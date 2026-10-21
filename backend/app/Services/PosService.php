<?php

namespace App\Services;

use App\Models\FolioEntry;
use App\Models\GuestFolio;
use App\Models\InventoryItem;
use App\Models\MenuItem;
use App\Models\MenuItemModifier;
use App\Models\MenuItemRecipe;
use App\Models\MenuItemVariant;
use App\Models\OperationalNotification;
use App\Models\PosOrder;
use App\Models\PosOrderHistory;
use App\Models\PosOrderItem;
use App\Models\PosOutlet;
use App\Models\PosPayment;
use App\Models\Reservation;
use App\Models\StockMovement;
use App\Models\StorageLocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Transactional POS, Restaurant, Bar, Room-Service, Recipe Consumption, and Folio Room-Charge Service.
 *
 * State Machine:
 * - Dine-in / Bar / Takeaway: `draft -> submitted -> preparing -> ready -> served`
 * - Room Service: `draft -> submitted -> preparing -> ready -> out_for_delivery -> delivered`
 * - Cancellation / Void: `cancelled` (before preparation) or `voided` (with mandatory `void_reason` & `pos.void` permission).
 *
 * Financial & Stock Integrity:
 * - All monetary values are calculated in integer minor units (`*_minor`, 1 NGN = 100 kobo) on the server.
 * - Menu item prices, variant prices, modifier prices, tax percentage, and service charge percentage are snapshotted
 *   on `pos_order_items` and `pos_orders` so subsequent menu edits never alter historical orders.
 * - Stock consumption (`none` | `direct_item` | `recipe`) occurs at the configured single consumption point
 *   (`operations.pos_stock_consumption_trigger`, default `'submitted'`) and is guarded by both `pos_orders.stock_consumed`
 *   and `stock_movements.unique_source_key` so retries or status transitions never deduct ingredients twice.
 * - Room-service orders and POS room charges resolve the reservation and open `GuestFolio` on the server, verify that
 *   the reservation is `checked_in` (and that any client-supplied `room_id` matches `reservation.assigned_room_id`),
 *   and post idempotently via `FolioService::postTrustedExternalCharge()`.
 */
class PosService
{
    public const ALLOWED_TRANSITIONS = [
        PosOrder::STATUS_DRAFT => [
            PosOrder::STATUS_SUBMITTED,
            PosOrder::STATUS_CANCELLED,
            PosOrder::STATUS_VOIDED,
        ],
        PosOrder::STATUS_SUBMITTED => [
            PosOrder::STATUS_PREPARING,
            PosOrder::STATUS_READY,
            PosOrder::STATUS_SERVED,
            PosOrder::STATUS_DELIVERED,
            PosOrder::STATUS_CANCELLED,
            PosOrder::STATUS_VOIDED,
        ],
        PosOrder::STATUS_PREPARING => [
            PosOrder::STATUS_READY,
            PosOrder::STATUS_SERVED,
            PosOrder::STATUS_OUT_FOR_DELIVERY,
            PosOrder::STATUS_DELIVERED,
            PosOrder::STATUS_VOIDED,
        ],
        PosOrder::STATUS_READY => [
            PosOrder::STATUS_OUT_FOR_DELIVERY,
            PosOrder::STATUS_SERVED,
            PosOrder::STATUS_DELIVERED,
            PosOrder::STATUS_VOIDED,
        ],
        PosOrder::STATUS_OUT_FOR_DELIVERY => [
            PosOrder::STATUS_DELIVERED,
            PosOrder::STATUS_VOIDED,
        ],
        PosOrder::STATUS_SERVED => [
            PosOrder::STATUS_VOIDED,
        ],
        PosOrder::STATUS_DELIVERED => [
            PosOrder::STATUS_VOIDED,
        ],
        PosOrder::STATUS_CANCELLED => [],
        PosOrder::STATUS_VOIDED => [],
    ];

    public function __construct(
        private InventoryService $inventory,
        private FolioService $folios,
        private SettingsService $settings,
        private AuditLogger $audit,
    ) {}

    /**
     * Create a POS or Room-Service order (either as `draft` or immediately `submitted`).
     */
    public function createOrder(array $data, User $actor): PosOrder
    {
        $idempotencyKey = isset($data['idempotency_key']) && filled($data['idempotency_key'])
            ? trim((string) $data['idempotency_key'])
            : null;

        if ($idempotencyKey) {
            $existing = PosOrder::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ($existing->pos_outlet_id !== (int) $data['pos_outlet_id']) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['Idempotency key has already been used for another outlet order.'],
                    ]);
                }

                return $existing->load(['outlet', 'items', 'reservation', 'guestFolio', 'room', 'payments', 'histories.actor']);
            }
        }

        return DB::transaction(function () use ($data, $actor, $idempotencyKey) {
            /** @var PosOutlet $outlet */
            $outlet = PosOutlet::where('id', (int) $data['pos_outlet_id'])->lockForUpdate()->firstOrFail();

            if ($idempotencyKey) {
                $existingLocked = PosOrder::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existingLocked) {
                    if ($existingLocked->pos_outlet_id !== $outlet->id) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => ['Idempotency key has already been used for another outlet order.'],
                        ]);
                    }

                    return $existingLocked->load(['outlet', 'items', 'reservation', 'guestFolio', 'room', 'payments', 'histories.actor']);
                }
            }

            if (! $outlet->is_active || ! $outlet->accepts_orders) {
                throw ValidationException::withMessages([
                    'pos_outlet_id' => ["Outlet [{$outlet->name}] is currently closed or not accepting orders."],
                ]);
            }

            $orderType = (string) ($data['order_type'] ?? ($outlet->type === 'room_service' ? 'room_service' : 'dine_in'));
            if ($orderType === 'room_service' && ! $outlet->supports_room_service && $outlet->type !== 'room_service') {
                throw ValidationException::withMessages([
                    'order_type' => ["Outlet [{$outlet->name}] is not configured for room-service orders."],
                ]);
            }

            if (empty($data['items']) || ! is_array($data['items'])) {
                throw ValidationException::withMessages([
                    'items' => ['At least one menu item is required to create an order.'],
                ]);
            }

            // Validate & resolve checked-in reservation if room_service or reservation_id provided
            $reservation = null;
            $folio = null;
            $roomId = null;
            $guestNameSnapshot = isset($data['guest_name']) ? strip_tags((string) $data['guest_name']) : null;

            if ($orderType === 'room_service' || ! empty($data['reservation_id'])) {
                if (empty($data['reservation_id'])) {
                    throw ValidationException::withMessages([
                        'reservation_id' => ['Room-service orders require a valid checked-in reservation.'],
                    ]);
                }

                $resolved = $this->resolveAndValidateInHouseStay(
                    reservationId: (int) $data['reservation_id'],
                    submittedRoomId: ! empty($data['room_id']) ? (int) $data['room_id'] : null,
                    submittedGuestFolioId: ! empty($data['guest_folio_id']) ? (int) $data['guest_folio_id'] : null,
                    actor: $actor,
                );
                $reservation = $resolved['reservation'];
                $folio = $resolved['folio'];
                $roomId = $reservation->assigned_room_id;
                $guestNameSnapshot = $reservation->guest_name;
            }

            $allSettings = $this->settings->all();
            $currency = $allSettings['hotel']['currency'] ?? 'NGN';
            $submitImmediately = (bool) ($data['submit'] ?? true);

            $order = PosOrder::create([
                'order_reference' => $this->generateUniqueOrderReference(),
                'idempotency_key' => $idempotencyKey,
                'pos_outlet_id' => $outlet->id,
                'order_type' => $orderType,
                'table_or_location' => isset($data['table_or_location'])
                    ? strip_tags((string) $data['table_or_location'])
                    : ($reservation && $reservation->assignedRoom ? "Room {$reservation->assignedRoom->room_number}" : null),
                'covers' => max(1, (int) ($data['covers'] ?? 1)),
                'reservation_id' => $reservation?->id,
                'guest_folio_id' => $folio?->id,
                'room_id' => $roomId,
                'guest_name_snapshot' => $guestNameSnapshot,
                'delivery_instructions' => isset($data['delivery_instructions']) ? strip_tags((string) $data['delivery_instructions']) : null,
                'status' => PosOrder::STATUS_DRAFT,
                'payment_status' => PosOrder::PAYMENT_UNPAID,
                'currency_code' => $currency,
                'special_instructions' => isset($data['special_instructions']) ? strip_tags((string) $data['special_instructions']) : null,
                'created_by' => $actor->id,
            ]);

            $this->replaceDraftOrderLinesAndRecalculateLocked($order, $outlet, $data['items'], $data, $actor);

            PosOrderHistory::create([
                'pos_order_id' => $order->id,
                'from_status' => null,
                'to_status' => PosOrder::STATUS_DRAFT,
                'from_payment_status' => null,
                'to_payment_status' => PosOrder::PAYMENT_UNPAID,
                'notes' => 'POS order created',
                'changed_by' => $actor->id,
                'created_at' => now(),
            ]);

            if ($submitImmediately) {
                $order = $this->transitionOrderStatusLocked(
                    order: $order,
                    outlet: $outlet,
                    targetStatus: PosOrder::STATUS_SUBMITTED,
                    actor: $actor,
                    notes: 'Order submitted to preparation',
                    allowNegativeStockOverride: ! empty($data['allow_negative_stock_override']),
                    negativeStockReason: $data['negative_stock_reason'] ?? null,
                );
            }

            if (! empty($data['charge_to_room'])) {
                if ($order->status === PosOrder::STATUS_DRAFT) {
                    throw ValidationException::withMessages([
                        'charge_to_room' => ['Draft orders must be submitted before posting a room charge.'],
                    ]);
                }
                $order = $this->postOrderChargeToRoomFolioLocked($order, $actor);
            }

            $this->audit->log('pos.order_created', $order, [
                'order_reference' => $order->order_reference,
                'outlet' => $outlet->code,
                'order_type' => $order->order_type,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'total_minor' => $order->total_minor,
            ], 'success', $actor->id);

            return $order->fresh(['outlet', 'items', 'reservation', 'guestFolio', 'room', 'payments', 'histories.actor']);
        }, 3);
    }

    /**
     * Update quantities, lines, or discounts on a `draft` order BEFORE submission.
     * Submitted or settled orders can never be silently edited.
     */
    public function updateDraftOrder(PosOrder $order, array $data, User $actor): PosOrder
    {
        return DB::transaction(function () use ($order, $data, $actor) {
            /** @var PosOrder $locked */
            $locked = PosOrder::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== PosOrder::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => ["Order {$locked->order_reference} has already been submitted ({$locked->status}) and cannot be edited. Use an authorized void or discount workflow."],
                ]);
            }

            /** @var PosOutlet $outlet */
            $outlet = PosOutlet::where('id', $locked->pos_outlet_id)->lockForUpdate()->firstOrFail();

            if (isset($data['table_or_location'])) {
                $locked->table_or_location = strip_tags((string) $data['table_or_location']);
            }
            if (isset($data['covers'])) {
                $locked->covers = max(1, (int) $data['covers']);
            }
            if (array_key_exists('special_instructions', $data)) {
                $locked->special_instructions = $data['special_instructions'] ? strip_tags((string) $data['special_instructions']) : null;
            }
            if (array_key_exists('delivery_instructions', $data)) {
                $locked->delivery_instructions = $data['delivery_instructions'] ? strip_tags((string) $data['delivery_instructions']) : null;
            }
            $locked->save();

            $itemsPayload = $data['items'] ?? null;
            if ($itemsPayload !== null) {
                if (! is_array($itemsPayload) || empty($itemsPayload)) {
                    throw ValidationException::withMessages(['items' => ['Order must contain at least one menu item.']]);
                }
                $this->replaceDraftOrderLinesAndRecalculateLocked($locked, $outlet, $itemsPayload, $data, $actor);
            } else {
                $this->recalculateOrderTotalsLocked($locked, $outlet, $data, $actor);
            }

            return $locked->fresh(['outlet', 'items', 'reservation', 'guestFolio', 'room', 'payments', 'histories.actor']);
        }, 3);
    }

    /**
     * Apply an authorized discount to an unpaid (`draft`, `submitted`, `preparing`, `ready`, `served`) order.
     * Requires `pos.discount`.
     */
    public function applyDiscount(PosOrder $order, array $data, User $actor): PosOrder
    {
        return DB::transaction(function () use ($order, $data, $actor) {
            if (! $actor->hasPermission('pos.discount')) {
                abort(403, 'You do not have permission to apply POS discounts.');
            }

            /** @var PosOrder $locked */
            $locked = PosOrder::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [PosOrder::STATUS_CANCELLED, PosOrder::STATUS_VOIDED], true)) {
                throw ValidationException::withMessages(['status' => ['Cannot apply a discount to a cancelled or voided order.']]);
            }

            if (in_array($locked->payment_status, [PosOrder::PAYMENT_PAID, PosOrder::PAYMENT_CHARGED_TO_ROOM], true)) {
                throw ValidationException::withMessages([
                    'payment_status' => ["Cannot modify discount on order {$locked->order_reference} after it has been settled ({$locked->payment_status})."],
                ]);
            }

            if (empty($data['discount_reason']) || strlen(trim((string) $data['discount_reason'])) < 3) {
                throw ValidationException::withMessages([
                    'discount_reason' => ['A reason is required when applying a POS discount.'],
                ]);
            }

            /** @var PosOutlet $outlet */
            $outlet = PosOutlet::findOrFail($locked->pos_outlet_id);
            $this->recalculateOrderTotalsLocked($locked, $outlet, $data, $actor);

            PosOrderHistory::create([
                'pos_order_id' => $locked->id,
                'from_status' => $locked->status,
                'to_status' => $locked->status,
                'from_payment_status' => $locked->payment_status,
                'to_payment_status' => $locked->payment_status,
                'notes' => "Discount applied ({$locked->discount_type}: {$locked->discount_value}) — {$locked->discount_reason}",
                'metadata' => ['discount_minor' => $locked->discount_minor],
                'changed_by' => $actor->id,
                'created_at' => now(),
            ]);

            $this->audit->log('pos.discount_applied', $locked, [
                'order_reference' => $locked->order_reference,
                'discount_type' => $locked->discount_type,
                'discount_minor' => $locked->discount_minor,
                'reason' => $locked->discount_reason,
            ], 'success', $actor->id);

            return $locked->fresh(['outlet', 'items', 'reservation', 'guestFolio', 'room', 'payments', 'histories.actor']);
        }, 3);
    }

    /**
     * Transition POS or Room-Service order state (`submitted`, `preparing`, `ready`, `out_for_delivery`, `served`, `delivered`).
     */
    public function transitionStatus(PosOrder $order, string $targetStatus, User $actor, ?string $notes = null, array $options = []): PosOrder
    {
        return DB::transaction(function () use ($order, $targetStatus, $actor, $notes, $options) {
            /** @var PosOrder $locked */
            $locked = PosOrder::where('id', $order->id)->lockForUpdate()->firstOrFail();
            /** @var PosOutlet $outlet */
            $outlet = PosOutlet::where('id', $locked->pos_outlet_id)->lockForUpdate()->firstOrFail();

            $updated = $this->transitionOrderStatusLocked(
                order: $locked,
                outlet: $outlet,
                targetStatus: $targetStatus,
                actor: $actor,
                notes: $notes,
                allowNegativeStockOverride: ! empty($options['allow_negative_stock_override']),
                negativeStockReason: $options['negative_stock_reason'] ?? null,
            );

            return $updated->fresh(['outlet', 'items', 'reservation', 'guestFolio', 'room', 'payments', 'histories.actor']);
        }, 3);
    }

    /**
     * Void or cancel a POS / Room-Service order (`pos.void`).
     *
     * Rules:
     * - Requires `pos.void` for any submitted/prepared/served order.
     * - Requires a mandatory `reason`.
     * - If the order was already charged to a guest folio (`folio_entry_id`), automatically reverses the folio entry
     *   via `FolioService::reversePostedEntry()` so the financial audit trail remains append-only and balanced.
     * - If the order has direct completed `PosPayment` rows that have not been refunded, rejects voiding until those
     *   payments are refunded/reversed OR automatically reverses them when `reverse_payments = true` and the actor holds `pos.refund`.
     * - If ingredient stock was already consumed (`stock_consumed = true`), supports explicit `stock_action = 'restock'`
     *   (creating an auditable `restock_return` movement for unopened/unprepared items) or `stock_action = 'waste'`
     *   (keeping the deduction and logging waste), never silently rewriting historical stock movements.
     */
    public function voidOrCancelOrder(PosOrder $order, array $data, User $actor): PosOrder
    {
        return DB::transaction(function () use ($order, $data, $actor) {
            /** @var PosOrder $locked */
            $locked = PosOrder::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if (in_array($locked->status, [PosOrder::STATUS_CANCELLED, PosOrder::STATUS_VOIDED], true)) {
                throw ValidationException::withMessages([
                    'status' => ["Order {$locked->order_reference} is already {$locked->status}."],
                ]);
            }

            if ($locked->status !== PosOrder::STATUS_DRAFT && ! $actor->hasPermission('pos.void')) {
                abort(403, 'You do not have permission to void or cancel submitted POS orders.');
            }

            $reason = trim(strip_tags((string) ($data['reason'] ?? '')));
            if (strlen($reason) < 3) {
                throw ValidationException::withMessages([
                    'reason' => ['A clear reason is required when voiding or cancelling an order.'],
                ]);
            }

            // Check direct POS payments
            $netDirectPaidMinor = (int) PosPayment::where('pos_order_id', $locked->id)
                ->where('status', 'completed')
                ->lockForUpdate()
                ->sum('amount_minor');

            if ($netDirectPaidMinor > 0) {
                if (! empty($data['reverse_payments']) && $actor->hasPermission('pos.refund')) {
                    $activePayments = PosPayment::where('pos_order_id', $locked->id)
                        ->where('type', 'payment')
                        ->where('status', 'completed')
                        ->lockForUpdate()
                        ->get();
                    foreach ($activePayments as $pay) {
                        if (! PosPayment::where('reversed_payment_id', $pay->id)->exists()) {
                            PosPayment::create([
                                'payment_reference' => $this->generateUniquePaymentReference(),
                                'pos_order_id' => $locked->id,
                                'pos_outlet_id' => $locked->pos_outlet_id,
                                'type' => 'reversal',
                                'method' => $pay->method,
                                'currency_code' => $pay->currency_code,
                                'amount_minor' => -$pay->amount_minor,
                                'amount' => -AvailabilityService::toMajor($pay->amount_minor),
                                'status' => 'completed',
                                'reversed_payment_id' => $pay->id,
                                'notes' => "Reversed due to order void: {$reason}",
                                'recorded_by' => $actor->id,
                                'approved_by' => $actor->id,
                                'paid_at' => now(),
                            ]);
                        }
                    }
                } else {
                    throw ValidationException::withMessages([
                        'payment_status' => ["Order {$locked->order_reference} has recorded payments. Refund/reverse the payments first (or pass reverse_payments with pos.refund permission)."],
                    ]);
                }
            }

            // If charged to a room folio, reverse the posted FolioEntry via FolioService::reversePostedEntry
            if ($locked->folio_entry_id) {
                $folioEntry = FolioEntry::find($locked->folio_entry_id);
                if ($folioEntry && ! FolioEntry::where('reversed_entry_id', $folioEntry->id)->exists()) {
                    $this->folios->reversePostedEntry($folioEntry, "POS Order {$locked->order_reference} voided: {$reason}", $actor);
                }
            }

            // Handle stock if already consumed (`restock` vs `waste`)
            $stockAction = (string) ($data['stock_action'] ?? 'waste'); // restock | waste
            if ($locked->stock_consumed && $stockAction === 'restock') {
                $this->restockConsumedOrderIngredientsLocked($locked, $actor, $reason);
            }

            $oldStatus = $locked->status;
            $oldPayStatus = $locked->payment_status;
            $targetStatus = $oldStatus === PosOrder::STATUS_DRAFT ? PosOrder::STATUS_CANCELLED : PosOrder::STATUS_VOIDED;

            $locked->update([
                'status' => $targetStatus,
                'payment_status' => PosOrder::PAYMENT_VOIDED,
                'amount_paid_minor' => 0,
                'amount_paid' => 0.00,
                'balance_due_minor' => 0,
                'balance_due_amount' => 0.00,
                'void_reason' => $reason,
                'voided_by' => $actor->id,
                'voided_at' => now(),
            ]);

            $locked->items()->update(['prep_status' => 'cancelled']);

            PosOrderHistory::create([
                'pos_order_id' => $locked->id,
                'from_status' => $oldStatus,
                'to_status' => $targetStatus,
                'from_payment_status' => $oldPayStatus,
                'to_payment_status' => PosOrder::PAYMENT_VOIDED,
                'notes' => "Order {$targetStatus}: {$reason} (stock_action: {$stockAction})",
                'changed_by' => $actor->id,
                'created_at' => now(),
            ]);

            $this->audit->log('pos.order_voided', $locked, [
                'order_reference' => $locked->order_reference,
                'from_status' => $oldStatus,
                'to_status' => $targetStatus,
                'reason' => $reason,
                'stock_action' => $stockAction,
            ], 'success', $actor->id);

            return $locked->fresh(['outlet', 'items', 'reservation', 'guestFolio', 'room', 'payments', 'histories.actor']);
        }, 3);
    }

    /**
     * Record a direct POS payment (`cash`, `pos_card`, `bank_transfer`) against a submitted POS order.
     * Prevents duplicate payments via `idempotency_key` and prevents overpaying beyond `balance_due_minor`.
     */
    public function recordPayment(PosOrder $order, array $data, User $actor): PosPayment
    {
        $idempotencyKey = isset($data['idempotency_key']) && filled($data['idempotency_key'])
            ? trim((string) $data['idempotency_key'])
            : null;

        if ($idempotencyKey) {
            $existing = PosPayment::where('idempotency_key', $idempotencyKey)->first();
            if ($existing) {
                if ($existing->pos_order_id !== $order->id) {
                    throw ValidationException::withMessages([
                        'idempotency_key' => ['Idempotency key has already been used for another POS order payment.'],
                    ]);
                }

                return $existing->load(['order', 'recorder']);
            }
        }

        return DB::transaction(function () use ($order, $data, $actor, $idempotencyKey) {
            /** @var PosOrder $locked */
            $locked = PosOrder::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if ($idempotencyKey) {
                $existingLocked = PosPayment::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existingLocked) {
                    if ($existingLocked->pos_order_id !== $locked->id) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => ['Idempotency key has already been used for another POS order payment.'],
                        ]);
                    }

                    return $existingLocked->load(['order', 'recorder']);
                }
            }

            if ($locked->status === PosOrder::STATUS_DRAFT) {
                throw ValidationException::withMessages([
                    'status' => ['Submit the draft order before recording payment.'],
                ]);
            }

            if (in_array($locked->status, [PosOrder::STATUS_CANCELLED, PosOrder::STATUS_VOIDED], true)) {
                throw ValidationException::withMessages([
                    'status' => ["Cannot record payment on a {$locked->status} order."],
                ]);
            }

            if (in_array($locked->payment_status, [PosOrder::PAYMENT_PAID, PosOrder::PAYMENT_CHARGED_TO_ROOM], true) || $locked->balance_due_minor <= 0) {
                throw ValidationException::withMessages([
                    'payment_status' => ["Order {$locked->order_reference} is already settled ({$locked->payment_status})."],
                ]);
            }

            $amountMinor = isset($data['amount_minor'])
                ? (int) $data['amount_minor']
                : (isset($data['amount']) ? AvailabilityService::toMinor($data['amount']) : $locked->balance_due_minor);

            if ($amountMinor <= 0) {
                throw ValidationException::withMessages(['amount' => ['Payment amount must be greater than zero.']]);
            }

            if ($amountMinor > $locked->balance_due_minor) {
                throw ValidationException::withMessages([
                    'amount' => ["Payment amount ({$amountMinor} minor) exceeds order balance due ({$locked->balance_due_minor} minor)."],
                ]);
            }

            $method = (string) ($data['method'] ?? 'cash');
            if (! in_array($method, ['cash', 'pos_card', 'bank_transfer'], true)) {
                throw ValidationException::withMessages(['method' => ['Payment method must be cash, pos_card, or bank_transfer.']]);
            }

            $payment = PosPayment::create([
                'payment_reference' => $this->generateUniquePaymentReference(),
                'idempotency_key' => $idempotencyKey,
                'pos_order_id' => $locked->id,
                'pos_outlet_id' => $locked->pos_outlet_id,
                'type' => 'payment',
                'method' => $method,
                'currency_code' => $locked->currency_code,
                'amount_minor' => $amountMinor,
                'amount' => AvailabilityService::toMajor($amountMinor),
                'status' => 'completed',
                'receipt_number' => $data['receipt_number'] ?? ('RCP-POS-'.now()->format('Ymd').'-'.strtoupper(Str::random(5))),
                'external_reference' => isset($data['external_reference']) ? strip_tags((string) $data['external_reference']) : null,
                'notes' => isset($data['notes']) ? strip_tags((string) $data['notes']) : null,
                'recorded_by' => $actor->id,
                'paid_at' => now(),
            ]);

            $this->syncOrderPaymentTotalsLocked($locked, $actor);

            $this->audit->log('pos.payment_recorded', $payment, [
                'order_reference' => $locked->order_reference,
                'payment_reference' => $payment->payment_reference,
                'method' => $method,
                'amount_minor' => $amountMinor,
                'payment_status' => $locked->payment_status,
            ], 'success', $actor->id);

            return $payment->load(['order', 'recorder']);
        }, 3);
    }

    /**
     * Refund or reverse a recorded POS payment (`pos.refund`).
     */
    public function refundOrReversePayment(PosPayment $payment, string $reason, User $actor): PosPayment
    {
        return DB::transaction(function () use ($payment, $reason, $actor) {
            if (! $actor->hasPermission('pos.refund')) {
                abort(403, 'You do not have permission to refund or reverse POS payments.');
            }

            /** @var PosOrder $order */
            $order = PosOrder::where('id', $payment->pos_order_id)->lockForUpdate()->firstOrFail();
            /** @var PosPayment $lockedPay */
            $lockedPay = PosPayment::where('id', $payment->id)->lockForUpdate()->firstOrFail();

            if ($lockedPay->type !== 'payment') {
                throw ValidationException::withMessages(['payment' => ['Only original payment rows can be refunded or reversed.']]);
            }

            if (PosPayment::where('reversed_payment_id', $lockedPay->id)->exists()) {
                throw ValidationException::withMessages(['payment' => ["Payment {$lockedPay->payment_reference} has already been refunded/reversed."]]);
            }

            if (strlen(trim($reason)) < 3) {
                throw ValidationException::withMessages(['reason' => ['A reason is required to refund or reverse a POS payment.']]);
            }

            $reversal = PosPayment::create([
                'payment_reference' => $this->generateUniquePaymentReference(),
                'pos_order_id' => $order->id,
                'pos_outlet_id' => $order->pos_outlet_id,
                'type' => 'refund',
                'method' => $lockedPay->method,
                'currency_code' => $lockedPay->currency_code,
                'amount_minor' => -$lockedPay->amount_minor,
                'amount' => -AvailabilityService::toMajor($lockedPay->amount_minor),
                'status' => 'completed',
                'reversed_payment_id' => $lockedPay->id,
                'notes' => strip_tags($reason),
                'recorded_by' => $actor->id,
                'approved_by' => $actor->id,
                'paid_at' => now(),
            ]);

            $this->syncOrderPaymentTotalsLocked($order, $actor);

            $this->audit->log('pos.payment_refunded', $reversal, [
                'order_reference' => $order->order_reference,
                'original_payment_reference' => $lockedPay->payment_reference,
                'refund_reference' => $reversal->payment_reference,
                'amount_minor' => $reversal->amount_minor,
                'reason' => strip_tags($reason),
            ], 'success', $actor->id);

            return $reversal->load(['order', 'recorder', 'reversedPayment']);
        }, 3);
    }

    /**
     * Post an unpaid POS or Room-Service order's balance as an authorized room charge to the guest's open `GuestFolio`.
     * Uses `FolioService::postTrustedExternalCharge()` and never trusts client-supplied guest/folio mismatches.
     */
    public function postOrderChargeToRoomFolio(PosOrder $order, array $data, User $actor): PosOrder
    {
        return DB::transaction(function () use ($order, $data, $actor) {
            if (! $actor->hasPermission('pos.room_charge.post')) {
                abort(403, 'You do not have permission to post POS / room-service charges to guest folios.');
            }

            /** @var PosOrder $locked */
            $locked = PosOrder::where('id', $order->id)->lockForUpdate()->firstOrFail();

            if (! empty($data['reservation_id']) && $locked->reservation_id !== (int) $data['reservation_id']) {
                // Allow linking an in-house reservation to a dine-in/bar order at bill settlement time
                $resolved = $this->resolveAndValidateInHouseStay(
                    reservationId: (int) $data['reservation_id'],
                    submittedRoomId: ! empty($data['room_id']) ? (int) $data['room_id'] : null,
                    submittedGuestFolioId: ! empty($data['guest_folio_id']) ? (int) $data['guest_folio_id'] : null,
                    actor: $actor,
                );
                $locked->update([
                    'reservation_id' => $resolved['reservation']->id,
                    'guest_folio_id' => $resolved['folio']->id,
                    'room_id' => $resolved['reservation']->assigned_room_id,
                    'guest_name_snapshot' => $resolved['reservation']->guest_name,
                ]);
            } elseif (! empty($data['room_id']) || ! empty($data['guest_folio_id'])) {
                // Verify submitted room_id / guest_folio_id matches the order's linked reservation
                $this->resolveAndValidateInHouseStay(
                    reservationId: (int) $locked->reservation_id,
                    submittedRoomId: ! empty($data['room_id']) ? (int) $data['room_id'] : null,
                    submittedGuestFolioId: ! empty($data['guest_folio_id']) ? (int) $data['guest_folio_id'] : null,
                    actor: $actor,
                );
            }

            $updated = $this->postOrderChargeToRoomFolioLocked($locked, $actor);

            return $updated->fresh(['outlet', 'items', 'reservation', 'guestFolio', 'folioEntry', 'room', 'payments', 'histories.actor']);
        }, 3);
    }

    /**
     * Internal locked room-charge posting using `FolioService::postTrustedExternalCharge()`.
     */
    private function postOrderChargeToRoomFolioLocked(PosOrder $order, User $actor): PosOrder
    {
        if (! $actor->hasPermission('pos.room_charge.post')) {
            abort(403, 'You do not have permission to post POS / room-service charges to guest folios.');
        }

        if ($order->status === PosOrder::STATUS_DRAFT) {
            throw ValidationException::withMessages([
                'status' => ['Submit the order before charging it to a guest folio.'],
            ]);
        }

        if (in_array($order->status, [PosOrder::STATUS_CANCELLED, PosOrder::STATUS_VOIDED], true)) {
            throw ValidationException::withMessages([
                'status' => ["Cannot post a {$order->status} order to a guest folio."],
            ]);
        }

        // Idempotent return if this order is already charged to the room folio and not reversed
        if ($order->payment_status === PosOrder::PAYMENT_CHARGED_TO_ROOM && $order->folio_entry_id) {
            $existingEntry = FolioEntry::find($order->folio_entry_id);
            if ($existingEntry && ! FolioEntry::where('reversed_entry_id', $existingEntry->id)->exists()) {
                return $order;
            }
        }

        if ($order->payment_status === PosOrder::PAYMENT_PAID || $order->balance_due_minor <= 0) {
            throw ValidationException::withMessages([
                'payment_status' => ["Order {$order->order_reference} has already been paid ({$order->payment_status})."],
            ]);
        }

        if ($order->amount_paid_minor > 0) {
            throw ValidationException::withMessages([
                'payment_status' => ["Order {$order->order_reference} has partial direct payments recorded; settle the remaining balance directly or reverse the partial payment before charging the full order to the room."],
            ]);
        }

        if (! $order->reservation_id) {
            throw ValidationException::withMessages([
                'reservation_id' => ['Select a valid checked-in reservation to charge this order to a guest folio.'],
            ]);
        }

        $roomServiceEnabled = (bool) ($this->settings->all()['operations']['room_service_charge_enabled'] ?? true);
        if (! $roomServiceEnabled) {
            throw ValidationException::withMessages([
                'room_charge' => ['Room-charge posting is currently disabled by hotel policy. Record immediate POS payment instead.'],
            ]);
        }

        /** @var PosOutlet $outlet */
        $outlet = PosOutlet::findOrFail($order->pos_outlet_id);
        if (! $outlet->supports_room_charge) {
            throw ValidationException::withMessages([
                'pos_outlet_id' => ["Outlet [{$outlet->name}] does not permit room-charge billing."],
            ]);
        }

        $resolved = $this->resolveAndValidateInHouseStay(
            reservationId: $order->reservation_id,
            submittedRoomId: $order->room_id,
            submittedGuestFolioId: $order->guest_folio_id,
            actor: $actor,
        );
        $reservation = $resolved['reservation'];
        $folio = $resolved['folio'];

        $netMinor = max(0, $order->subtotal_minor - $order->discount_minor);
        $lineSummary = $order->items()->pluck('item_name_snapshot')->take(3)->implode(', ');

        try {
            $folioEntry = $this->folios->postTrustedExternalCharge(
                reservation: $reservation,
                sourceType: 'pos_order',
                sourceId: (string) $order->id,
                chargeData: [
                    'idempotency_key' => "pos_order_room_charge:{$order->id}",
                    'category' => $order->order_type === 'room_service' ? 'room_service' : 'pos_restaurant',
                    'description' => ($order->order_type === 'room_service' ? 'Room Service' : $outlet->name)." #{$order->order_reference}".($lineSummary ? " ({$lineSummary})" : ''),
                    'service_date' => now()->format('Y-m-d'),
                    'quantity' => 1,
                    'net_amount_minor' => $netMinor,
                    'tax_amount_minor' => $order->tax_minor,
                    'service_charge_minor' => $order->service_charge_minor,
                    'metadata' => [
                        'order_reference' => $order->order_reference,
                        'outlet_code' => $outlet->code,
                        'order_type' => $order->order_type,
                    ],
                ],
                actor: $actor,
            );
        } catch (ValidationException $e) {
            OperationalNotification::create([
                'type' => 'pos.room_charge_failed',
                'severity' => 'warning',
                'title' => "Failed Room-Charge Posting: {$order->order_reference}",
                'message' => "Could not post {$order->order_reference} to reservation {$reservation->reference}: ".$e->getMessage(),
                'target_permission' => 'pos.view',
                'subject_type' => PosOrder::class,
                'subject_id' => $order->id,
            ]);
            throw $e;
        }

        $oldPayStatus = $order->payment_status;
        $order->update([
            'guest_folio_id' => $folio->id,
            'folio_entry_id' => $folioEntry->id,
            'payment_status' => PosOrder::PAYMENT_CHARGED_TO_ROOM,
            'settlement_method' => 'room_charge',
            'amount_paid_minor' => $order->total_minor,
            'amount_paid' => $order->total_amount,
            'balance_due_minor' => 0,
            'balance_due_amount' => 0.00,
            'room_charged_at' => now(),
        ]);

        PosOrderHistory::create([
            'pos_order_id' => $order->id,
            'from_status' => $order->status,
            'to_status' => $order->status,
            'from_payment_status' => $oldPayStatus,
            'to_payment_status' => PosOrder::PAYMENT_CHARGED_TO_ROOM,
            'notes' => "Charged to guest folio {$folio->folio_reference} (Entry {$folioEntry->entry_reference})",
            'metadata' => [
                'folio_id' => $folio->id,
                'folio_reference' => $folio->folio_reference,
                'folio_entry_id' => $folioEntry->id,
                'folio_entry_reference' => $folioEntry->entry_reference,
            ],
            'changed_by' => $actor->id,
            'created_at' => now(),
        ]);

        return $order;
    }

    /**
     * Validate that a reservation is genuinely `checked_in`, resolve its authoritative `GuestFolio`,
     * and reject any cross-room or cross-folio mismatch (preventing IDOR / charging an unrelated guest).
     *
     * @return array{reservation: Reservation, folio: GuestFolio}
     */
    private function resolveAndValidateInHouseStay(
        int $reservationId,
        ?int $submittedRoomId,
        ?int $submittedGuestFolioId,
        User $actor
    ): array {
        /** @var Reservation|null $reservation */
        $reservation = Reservation::with('assignedRoom')->where('id', $reservationId)->lockForUpdate()->first();
        if (! $reservation) {
            throw ValidationException::withMessages(['reservation_id' => ['Selected reservation was not found.']]);
        }

        if ($reservation->status !== Reservation::STATUS_CHECKED_IN) {
            throw ValidationException::withMessages([
                'reservation_id' => ["Reservation {$reservation->reference} is not checked in (current status: {$reservation->status}). Room-service and room charges are only permitted for checked-in stays."],
            ]);
        }

        if (! $reservation->assigned_room_id) {
            throw ValidationException::withMessages([
                'reservation_id' => ["Reservation {$reservation->reference} does not have an assigned physical room."],
            ]);
        }

        if ($submittedRoomId !== null && $submittedRoomId !== (int) $reservation->assigned_room_id) {
            throw ValidationException::withMessages([
                'room_id' => ["Room mismatch: reservation {$reservation->reference} is assigned to room ID {$reservation->assigned_room_id}, not room ID {$submittedRoomId}."],
            ]);
        }

        $folio = $this->folios->ensureFolioForReservation($reservation, $actor);

        if ($submittedGuestFolioId !== null && $submittedGuestFolioId !== (int) $folio->id) {
            throw ValidationException::withMessages([
                'guest_folio_id' => ['Cross-folio mismatch: the submitted guest folio does not belong to the selected checked-in reservation.'],
            ]);
        }

        if ($folio->status !== GuestFolio::STATUS_OPEN) {
            throw ValidationException::withMessages([
                'guest_folio_id' => ["Guest folio {$folio->folio_reference} for reservation {$reservation->reference} is [{$folio->status}] and cannot accept room charges. Collect immediate POS payment or ask a supervisor to reopen the folio."],
            ]);
        }

        return ['reservation' => $reservation, 'folio' => $folio];
    }

    /**
     * Build snapshotted `pos_order_items` for a draft order and recalculate order totals.
     */
    private function replaceDraftOrderLinesAndRecalculateLocked(
        PosOrder $order,
        PosOutlet $outlet,
        array $itemsPayload,
        array $orderData,
        User $actor
    ): void {
        PosOrderItem::where('pos_order_id', $order->id)->delete();

        foreach ($itemsPayload as $idx => $line) {
            $menuItemId = (int) ($line['menu_item_id'] ?? 0);
            /** @var MenuItem|null $menuItem */
            $menuItem = MenuItem::with(['prepStation', 'variants', 'modifiers'])->find($menuItemId);

            if (! $menuItem || ! $menuItem->is_active) {
                throw ValidationException::withMessages([
                    "items.{$idx}.menu_item_id" => ['Selected menu item does not exist or is inactive.'],
                ]);
            }

            if ($menuItem->pos_outlet_id !== null && $menuItem->pos_outlet_id !== $outlet->id) {
                throw ValidationException::withMessages([
                    "items.{$idx}.menu_item_id" => ["Menu item [{$menuItem->name}] does not belong to outlet [{$outlet->name}]."],
                ]);
            }

            if (! $menuItem->is_available || $menuItem->is_sold_out) {
                throw ValidationException::withMessages([
                    "items.{$idx}.menu_item_id" => ["Menu item [{$menuItem->name}] is currently sold out or unavailable."],
                ]);
            }

            if ($order->order_type === 'room_service' && ! $menuItem->is_room_service_eligible) {
                throw ValidationException::withMessages([
                    "items.{$idx}.menu_item_id" => ["Menu item [{$menuItem->name}] is not eligible for room service."],
                ]);
            }

            $qty = (int) ($line['quantity'] ?? 0);
            if ($qty < 1 || $qty > 500) {
                throw ValidationException::withMessages([
                    "items.{$idx}.quantity" => ["Quantity for [{$menuItem->name}] must be between 1 and 500."],
                ]);
            }

            // Variant resolution (server-side price snapshot)
            $variantId = ! empty($line['menu_item_variant_id']) ? (int) $line['menu_item_variant_id'] : null;
            $variantNameSnapshot = null;
            $unitPriceMinor = (int) $menuItem->price_minor;

            if ($variantId) {
                /** @var MenuItemVariant|null $variant */
                $variant = $menuItem->variants->firstWhere('id', $variantId);
                if (! $variant || ! $variant->is_active || $variant->is_sold_out) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.menu_item_variant_id" => ["Selected variant for [{$menuItem->name}] is invalid or sold out."],
                    ]);
                }
                $variantNameSnapshot = $variant->name;
                $unitPriceMinor = (int) $variant->price_minor;
            }

            // Modifiers resolution (server-side price snapshot)
            $modifierIds = array_values(array_unique(array_map('intval', (array) ($line['modifier_ids'] ?? []))));
            $modifiersSnapshot = [];
            $modifiersUnitMinor = 0;

            foreach ($modifierIds as $modId) {
                /** @var MenuItemModifier|null $mod */
                $mod = $menuItem->modifiers->firstWhere('id', $modId);
                if (! $mod || ! $mod->is_active) {
                    throw ValidationException::withMessages([
                        "items.{$idx}.modifier_ids" => ["Selected modifier #{$modId} for [{$menuItem->name}] is invalid or inactive."],
                    ]);
                }
                $modifiersUnitMinor += (int) $mod->price_minor;
                $modifiersSnapshot[] = [
                    'id' => $mod->id,
                    'name' => $mod->name,
                    'price_minor' => (int) $mod->price_minor,
                    'price' => AvailabilityService::toMajor((int) $mod->price_minor),
                ];
            }

            $effectiveUnitMinor = $unitPriceMinor + $modifiersUnitMinor;
            $lineSubtotalMinor = $effectiveUnitMinor * $qty;

            PosOrderItem::create([
                'pos_order_id' => $order->id,
                'menu_item_id' => $menuItem->id,
                'menu_item_variant_id' => $variantId,
                'item_name_snapshot' => $menuItem->name,
                'variant_name_snapshot' => $variantNameSnapshot,
                'prep_station_name_snapshot' => $menuItem->prepStation?->name,
                'quantity' => $qty,
                'currency_code' => $order->currency_code,
                'unit_price_minor' => $unitPriceMinor,
                'modifiers_unit_minor' => $modifiersUnitMinor,
                'line_subtotal_minor' => $lineSubtotalMinor,
                'unit_price' => AvailabilityService::toMajor($effectiveUnitMinor),
                'line_subtotal' => AvailabilityService::toMajor($lineSubtotalMinor),
                'modifiers_snapshot' => $modifiersSnapshot,
                'special_instructions' => isset($line['special_instructions']) ? strip_tags((string) $line['special_instructions']) : null,
                'prep_status' => 'pending',
            ]);
        }

        $this->recalculateOrderTotalsLocked($order, $outlet, $orderData, $actor);
    }

    /**
     * Recalculate subtotal, discount, tax, service charge, total, and balance due in integer minor units.
     */
    private function recalculateOrderTotalsLocked(PosOrder $order, PosOutlet $outlet, array $data, User $actor): void
    {
        $subtotalMinor = (int) PosOrderItem::where('pos_order_id', $order->id)->sum('line_subtotal_minor');

        $discountType = array_key_exists('discount_type', $data) ? $data['discount_type'] : $order->discount_type;
        $discountValue = array_key_exists('discount_value', $data) ? (float) $data['discount_value'] : (float) $order->discount_value;
        $discountReason = array_key_exists('discount_reason', $data)
            ? ($data['discount_reason'] ? strip_tags((string) $data['discount_reason']) : null)
            : $order->discount_reason;

        $discountMinor = 0;
        $discountApprovedBy = $order->discount_approved_by;

        if ($discountType && $discountValue > 0) {
            if (! $actor->hasPermission('pos.discount')) {
                abort(403, 'You do not have permission to apply POS discounts.');
            }
            if (! $discountReason || strlen(trim($discountReason)) < 3) {
                throw ValidationException::withMessages([
                    'discount_reason' => ['A reason is required when applying a POS discount.'],
                ]);
            }

            if ($discountType === 'percentage') {
                if ($discountValue > 100) {
                    throw ValidationException::withMessages(['discount_value' => ['Percentage discount cannot exceed 100%.']]);
                }
                $discountMinor = (int) round($subtotalMinor * ($discountValue / 100.0));
            } elseif ($discountType === 'fixed') {
                $discountMinor = AvailabilityService::toMinor($discountValue);
                if ($discountMinor > $subtotalMinor) {
                    throw ValidationException::withMessages(['discount_value' => ['Fixed discount cannot exceed the order subtotal.']]);
                }
            } else {
                throw ValidationException::withMessages(['discount_type' => ['Discount type must be percentage or fixed.']]);
            }
            $discountApprovedBy = $actor->id;
        } else {
            $discountType = null;
            $discountValue = 0.00;
            $discountReason = null;
        }

        $netAfterDiscountMinor = max(0, $subtotalMinor - $discountMinor);

        $opsSettings = $this->settings->all()['operations'] ?? [];
        $taxPercent = $outlet->tax_percentage !== null
            ? (float) $outlet->tax_percentage
            : (float) ($opsSettings['pos_default_tax_percentage'] ?? 0.0);
        $servicePercent = $outlet->service_charge_percentage !== null
            ? (float) $outlet->service_charge_percentage
            : (float) ($opsSettings['pos_default_service_charge_percentage'] ?? 0.0);

        $taxMinor = (int) round($netAfterDiscountMinor * ($taxPercent / 100.0));
        $serviceMinor = (int) round($netAfterDiscountMinor * ($servicePercent / 100.0));
        $totalMinor = $netAfterDiscountMinor + $taxMinor + $serviceMinor;

        $netPaidMinor = max(0, (int) PosPayment::where('pos_order_id', $order->id)->where('status', 'completed')->sum('amount_minor'));
        $balanceDueMinor = max(0, $totalMinor - $netPaidMinor);

        $order->update([
            'subtotal_minor' => $subtotalMinor,
            'discount_type' => $discountType,
            'discount_value' => $discountValue,
            'discount_minor' => $discountMinor,
            'discount_reason' => $discountReason,
            'discount_approved_by' => $discountApprovedBy,
            'tax_percentage_snapshot' => $taxPercent,
            'tax_minor' => $taxMinor,
            'service_charge_percentage_snapshot' => $servicePercent,
            'service_charge_minor' => $serviceMinor,
            'total_minor' => $totalMinor,
            'balance_due_minor' => $balanceDueMinor,
            'total_amount' => AvailabilityService::toMajor($totalMinor),
            'balance_due_amount' => AvailabilityService::toMajor($balanceDueMinor),
        ]);
    }

    /**
     * Transition order status and trigger recipe/direct stock consumption when the configured trigger state is reached.
     */
    private function transitionOrderStatusLocked(
        PosOrder $order,
        PosOutlet $outlet,
        string $targetStatus,
        User $actor,
        ?string $notes = null,
        bool $allowNegativeStockOverride = false,
        ?string $negativeStockReason = null
    ): PosOrder {
        $oldStatus = $order->status;
        if ($targetStatus === $oldStatus) {
            return $order;
        }

        $allowed = self::ALLOWED_TRANSITIONS[$oldStatus] ?? [];
        if (! in_array($targetStatus, $allowed, true)) {
            throw ValidationException::withMessages([
                'status' => ["Invalid POS order status transition from [{$oldStatus}] to [{$targetStatus}]."],
            ]);
        }

        if (in_array($targetStatus, [PosOrder::STATUS_OUT_FOR_DELIVERY, PosOrder::STATUS_DELIVERED], true)
            && ! $actor->hasAnyPermission(['pos.room_service.deliver', 'pos.prepare', 'pos.sell'])) {
            abort(403, 'You do not have permission to update room-service delivery status.');
        }

        $updates = ['status' => $targetStatus];
        if ($targetStatus === PosOrder::STATUS_SUBMITTED && ! $order->submitted_at) {
            $updates['submitted_by'] = $actor->id;
            $updates['submitted_at'] = now();
        }
        if ($targetStatus === PosOrder::STATUS_PREPARING && ! $order->preparing_at) {
            $updates['preparing_at'] = now();
        }
        if ($targetStatus === PosOrder::STATUS_READY && ! $order->ready_at) {
            $updates['ready_at'] = now();
        }
        if ($targetStatus === PosOrder::STATUS_OUT_FOR_DELIVERY && ! $order->out_for_delivery_at) {
            $updates['out_for_delivery_at'] = now();
        }
        if ($targetStatus === PosOrder::STATUS_SERVED && ! $order->served_at) {
            $updates['served_by'] = $actor->id;
            $updates['served_at'] = now();
        }
        if ($targetStatus === PosOrder::STATUS_DELIVERED && ! $order->delivered_at) {
            $updates['delivered_by'] = $actor->id;
            $updates['delivered_at'] = now();
        }

        $order->update($updates);

        // Update line prep_status
        $linePrepMap = [
            PosOrder::STATUS_PREPARING => 'preparing',
            PosOrder::STATUS_READY => 'ready',
            PosOrder::STATUS_SERVED => 'served',
            PosOrder::STATUS_DELIVERED => 'served',
        ];
        if (isset($linePrepMap[$targetStatus])) {
            $order->items()->update(['prep_status' => $linePrepMap[$targetStatus]]);
        }

        // Check if stock consumption should occur on this transition
        $triggerState = (string) ($this->settings->all()['operations']['pos_stock_consumption_trigger'] ?? PosOrder::STATUS_SUBMITTED);
        $triggerRank = [
            PosOrder::STATUS_SUBMITTED => 1,
            PosOrder::STATUS_PREPARING => 2,
            PosOrder::STATUS_READY => 3,
            PosOrder::STATUS_OUT_FOR_DELIVERY => 4,
            PosOrder::STATUS_SERVED => 5,
            PosOrder::STATUS_DELIVERED => 5,
        ];

        $currentRank = $triggerRank[$targetStatus] ?? 0;
        $requiredRank = $triggerRank[$triggerState] ?? 1;

        if (! $order->stock_consumed && $currentRank >= $requiredRank) {
            $this->consumeOrderStockLocked($order, $outlet, $actor, $allowNegativeStockOverride, $negativeStockReason);
        }

        PosOrderHistory::create([
            'pos_order_id' => $order->id,
            'from_status' => $oldStatus,
            'to_status' => $targetStatus,
            'from_payment_status' => $order->payment_status,
            'to_payment_status' => $order->payment_status,
            'notes' => $notes ? strip_tags($notes) : "Order transitioned to {$targetStatus}",
            'changed_by' => $actor->id,
            'created_at' => now(),
        ]);

        return $order;
    }

    /**
     * Deduct inventory stock for all lines on `$order` that use `stock_tracking_policy = 'direct_item'` or `'recipe'`.
     *
     * Idempotency & Concurrency Guarantees:
     * - Collects all required `InventoryItem` IDs across the order, sorts them in ascending ID order, and acquires
     *   `InventoryItem::whereIn(...)->orderBy('id')->lockForUpdate()` so concurrent POS sales never deadlock or oversell the final unit.
     * - Each deduction uses a deterministic `unique_source_key` (`pos_order:{order_id}:line:{line_id}:inv:{inv_id}`)
     *   on `stock_movements` AND marks `pos_orders.stock_consumed = true`.
     */
    private function consumeOrderStockLocked(
        PosOrder $order,
        PosOutlet $outlet,
        User $actor,
        bool $allowNegativeOverride = false,
        ?string $negativeStockReason = null
    ): void {
        if ($order->stock_consumed) {
            return;
        }

        $locationId = $outlet->default_storage_location_id
            ?: StorageLocation::where('is_default', true)->value('id')
            ?: StorageLocation::where('is_active', true)->orderBy('id')->value('id');

        $orderLines = PosOrderItem::where('pos_order_id', $order->id)->orderBy('id')->get();
        $menuItemIds = $orderLines->pluck('menu_item_id')->unique()->all();
        $menuItems = MenuItem::with(['variants', 'recipes' => fn ($q) => $q->where('is_active', true)])
            ->whereIn('id', $menuItemIds)
            ->get()
            ->keyBy('id');

        // Build list of planned deductions: [line_id, inv_item_id, base_qty_to_deduct, reason]
        $deductionPlan = [];
        $invIdsToLock = [];

        foreach ($orderLines as $line) {
            /** @var MenuItem|null $menuItem */
            $menuItem = $menuItems->get($line->menu_item_id);
            if (! $menuItem || $menuItem->stock_tracking_policy === 'none') {
                continue;
            }

            $variantMultiplier = 1.0;
            if ($line->menu_item_variant_id) {
                $variant = $menuItem->variants->firstWhere('id', $line->menu_item_variant_id);
                if ($variant) {
                    $variantMultiplier = max(0.0001, (float) $variant->recipe_multiplier);
                }
            }

            if ($menuItem->stock_tracking_policy === 'direct_item' && $menuItem->direct_inventory_item_id) {
                $invId = (int) $menuItem->direct_inventory_item_id;
                $invIdsToLock[] = $invId;
                $baseQty = round(((float) $menuItem->direct_deduction_quantity) * $variantMultiplier * $line->quantity, 4);
                if ($baseQty > 0) {
                    $deductionPlan[] = [
                        'line_id' => $line->id,
                        'inventory_item_id' => $invId,
                        'base_qty' => $baseQty,
                        'unit' => null,
                        'conversion_to_base' => 1.0,
                        'label' => "Direct sale of {$line->quantity}x {$line->item_name_snapshot}",
                    ];
                }
            } elseif ($menuItem->stock_tracking_policy === 'recipe') {
                // Match variant-specific recipes first, plus base item recipes where variant is null
                $applicableRecipes = $menuItem->recipes->filter(function (MenuItemRecipe $r) use ($line) {
                    return $r->menu_item_variant_id === null || $r->menu_item_variant_id === $line->menu_item_variant_id;
                });

                foreach ($applicableRecipes as $recipe) {
                    $invId = (int) $recipe->inventory_item_id;
                    $invIdsToLock[] = $invId;
                    $mult = $recipe->menu_item_variant_id ? 1.0 : $variantMultiplier;
                    $wasteFactor = 1.0 + (max(0.0, (float) $recipe->waste_percentage) / 100.0);
                    $rawRecipeQty = ((float) $recipe->quantity_required) * $mult * $wasteFactor * $line->quantity;

                    $deductionPlan[] = [
                        'line_id' => $line->id,
                        'inventory_item_id' => $invId,
                        'raw_qty' => $rawRecipeQty,
                        'unit' => $recipe->unit,
                        'conversion_to_base' => (float) $recipe->conversion_to_base,
                        'label' => "Recipe consumption for {$line->quantity}x {$line->item_name_snapshot}",
                    ];
                }
            }
        }

        if (empty($deductionPlan)) {
            $order->update([
                'stock_consumed' => true,
                'stock_consumed_at' => now(),
            ]);

            return;
        }

        if (! $locationId) {
            throw ValidationException::withMessages([
                'storage_location_id' => ["No active storage location is configured to deduct stock for outlet [{$outlet->name}]."],
            ]);
        }

        /** @var StorageLocation $location */
        $location = StorageLocation::where('id', $locationId)->lockForUpdate()->firstOrFail();

        $invIdsToLock = array_values(array_unique($invIdsToLock));
        sort($invIdsToLock);
        $lockedItems = InventoryItem::whereIn('id', $invIdsToLock)
            ->orderBy('id')
            ->lockForUpdate()
            ->get()
            ->keyBy('id');

        $movementType = $order->order_type === 'room_service'
            ? StockMovement::TYPE_ROOM_SERVICE_CONSUMPTION
            : StockMovement::TYPE_POS_CONSUMPTION;

        foreach ($deductionPlan as $plan) {
            /** @var InventoryItem $invItem */
            $invItem = $lockedItems->get($plan['inventory_item_id']) ?? InventoryItem::findOrFail($plan['inventory_item_id']);

            $baseQty = isset($plan['base_qty'])
                ? (float) $plan['base_qty']
                : $this->inventory->convertToBaseQuantity($invItem, (float) $plan['raw_qty'], $plan['unit'], (float) $plan['conversion_to_base']);

            if ($baseQty <= 0) {
                continue;
            }

            $uniqueKey = "pos_order:{$order->id}:line:{$plan['line_id']}:inv:{$invItem->id}";
            $reasonText = $allowNegativeOverride && filled($negativeStockReason)
                ? "{$plan['label']} ({$order->order_reference}) — Override: {$negativeStockReason}"
                : "{$plan['label']} ({$order->order_reference})";

            $this->inventory->applyLockedStockMovement(
                item: $invItem,
                location: $location,
                movementType: $movementType,
                quantityDeltaBase: -$baseQty,
                unitCostMinorPerBase: (int) $invItem->unit_cost_minor,
                actor: $actor,
                reason: $reasonText,
                notes: null,
                sourceType: 'pos_order',
                sourceId: (string) $order->id,
                idempotencyKey: null,
                uniqueSourceKey: $uniqueKey,
                allowNegativeOverride: $allowNegativeOverride,
            );
        }

        $order->update([
            'stock_consumed' => true,
            'stock_consumed_at' => now(),
        ]);
    }

    /**
     * When a voided order specifies `stock_action = 'restock'`, create auditable `restock_return` movements
     * reversing each consumption movement recorded for the order.
     */
    private function restockConsumedOrderIngredientsLocked(PosOrder $order, User $actor, string $voidReason): void
    {
        $consumptionMovements = StockMovement::where('source_type', 'pos_order')
            ->where('source_id', (string) $order->id)
            ->where('quantity_delta', '<', 0)
            ->orderBy('inventory_item_id')
            ->lockForUpdate()
            ->get();

        foreach ($consumptionMovements as $origMove) {
            $restockKey = "pos_order_restock:{$order->id}:orig_move:{$origMove->id}";
            if (StockMovement::where('unique_source_key', $restockKey)->exists()) {
                continue;
            }

            /** @var InventoryItem $invItem */
            $invItem = InventoryItem::where('id', $origMove->inventory_item_id)->lockForUpdate()->firstOrFail();
            /** @var StorageLocation $location */
            $location = StorageLocation::where('id', $origMove->storage_location_id)->lockForUpdate()->firstOrFail();

            $this->inventory->applyLockedStockMovement(
                item: $invItem,
                location: $location,
                movementType: StockMovement::TYPE_RESTOCK_RETURN,
                quantityDeltaBase: abs((float) $origMove->quantity_delta),
                unitCostMinorPerBase: (int) $origMove->unit_cost_minor,
                actor: $actor,
                reason: "Restocked from voided order {$order->order_reference}: {$voidReason}",
                notes: "Reverses movement {$origMove->movement_reference}",
                sourceType: 'pos_order_void',
                sourceId: (string) $order->id,
                idempotencyKey: null,
                uniqueSourceKey: $restockKey,
                allowNegativeOverride: false,
            );
        }
    }

    /**
     * Synchronize `amount_paid_minor`, `amount_refunded_minor`, `balance_due_minor`, and `payment_status` on `$order`.
     */
    private function syncOrderPaymentTotalsLocked(PosOrder $order, User $actor): void
    {
        $payments = PosPayment::where('pos_order_id', $order->id)
            ->where('status', 'completed')
            ->get();

        $grossPaidMinor = (int) $payments->where('type', 'payment')->sum('amount_minor');
        $refundedMinor = abs((int) $payments->whereIn('type', ['refund', 'reversal'])->sum('amount_minor'));
        $netPaidMinor = max(0, $grossPaidMinor - $refundedMinor);
        $balanceDueMinor = max(0, $order->total_minor - $netPaidMinor);

        $oldPayStatus = $order->payment_status;
        if ($netPaidMinor >= $order->total_minor && $order->total_minor > 0) {
            $newPayStatus = PosOrder::PAYMENT_PAID;
        } elseif ($netPaidMinor > 0) {
            $newPayStatus = PosOrder::PAYMENT_PARTIALLY_PAID;
        } elseif ($refundedMinor > 0) {
            $newPayStatus = PosOrder::PAYMENT_REFUNDED;
        } else {
            $newPayStatus = PosOrder::PAYMENT_UNPAID;
        }

        $methods = $payments->where('type', 'payment')->pluck('method')->unique()->values()->all();
        $settlementMethod = count($methods) > 1 ? 'split' : ($methods[0] ?? null);

        $order->update([
            'payment_status' => $newPayStatus,
            'settlement_method' => $settlementMethod,
            'amount_paid_minor' => $netPaidMinor,
            'amount_refunded_minor' => $refundedMinor,
            'balance_due_minor' => $balanceDueMinor,
            'amount_paid' => AvailabilityService::toMajor($netPaidMinor),
            'balance_due_amount' => AvailabilityService::toMajor($balanceDueMinor),
        ]);

        if ($oldPayStatus !== $newPayStatus) {
            PosOrderHistory::create([
                'pos_order_id' => $order->id,
                'from_status' => $order->status,
                'to_status' => $order->status,
                'from_payment_status' => $oldPayStatus,
                'to_payment_status' => $newPayStatus,
                'notes' => "Payment status updated to {$newPayStatus}",
                'changed_by' => $actor->id,
                'created_at' => now(),
            ]);
        }
    }

    private function generateUniqueOrderReference(): string
    {
        $date = now()->format('Ymd');
        for ($i = 0; $i < 10; $i++) {
            $ref = 'POS-'.$date.'-'.strtoupper(Str::random(6));
            if (! PosOrder::where('order_reference', $ref)->exists()) {
                return $ref;
            }
        }

        return 'POS-'.$date.'-'.strtoupper(Str::random(8));
    }

    private function generateUniquePaymentReference(): string
    {
        $date = now()->format('Ymd');
        for ($i = 0; $i < 10; $i++) {
            $ref = 'PPY-'.$date.'-'.strtoupper(Str::random(7));
            if (! PosPayment::where('payment_reference', $ref)->exists()) {
                return $ref;
            }
        }

        return 'PPY-'.$date.'-'.strtoupper(Str::random(9));
    }
}
