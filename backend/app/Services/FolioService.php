<?php

namespace App\Services;

use App\Models\FolioEntry;
use App\Models\FolioPaymentAllocation;
use App\Models\GuestFolio;
use App\Models\OperationalNotification;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\Reservation;
use App\Models\ReservationNight;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Transactional Guest Folio, Charge Ledger, Payment Allocation, and Settlement Service.
 *
 * Sign Convention & Financial Rules:
 * - All monetary calculations use integer minor units (`*_minor`, 1 NGN = 100 kobo).
 * - `direction = 'charge'`: increases `total_charges_minor` (positive `total_amount_minor`).
 * - `direction = 'credit'`: increases `total_credits_minor` (positive `total_amount_minor`), reducing balance due.
 * - `reversal` entries post with `total_amount_minor = -original.total_amount_minor` in the same `direction`,
 *   netting out the original entry's contribution while preserving an immutable, append-only ledger.
 * - `balance_due_minor = total_charges_minor - total_credits_minor - total_payments_minor + total_refunds_minor`.
 * - Once posted (`status = 'posted'`), a `FolioEntry` is immutable at both Eloquent and MariaDB trigger levels.
 */
class FolioService
{
    public function __construct(
        private SettingsService $settings,
        private AuditLogger $audit,
    ) {}

    /**
     * Get or create the GuestFolio for a Reservation, optionally syncing accommodation charges and payments.
     */
    public function ensureFolioForReservation(
        Reservation $reservation,
        ?User $actor = null,
        bool $syncAccommodation = true,
        bool $syncPayments = true
    ): GuestFolio {
        return DB::transaction(function () use ($reservation, $actor, $syncAccommodation, $syncPayments) {
            /** @var Reservation $res */
            $res = Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();

            /** @var GuestFolio|null $folio */
            $folio = GuestFolio::where('reservation_id', $res->id)->lockForUpdate()->first();

            if (! $folio) {
                $folio = GuestFolio::create([
                    'folio_reference' => $this->generateUniqueFolioReference(),
                    'reservation_id' => $res->id,
                    'guest_id' => $res->guest_id,
                    'room_id' => $res->assigned_room_id,
                    'guest_name' => $res->guest_name,
                    'status' => GuestFolio::STATUS_OPEN,
                    'currency_code' => $res->currency_code,
                    'created_by' => $actor?->id ?? $res->created_by,
                ]);
            } else {
                // Keep guest_name and room_id synchronized if reservation was updated
                if ($folio->guest_name !== $res->guest_name || $folio->room_id !== $res->assigned_room_id) {
                    $folio->update([
                        'guest_name' => $res->guest_name,
                        'room_id' => $res->assigned_room_id,
                    ]);
                }
            }

            if ($syncAccommodation) {
                $this->syncAccommodationChargesLocked($folio, $res, $actor);
            }

            if ($syncPayments) {
                $this->syncVerifiedPaymentsToFolioLocked($folio, $res, $actor);
            }

            return $this->recalculateFolioTotalsLocked($folio);
        }, 3);
    }

    /**
     * Synchronize nightly accommodation charges, taxes, service charges, or cancellation/no-show fees
     * idempotently so no `reservation_night` is ever posted twice, and amended/cancelled nights are reversed cleanly.
     */
    public function syncAccommodationCharges(GuestFolio $folio, ?User $actor = null): GuestFolio
    {
        return DB::transaction(function () use ($folio, $actor) {
            /** @var GuestFolio $lockedFolio */
            $lockedFolio = GuestFolio::where('id', $folio->id)->lockForUpdate()->firstOrFail();
            /** @var Reservation $res */
            $res = Reservation::where('id', $lockedFolio->reservation_id)->lockForUpdate()->firstOrFail();

            $this->syncAccommodationChargesLocked($lockedFolio, $res, $actor);

            return $this->recalculateFolioTotalsLocked($lockedFolio);
        }, 3);
    }

    /**
     * Internal locked synchronization of reservation accommodation charges & taxes/fees/cancellation fees.
     */
    private function syncAccommodationChargesLocked(GuestFolio $folio, Reservation $res, ?User $actor = null): void
    {
        if (in_array($folio->status, [GuestFolio::STATUS_CLOSED, GuestFolio::STATUS_VOIDED], true)) {
            return;
        }

        $isCancelledOrNoShow = in_array($res->status, [Reservation::STATUS_CANCELLED, Reservation::STATUS_NO_SHOW, Reservation::STATUS_EXPIRED], true);

        // Load active reservation nights
        $activeNights = ReservationNight::where('reservation_id', $res->id)
            ->where('status', 'active')
            ->orderBy('stay_date')
            ->orderBy('unit_index')
            ->get();

        // Build map of desired accommodation entries by active_source_key
        $desiredMap = [];
        if (! $isCancelledOrNoShow) {
            foreach ($activeNights as $night) {
                $stayDate = $night->stay_date->format('Y-m-d');
                $key = "folio:{$folio->id}:night:{$stayDate}:unit:{$night->unit_index}";
                $desiredMap[$key] = [
                    'entry_type' => 'accommodation',
                    'direction' => FolioEntry::DIRECTION_CHARGE,
                    'category' => 'room_rate',
                    'description' => "Accommodation — Night of {$stayDate}".($res->rooms_count > 1 ? " (Room #{$night->unit_index})" : ''),
                    'service_date' => $stayDate,
                    'quantity' => 1,
                    'unit_amount_minor' => $night->total_night_minor,
                    'net_amount_minor' => $night->total_night_minor,
                    'tax_amount_minor' => 0,
                    'service_charge_minor' => 0,
                    'total_amount_minor' => $night->total_night_minor,
                    'source_type' => 'reservation_night',
                    'source_id' => "{$stayDate}:{$night->unit_index}",
                ];
            }

            if ($res->taxes_minor > 0) {
                $taxKey = "folio:{$folio->id}:reservation_tax";
                $desiredMap[$taxKey] = [
                    'entry_type' => 'tax',
                    'direction' => FolioEntry::DIRECTION_CHARGE,
                    'category' => 'tax',
                    'description' => 'Accommodation Tax ('.($res->price_snapshot['tax_percentage'] ?? 0).'%)',
                    'service_date' => $res->check_in_date->format('Y-m-d'),
                    'quantity' => 1,
                    'unit_amount_minor' => $res->taxes_minor,
                    'net_amount_minor' => 0,
                    'tax_amount_minor' => $res->taxes_minor,
                    'service_charge_minor' => 0,
                    'total_amount_minor' => $res->taxes_minor,
                    'source_type' => 'reservation_tax',
                    'source_id' => (string) $res->id,
                ];
            }

            if ($res->fees_minor > 0) {
                $feeKey = "folio:{$folio->id}:reservation_fee";
                $desiredMap[$feeKey] = [
                    'entry_type' => 'service_charge',
                    'direction' => FolioEntry::DIRECTION_CHARGE,
                    'category' => 'service_charge',
                    'description' => 'Accommodation Service Charge ('.($res->price_snapshot['service_charge_percentage'] ?? 0).'%)',
                    'service_date' => $res->check_in_date->format('Y-m-d'),
                    'quantity' => 1,
                    'unit_amount_minor' => $res->fees_minor,
                    'net_amount_minor' => 0,
                    'tax_amount_minor' => 0,
                    'service_charge_minor' => $res->fees_minor,
                    'total_amount_minor' => $res->fees_minor,
                    'source_type' => 'reservation_fee',
                    'source_id' => (string) $res->id,
                ];
            }
        } elseif ($res->cancellation_fee_minor > 0) {
            $cancelKey = "folio:{$folio->id}:cancellation_fee";
            $desiredMap[$cancelKey] = [
                'entry_type' => 'cancellation_fee',
                'direction' => FolioEntry::DIRECTION_CHARGE,
                'category' => 'cancellation_fee',
                'description' => $res->status === Reservation::STATUS_NO_SHOW
                    ? "No-Show Penalty Fee ({$res->reference})"
                    : "Cancellation Penalty Fee ({$res->reference})",
                'service_date' => now()->format('Y-m-d'),
                'quantity' => 1,
                'unit_amount_minor' => $res->cancellation_fee_minor,
                'net_amount_minor' => $res->cancellation_fee_minor,
                'tax_amount_minor' => 0,
                'service_charge_minor' => 0,
                'total_amount_minor' => $res->cancellation_fee_minor,
                'source_type' => 'cancellation_fee',
                'source_id' => (string) $res->id,
            ];
        }

        // Load all existing posted system accommodation/tax/fee/cancellation_fee entries on this folio that have not been reversed
        $existingEntries = FolioEntry::where('guest_folio_id', $folio->id)
            ->where('status', FolioEntry::STATUS_POSTED)
            ->whereIn('source_type', ['reservation_night', 'reservation_tax', 'reservation_fee', 'cancellation_fee'])
            ->whereNotNull('active_source_key')
            ->lockForUpdate()
            ->get()
            ->keyBy('active_source_key');

        // 1. Reverse any existing entry that is no longer in $desiredMap OR whose amount changed due to amendment
        foreach ($existingEntries as $activeKey => $existingEntry) {
            $desired = $desiredMap[$activeKey] ?? null;
            if (! $desired || (int) $desired['total_amount_minor'] !== (int) $existingEntry->total_amount_minor) {
                $this->createSystemReversalForEntry($folio, $existingEntry, $actor, $isCancelledOrNoShow ? 'Reservation cancelled/no-show' : 'Reservation amended');
                $existingEntries->forget($activeKey);
            }
        }

        // 2. Post any missing or newly amended entries in $desiredMap
        foreach ($desiredMap as $activeKey => $spec) {
            if ($existingEntries->has($activeKey)) {
                continue;
            }

            // Use unique active_source_key (with generation suffix if a previous version was reversed)
            $uniqueActiveKey = $this->findAvailableActiveSourceKey($activeKey);

            FolioEntry::create([
                'entry_reference' => $this->generateUniqueEntryReference(),
                'guest_folio_id' => $folio->id,
                'reservation_id' => $res->id,
                'entry_type' => $spec['entry_type'],
                'direction' => $spec['direction'],
                'category' => $spec['category'],
                'description' => $spec['description'],
                'service_date' => $spec['service_date'],
                'quantity' => $spec['quantity'],
                'currency_code' => $folio->currency_code,
                'unit_amount_minor' => $spec['unit_amount_minor'],
                'net_amount_minor' => $spec['net_amount_minor'],
                'tax_amount_minor' => $spec['tax_amount_minor'],
                'service_charge_minor' => $spec['service_charge_minor'],
                'total_amount_minor' => $spec['total_amount_minor'],
                'total_amount' => AvailabilityService::toMajor($spec['total_amount_minor']),
                'status' => FolioEntry::STATUS_POSTED,
                'source_type' => $spec['source_type'],
                'source_id' => $spec['source_id'],
                'active_source_key' => $uniqueActiveKey,
                'posted_by' => $actor?->id,
                'posted_at' => now(),
            ]);
        }
    }

    /**
     * Post an itemized manual charge (`manual_charge`) to an open guest folio.
     */
    public function postManualCharge(GuestFolio $folio, array $data, User $actor): FolioEntry
    {
        return DB::transaction(function () use ($folio, $data, $actor) {
            /** @var GuestFolio $locked */
            $locked = GuestFolio::where('id', $folio->id)->lockForUpdate()->firstOrFail();
            $this->assertFolioOpenForPosting($locked);

            $idempotencyKey = isset($data['idempotency_key']) && filled($data['idempotency_key'])
                ? trim((string) $data['idempotency_key'])
                : null;

            if ($idempotencyKey) {
                $existing = FolioEntry::where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
                if ($existing) {
                    if ($existing->guest_folio_id !== $locked->id) {
                        throw ValidationException::withMessages([
                            'idempotency_key' => ['Idempotency key has already been used for another folio.'],
                        ]);
                    }

                    return $existing->load(['poster', 'requester', 'approver']);
                }
            }

            $quantity = max(1, (int) ($data['quantity'] ?? 1));
            $unitAmountMinor = AvailabilityService::toMinor($data['unit_amount'] ?? ($data['amount'] ?? 0));
            if ($unitAmountMinor <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Charge amount must be greater than zero.'],
                ]);
            }

            $netMinor = $unitAmountMinor * $quantity;
            $taxMinor = isset($data['tax_amount']) ? AvailabilityService::toMinor($data['tax_amount']) : 0;
            $serviceMinor = isset($data['service_charge_amount']) ? AvailabilityService::toMinor($data['service_charge_amount']) : 0;
            if ($taxMinor < 0 || $serviceMinor < 0) {
                throw ValidationException::withMessages(['tax_amount' => ['Tax or service charge cannot be negative.']]);
            }
            $totalMinor = $netMinor + $taxMinor + $serviceMinor;

            $entry = FolioEntry::create([
                'entry_reference' => $this->generateUniqueEntryReference(),
                'idempotency_key' => $idempotencyKey,
                'guest_folio_id' => $locked->id,
                'reservation_id' => $locked->reservation_id,
                'entry_type' => 'manual_charge',
                'direction' => FolioEntry::DIRECTION_CHARGE,
                'category' => $data['category'] ?? 'miscellaneous',
                'description' => strip_tags((string) $data['description']),
                'service_date' => $data['service_date'] ?? now()->format('Y-m-d'),
                'quantity' => $quantity,
                'currency_code' => $locked->currency_code,
                'unit_amount_minor' => $unitAmountMinor,
                'net_amount_minor' => $netMinor,
                'tax_amount_minor' => $taxMinor,
                'service_charge_minor' => $serviceMinor,
                'total_amount_minor' => $totalMinor,
                'total_amount' => AvailabilityService::toMajor($totalMinor),
                'status' => FolioEntry::STATUS_POSTED,
                'source_type' => 'manual',
                'reason' => isset($data['reason']) ? strip_tags((string) $data['reason']) : null,
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ]);

            $this->recalculateFolioTotalsLocked($locked);

            $this->audit->log('folio.charge_posted', $entry, [
                'folio_reference' => $locked->folio_reference,
                'entry_reference' => $entry->entry_reference,
                'category' => $entry->category,
                'total_amount_minor' => $entry->total_amount_minor,
            ], 'success', $actor->id);

            return $entry->load(['poster']);
        }, 3);
    }

    /**
     * Post or request a credit/discount/allowance/debit adjustment on an open guest folio.
     *
     * Separation of Duties & Threshold Controls:
     * - If the actor has `folios.adjust` (or `finance.approve`) AND the adjustment is within threshold (or actor is
     *   a manager/accountant with `finance.approve` / `folios.refund`), it can be posted immediately OR queued for
     *   review when `require_approval = true` or when requested by a staff member without `folios.adjust`.
     * - Staff with only `folios.create_charge` (e.g., Receptionist / Cashier) can submit an adjustment request
     *   (`status = 'pending_approval'`), which a DIFFERENT user with `folios.adjust` must approve (`reviewAdjustment`).
     * - Even for users with `folios.adjust` (like Front Desk Supervisor), if the credit adjustment exceeds
     *   `operations.folio_adjustment_approval_threshold_minor` (default 5,000 NGN) and the actor does not hold
     *   `finance.approve` / `folios.refund`, it is placed in `pending_approval` and cannot be self-approved!
     */
    public function postOrRequestAdjustment(GuestFolio $folio, array $data, User $actor): FolioEntry
    {
        return DB::transaction(function () use ($folio, $data, $actor) {
            /** @var GuestFolio $locked */
            $locked = GuestFolio::where('id', $folio->id)->lockForUpdate()->firstOrFail();
            $this->assertFolioOpenForPosting($locked);

            $entryType = (string) ($data['entry_type'] ?? 'credit_adjustment'); // discount | allowance | credit_adjustment | debit_adjustment
            if (! in_array($entryType, ['discount', 'allowance', 'credit_adjustment', 'debit_adjustment'], true)) {
                throw ValidationException::withMessages([
                    'entry_type' => ['Invalid adjustment entry type.'],
                ]);
            }

            $direction = $entryType === 'debit_adjustment'
                ? FolioEntry::DIRECTION_CHARGE
                : FolioEntry::DIRECTION_CREDIT;

            $amountMinor = AvailabilityService::toMinor($data['amount'] ?? 0);
            if ($amountMinor <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Adjustment amount must be greater than zero.'],
                ]);
            }

            if (empty($data['reason']) || strlen(trim((string) $data['reason'])) < 3) {
                throw ValidationException::withMessages([
                    'reason' => ['A clear reason is required for all folio adjustments and discounts.'],
                ]);
            }

            $thresholdMinor = (int) ($this->settings->all()['operations']['folio_adjustment_approval_threshold_minor'] ?? 500000);
            $hasAdjustPerm = $actor->hasPermission('folios.adjust');
            $hasHighLevelFinanceApproval = $actor->hasAnyPermission(['finance.approve', 'folios.refund']);
            $forcePending = ! empty($data['request_only']);

            // Determine whether this adjustment can be posted immediately or requires separate approval
            $canPostImmediately = ! $forcePending
                && $hasAdjustPerm
                && ($amountMinor <= $thresholdMinor || $hasHighLevelFinanceApproval);

            $status = $canPostImmediately
                ? FolioEntry::STATUS_POSTED
                : FolioEntry::STATUS_PENDING_APPROVAL;

            $entry = FolioEntry::create([
                'entry_reference' => $this->generateUniqueEntryReference(),
                'guest_folio_id' => $locked->id,
                'reservation_id' => $locked->reservation_id,
                'entry_type' => $entryType,
                'direction' => $direction,
                'category' => 'adjustment',
                'description' => strip_tags((string) ($data['description'] ?? ucfirst(str_replace('_', ' ', $entryType)))),
                'service_date' => $data['service_date'] ?? now()->format('Y-m-d'),
                'quantity' => 1,
                'currency_code' => $locked->currency_code,
                'unit_amount_minor' => $amountMinor,
                'net_amount_minor' => $amountMinor,
                'tax_amount_minor' => 0,
                'service_charge_minor' => 0,
                'total_amount_minor' => $amountMinor,
                'total_amount' => AvailabilityService::toMajor($amountMinor),
                'status' => $status,
                'source_type' => 'manual_adjustment',
                'reason' => strip_tags((string) $data['reason']),
                'requested_by' => $actor->id,
                'approved_by' => $canPostImmediately ? $actor->id : null,
                'posted_by' => $canPostImmediately ? $actor->id : null,
                'posted_at' => $canPostImmediately ? now() : null,
            ]);

            if ($canPostImmediately) {
                $this->recalculateFolioTotalsLocked($locked);
            } else {
                OperationalNotification::create([
                    'type' => 'folio.adjustment_pending',
                    'severity' => 'warning',
                    'title' => "Folio Adjustment Pending Approval: {$locked->folio_reference}",
                    'message' => "{$actor->name} requested a {$locked->currency_code} ".number_format($entry->total_amount, 2)." {$entryType} on {$locked->folio_reference}.",
                    'target_permission' => 'folios.adjust',
                    'subject_type' => FolioEntry::class,
                    'subject_id' => $entry->id,
                ]);
            }

            $this->audit->log($canPostImmediately ? 'folio.adjustment_posted' : 'folio.adjustment_requested', $entry, [
                'folio_reference' => $locked->folio_reference,
                'entry_reference' => $entry->entry_reference,
                'entry_type' => $entryType,
                'direction' => $direction,
                'total_amount_minor' => $amountMinor,
                'status' => $status,
                'reason' => $entry->reason,
            ], 'success', $actor->id);

            return $entry->load(['requester', 'approver', 'poster']);
        }, 3);
    }

    /**
     * Approve or reject a pending folio adjustment (`status = 'pending_approval'`).
     * Enforces separation of duties: the requester cannot approve their own pending adjustment!
     */
    public function reviewAdjustment(FolioEntry $entry, string $decision, User $actor, ?string $reviewNotes = null): FolioEntry
    {
        return DB::transaction(function () use ($entry, $decision, $actor, $reviewNotes) {
            /** @var GuestFolio $folio */
            $folio = GuestFolio::where('id', $entry->guest_folio_id)->lockForUpdate()->firstOrFail();
            /** @var FolioEntry $lockedEntry */
            $lockedEntry = FolioEntry::where('id', $entry->id)->lockForUpdate()->firstOrFail();

            if ($lockedEntry->status !== FolioEntry::STATUS_PENDING_APPROVAL) {
                throw ValidationException::withMessages([
                    'status' => ["Adjustment {$lockedEntry->entry_reference} has already been {$lockedEntry->status}."],
                ]);
            }

            // Separation of duties: requester cannot approve their own restricted adjustment
            if ($lockedEntry->requested_by === $actor->id && $decision === 'approve') {
                throw ValidationException::withMessages([
                    'decision' => ['Separation of duties violation: you cannot approve a folio adjustment that you requested yourself.'],
                ]);
            }

            if (! in_array($decision, ['approve', 'reject'], true)) {
                throw ValidationException::withMessages(['decision' => ['Decision must be approve or reject.']]);
            }

            if ($decision === 'reject') {
                $lockedEntry->update([
                    'status' => FolioEntry::STATUS_REJECTED,
                    'approved_by' => $actor->id,
                    'metadata' => array_merge($lockedEntry->metadata ?? [], [
                        'review_notes' => $reviewNotes ? strip_tags($reviewNotes) : 'Rejected',
                        'reviewed_at' => now()->toIso8601String(),
                    ]),
                ]);

                $this->audit->log('folio.adjustment_rejected', $lockedEntry, [
                    'folio_reference' => $folio->folio_reference,
                    'entry_reference' => $lockedEntry->entry_reference,
                ], 'success', $actor->id);

                return $lockedEntry->fresh(['requester', 'approver', 'poster']);
            }

            $this->assertFolioOpenForPosting($folio);

            $lockedEntry->update([
                'status' => FolioEntry::STATUS_POSTED,
                'approved_by' => $actor->id,
                'posted_by' => $actor->id,
                'posted_at' => now(),
                'metadata' => array_merge($lockedEntry->metadata ?? [], [
                    'review_notes' => $reviewNotes ? strip_tags($reviewNotes) : 'Approved',
                    'reviewed_at' => now()->toIso8601String(),
                ]),
            ]);

            $this->recalculateFolioTotalsLocked($folio);

            $this->audit->log('folio.adjustment_approved', $lockedEntry, [
                'folio_reference' => $folio->folio_reference,
                'entry_reference' => $lockedEntry->entry_reference,
                'total_amount_minor' => $lockedEntry->total_amount_minor,
            ], 'success', $actor->id);

            return $lockedEntry->fresh(['requester', 'approver', 'poster']);
        }, 3);
    }

    /**
     * Reverse a posted FolioEntry via an append-only contra entry (`reversed_entry_id = $original->id`).
     * Never updates or deletes the original posted row.
     */
    public function reversePostedEntry(FolioEntry $entry, string $reason, User $actor): FolioEntry
    {
        return DB::transaction(function () use ($entry, $reason, $actor) {
            /** @var GuestFolio $folio */
            $folio = GuestFolio::where('id', $entry->guest_folio_id)->lockForUpdate()->firstOrFail();
            $this->assertFolioOpenForPosting($folio);

            /** @var FolioEntry $orig */
            $orig = FolioEntry::where('id', $entry->id)->lockForUpdate()->firstOrFail();

            if ($orig->status !== FolioEntry::STATUS_POSTED) {
                throw ValidationException::withMessages([
                    'entry' => ['Only posted folio entries can be reversed.'],
                ]);
            }

            if ($orig->entry_type === 'reversal') {
                throw ValidationException::withMessages([
                    'entry' => ['A reversal entry cannot itself be reversed.'],
                ]);
            }

            if (FolioEntry::where('reversed_entry_id', $orig->id)->exists()) {
                throw ValidationException::withMessages([
                    'entry' => ["Folio entry {$orig->entry_reference} has already been reversed."],
                ]);
            }

            $reversal = FolioEntry::create([
                'entry_reference' => $this->generateUniqueEntryReference(),
                'guest_folio_id' => $folio->id,
                'reservation_id' => $folio->reservation_id,
                'entry_type' => 'reversal',
                'direction' => $orig->direction, // Same direction with negative amounts so charges/credits net out cleanly
                'category' => 'reversal',
                'description' => "Reversal of {$orig->entry_reference}: {$orig->description}",
                'service_date' => now()->format('Y-m-d'),
                'quantity' => $orig->quantity,
                'currency_code' => $orig->currency_code,
                'unit_amount_minor' => -$orig->unit_amount_minor,
                'net_amount_minor' => -$orig->net_amount_minor,
                'tax_amount_minor' => -$orig->tax_amount_minor,
                'service_charge_minor' => -$orig->service_charge_minor,
                'total_amount_minor' => -$orig->total_amount_minor,
                'total_amount' => -AvailabilityService::toMajor($orig->total_amount_minor),
                'status' => FolioEntry::STATUS_POSTED,
                'source_type' => 'reversal',
                'source_id' => (string) $orig->id,
                'reversed_entry_id' => $orig->id,
                'reason' => strip_tags($reason),
                'requested_by' => $actor->id,
                'approved_by' => $actor->id,
                'posted_by' => $actor->id,
                'posted_at' => now(),
            ]);

            $this->recalculateFolioTotalsLocked($folio);

            $this->audit->log('folio.entry_reversed', $reversal, [
                'folio_reference' => $folio->folio_reference,
                'original_entry_reference' => $orig->entry_reference,
                'reversal_entry_reference' => $reversal->entry_reference,
                'total_amount_minor' => $reversal->total_amount_minor,
                'reason' => strip_tags($reason),
            ], 'success', $actor->id);

            return $reversal->load(['poster', 'reversedEntry']);
        }, 3);
    }

    /**
     * Allocate a verified `PaymentTransaction` (or portion thereof) to a `GuestFolio`.
     * Prevents allocating unverified/failed payments or allocating more than the payment's remaining unallocated balance.
     */
    public function allocatePaymentToFolio(
        GuestFolio $folio,
        PaymentTransaction $payment,
        ?int $amountMinor = null,
        ?User $actor = null,
        ?string $notes = null
    ): FolioPaymentAllocation {
        return DB::transaction(function () use ($folio, $payment, $amountMinor, $actor, $notes) {
            /** @var GuestFolio $lockedFolio */
            $lockedFolio = GuestFolio::where('id', $folio->id)->lockForUpdate()->firstOrFail();
            /** @var PaymentTransaction $lockedTx */
            $lockedTx = PaymentTransaction::where('id', $payment->id)->lockForUpdate()->firstOrFail();

            if ($lockedTx->reservation_id !== $lockedFolio->reservation_id) {
                throw ValidationException::withMessages([
                    'payment_transaction_id' => ['Payment transaction does not belong to this folio\'s reservation.'],
                ]);
            }

            if ($lockedTx->type !== 'payment' || $lockedTx->status !== 'completed') {
                throw ValidationException::withMessages([
                    'payment_transaction_id' => ['Only completed, verified payment transactions can be allocated to a guest folio.'],
                ]);
            }

            if (PaymentTransaction::where('reversed_transaction_id', $lockedTx->id)->exists()) {
                throw ValidationException::withMessages([
                    'payment_transaction_id' => ['Cannot allocate a payment transaction that has been reversed.'],
                ]);
            }

            $alreadyAllocatedMinor = (int) FolioPaymentAllocation::where('payment_transaction_id', $lockedTx->id)
                ->lockForUpdate()
                ->sum('allocated_amount_minor');

            $remainingUnallocatedMinor = max(0, $lockedTx->amount_minor - $alreadyAllocatedMinor);
            $targetMinor = $amountMinor ?? $remainingUnallocatedMinor;

            if ($targetMinor <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Allocation amount must be greater than zero and transaction must have unallocated balance.'],
                ]);
            }

            if ($targetMinor > $remainingUnallocatedMinor) {
                throw ValidationException::withMessages([
                    'amount' => ["Cannot allocate {$targetMinor} minor units; only {$remainingUnallocatedMinor} minor units remain unallocated on {$lockedTx->transaction_reference}."],
                ]);
            }

            $allocation = FolioPaymentAllocation::create([
                'allocation_reference' => 'FPA-'.now()->format('Ymd').'-'.strtoupper(Str::random(8)),
                'guest_folio_id' => $lockedFolio->id,
                'reservation_id' => $lockedFolio->reservation_id,
                'payment_transaction_id' => $lockedTx->id,
                'allocation_type' => 'payment',
                'currency_code' => $lockedFolio->currency_code,
                'allocated_amount_minor' => $targetMinor,
                'allocated_amount' => AvailabilityService::toMajor($targetMinor),
                'notes' => $notes ? strip_tags($notes) : "Allocated from {$lockedTx->transaction_reference}",
                'allocated_by' => $actor?->id ?? $lockedTx->recorded_by,
                'allocated_at' => now(),
            ]);

            $this->recalculateFolioTotalsLocked($lockedFolio);

            $this->audit->log('folio.payment_allocated', $allocation, [
                'folio_reference' => $lockedFolio->folio_reference,
                'transaction_reference' => $lockedTx->transaction_reference,
                'allocated_amount_minor' => $targetMinor,
            ], 'success', $actor?->id);

            return $allocation->load(['paymentTransaction', 'allocator']);
        }, 3);
    }

    /**
     * Automatically synchronize all verified payments, reversals, and processed refunds on the reservation to its folio.
     */
    private function syncVerifiedPaymentsToFolioLocked(GuestFolio $folio, Reservation $res, ?User $actor = null): void
    {
        $completedPayments = PaymentTransaction::where('reservation_id', $res->id)
            ->where('type', 'payment')
            ->where('status', 'completed')
            ->get();

        foreach ($completedPayments as $tx) {
            $isReversed = PaymentTransaction::where('reversed_transaction_id', $tx->id)->exists();
            $allocatedMinor = (int) FolioPaymentAllocation::where('guest_folio_id', $folio->id)
                ->where('payment_transaction_id', $tx->id)
                ->sum('allocated_amount_minor');

            if (! $isReversed && $allocatedMinor === 0 && $tx->amount_minor > 0) {
                FolioPaymentAllocation::create([
                    'allocation_reference' => 'FPA-'.now()->format('Ymd').'-'.strtoupper(Str::random(8)),
                    'guest_folio_id' => $folio->id,
                    'reservation_id' => $res->id,
                    'payment_transaction_id' => $tx->id,
                    'allocation_type' => 'payment',
                    'currency_code' => $folio->currency_code,
                    'allocated_amount_minor' => $tx->amount_minor,
                    'allocated_amount' => AvailabilityService::toMajor($tx->amount_minor),
                    'notes' => "Auto-allocated from verified payment {$tx->transaction_reference}",
                    'allocated_by' => $actor?->id ?? $tx->recorded_by,
                    'allocated_at' => now(),
                ]);
            } elseif ($isReversed && $allocatedMinor > 0) {
                // Append an unallocation record so reversed payment no longer counts toward folio payments
                FolioPaymentAllocation::create([
                    'allocation_reference' => 'FPA-'.now()->format('Ymd').'-'.strtoupper(Str::random(8)),
                    'guest_folio_id' => $folio->id,
                    'reservation_id' => $res->id,
                    'payment_transaction_id' => $tx->id,
                    'allocation_type' => 'unallocation',
                    'currency_code' => $folio->currency_code,
                    'allocated_amount_minor' => -$allocatedMinor,
                    'allocated_amount' => -AvailabilityService::toMajor($allocatedMinor),
                    'notes' => "Reversed allocation following payment reversal of {$tx->transaction_reference}",
                    'allocated_by' => $actor?->id,
                    'allocated_at' => now(),
                ]);
            }
        }
    }

    /**
     * Settle and close a GuestFolio (`open` -> `closed`).
     *
     * Rules:
     * - By default, a folio can only be closed when `balance_due_minor === 0` and there are no `pending_approval` entries.
     * - If `balance_due_minor > 0` (or `< 0` overpayment), closing is rejected unless `allow_exception = true` AND the
     *   actor holds `folios.refund` or `finance.approve` AND provides a `settlement_exception_reason`.
     * - Once closed, no further charges or adjustments can be posted unless reopened by an authorized manager/accountant.
     */
    public function settleAndCloseFolio(GuestFolio $folio, array $data, User $actor): GuestFolio
    {
        return DB::transaction(function () use ($folio, $data, $actor) {
            /** @var GuestFolio $locked */
            $locked = GuestFolio::where('id', $folio->id)->lockForUpdate()->firstOrFail();

            if ($locked->status === GuestFolio::STATUS_CLOSED) {
                throw ValidationException::withMessages([
                    'status' => ["Folio {$locked->folio_reference} is already closed."],
                ]);
            }

            /** @var Reservation $res */
            $res = Reservation::where('id', $locked->reservation_id)->lockForUpdate()->firstOrFail();

            // Sync latest accommodation charges and verified payments before checking balance
            $this->syncAccommodationChargesLocked($locked, $res, $actor);
            $this->syncVerifiedPaymentsToFolioLocked($locked, $res, $actor);
            $locked = $this->recalculateFolioTotalsLocked($locked);

            $pendingCount = FolioEntry::where('guest_folio_id', $locked->id)
                ->where('status', FolioEntry::STATUS_PENDING_APPROVAL)
                ->count();

            if ($pendingCount > 0) {
                throw ValidationException::withMessages([
                    'status' => ["Cannot settle folio {$locked->folio_reference} while {$pendingCount} adjustment(s) are pending approval."],
                ]);
            }

            $allowException = ! empty($data['allow_exception']);
            $exceptionReason = isset($data['settlement_exception_reason'])
                ? trim(strip_tags((string) $data['settlement_exception_reason']))
                : null;

            if ($locked->balance_due_minor !== 0) {
                if (! $allowException) {
                    $formattedBalance = number_format($locked->balance_due_amount, 2);
                    throw ValidationException::withMessages([
                        'balance_due' => ["Folio {$locked->folio_reference} has a non-zero balance of {$locked->currency_code} {$formattedBalance}. Record/allocate payment or use an authorized settlement exception."],
                    ]);
                }

                if (! $actor->hasAnyPermission(['folios.refund', 'finance.approve'])) {
                    abort(403, 'You do not have permission to authorize a non-zero balance folio settlement exception.');
                }

                if (! $exceptionReason || strlen($exceptionReason) < 5) {
                    throw ValidationException::withMessages([
                        'settlement_exception_reason' => ['A detailed reason is required when closing a folio with a non-zero balance exception.'],
                    ]);
                }
            }

            $locked->update([
                'status' => GuestFolio::STATUS_CLOSED,
                'settled_with_exception' => $locked->balance_due_minor !== 0,
                'settlement_exception_reason' => $locked->balance_due_minor !== 0 ? $exceptionReason : null,
                'notes' => isset($data['notes']) ? strip_tags((string) $data['notes']) : $locked->notes,
                'settled_at' => now(),
                'settled_by' => $actor->id,
                'closed_at' => now(),
                'closed_by' => $actor->id,
            ]);

            if ($locked->settled_with_exception) {
                OperationalNotification::create([
                    'type' => 'folio.settlement_exception',
                    'severity' => 'warning',
                    'title' => "Folio Closed with Balance Exception: {$locked->folio_reference}",
                    'message' => "Folio {$locked->folio_reference} closed by {$actor->name} with balance {$locked->currency_code} ".number_format($locked->balance_due_amount, 2).". Reason: {$exceptionReason}",
                    'target_permission' => 'folios.reports.view',
                    'subject_type' => GuestFolio::class,
                    'subject_id' => $locked->id,
                ]);
            }

            $this->audit->log('folio.settled_and_closed', $locked, [
                'folio_reference' => $locked->folio_reference,
                'reservation_reference' => $res->reference,
                'total_charges_minor' => $locked->total_charges_minor,
                'total_credits_minor' => $locked->total_credits_minor,
                'total_payments_minor' => $locked->total_payments_minor,
                'balance_due_minor' => $locked->balance_due_minor,
                'settled_with_exception' => $locked->settled_with_exception,
                'settlement_exception_reason' => $locked->settlement_exception_reason,
            ], 'success', $actor->id);

            return $locked->fresh(['reservation', 'entries.poster', 'paymentAllocations.paymentTransaction', 'settler', 'closer']);
        }, 3);
    }

    /**
     * Reopen a closed folio (requires `folios.refund` or `finance.approve`).
     */
    public function reopenFolio(GuestFolio $folio, string $reason, User $actor): GuestFolio
    {
        return DB::transaction(function () use ($folio, $reason, $actor) {
            if (! $actor->hasAnyPermission(['folios.refund', 'finance.approve'])) {
                abort(403, 'You do not have permission to reopen a closed guest folio.');
            }

            /** @var GuestFolio $locked */
            $locked = GuestFolio::where('id', $folio->id)->lockForUpdate()->firstOrFail();

            if ($locked->status !== GuestFolio::STATUS_CLOSED) {
                throw ValidationException::withMessages([
                    'status' => ['Only closed folios can be reopened.'],
                ]);
            }

            $locked->update([
                'status' => GuestFolio::STATUS_OPEN,
                'closed_at' => null,
                'closed_by' => null,
            ]);

            $this->audit->log('folio.reopened', $locked, [
                'folio_reference' => $locked->folio_reference,
                'reason' => strip_tags($reason),
            ], 'success', $actor->id);

            return $this->recalculateFolioTotalsLocked($locked);
        }, 3);
    }

    /**
     * Phase 4 -> Phase 5 Restaurant / Room-Service / POS Integration Boundary.
     *
     * Allows future internal services (such as Restaurant POS / Room Service in Phase 5) to post
     * verified room charges idempotently to an in-house guest's open folio without duplicating
     * ledger logic or trusting unverified external payloads.
     */
    public function postTrustedExternalCharge(
        Reservation $reservation,
        string $sourceType,
        string $sourceId,
        array $chargeData,
        ?User $actor = null
    ): FolioEntry {
        return DB::transaction(function () use ($reservation, $sourceType, $sourceId, $chargeData, $actor) {
            /** @var Reservation $res */
            $res = Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();

            if ($res->status !== Reservation::STATUS_CHECKED_IN) {
                throw ValidationException::withMessages([
                    'reservation' => ["Room charges from [{$sourceType}] can only be posted to checked-in reservations (current status: {$res->status})."],
                ]);
            }

            $folio = $this->ensureFolioForReservation($res, $actor);
            /** @var GuestFolio $lockedFolio */
            $lockedFolio = GuestFolio::where('id', $folio->id)->lockForUpdate()->firstOrFail();
            $this->assertFolioOpenForPosting($lockedFolio);

            $activeKey = "folio:{$lockedFolio->id}:ext:{$sourceType}:{$sourceId}";
            $existing = FolioEntry::where('active_source_key', $activeKey)->lockForUpdate()->first();
            if ($existing) {
                $wasReversed = FolioEntry::where('reversed_entry_id', $existing->id)->exists();
                if (! $wasReversed) {
                    return $existing;
                }
                $activeKey = $this->findAvailableActiveSourceKey($activeKey);
            }

            $netMinor = (int) ($chargeData['net_amount_minor'] ?? AvailabilityService::toMinor($chargeData['net_amount'] ?? 0));
            $taxMinor = (int) ($chargeData['tax_amount_minor'] ?? AvailabilityService::toMinor($chargeData['tax_amount'] ?? 0));
            $serviceMinor = (int) ($chargeData['service_charge_minor'] ?? AvailabilityService::toMinor($chargeData['service_charge_amount'] ?? 0));
            $totalMinor = $netMinor + $taxMinor + $serviceMinor;

            if ($totalMinor <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['External room charge total must be greater than zero.'],
                ]);
            }

            $baseIdempotencyKey = $chargeData['idempotency_key'] ?? "{$sourceType}:{$sourceId}";
            $idempotencyKey = FolioEntry::where('idempotency_key', $baseIdempotencyKey)->exists()
                ? "{$baseIdempotencyKey}:".Str::random(6)
                : $baseIdempotencyKey;

            $entry = FolioEntry::create([
                'entry_reference' => $this->generateUniqueEntryReference(),
                'idempotency_key' => $idempotencyKey,
                'guest_folio_id' => $lockedFolio->id,
                'reservation_id' => $res->id,
                'entry_type' => 'pos_charge',
                'direction' => FolioEntry::DIRECTION_CHARGE,
                'category' => $chargeData['category'] ?? 'pos_restaurant',
                'description' => strip_tags((string) ($chargeData['description'] ?? "POS Room Charge #{$sourceId}")),
                'service_date' => $chargeData['service_date'] ?? now()->format('Y-m-d'),
                'quantity' => max(1, (int) ($chargeData['quantity'] ?? 1)),
                'currency_code' => $lockedFolio->currency_code,
                'unit_amount_minor' => $netMinor,
                'net_amount_minor' => $netMinor,
                'tax_amount_minor' => $taxMinor,
                'service_charge_minor' => $serviceMinor,
                'total_amount_minor' => $totalMinor,
                'total_amount' => AvailabilityService::toMajor($totalMinor),
                'status' => FolioEntry::STATUS_POSTED,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'active_source_key' => $activeKey,
                'metadata' => $chargeData['metadata'] ?? null,
                'posted_by' => $actor?->id,
                'posted_at' => now(),
            ]);

            $this->recalculateFolioTotalsLocked($lockedFolio);

            $this->audit->log('folio.external_charge_posted', $entry, [
                'folio_reference' => $lockedFolio->folio_reference,
                'entry_reference' => $entry->entry_reference,
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'total_amount_minor' => $totalMinor,
            ], 'success', $actor?->id);

            return $entry;
        }, 3);
    }

    /**
     * Recalculate and persist all summary totals and `balance_due_minor` on a locked `GuestFolio`.
     */
    public function recalculateFolioTotalsLocked(GuestFolio $folio): GuestFolio
    {
        $postedEntries = FolioEntry::where('guest_folio_id', $folio->id)
            ->where('status', FolioEntry::STATUS_POSTED)
            ->get();

        $chargeEntries = $postedEntries->where('direction', FolioEntry::DIRECTION_CHARGE);
        $creditEntries = $postedEntries->where('direction', FolioEntry::DIRECTION_CREDIT);

        $subtotalChargesMinor = (int) $chargeEntries->sum('net_amount_minor');
        $taxesMinor = (int) $chargeEntries->sum('tax_amount_minor');
        $serviceChargesMinor = (int) $chargeEntries->sum('service_charge_minor');
        $totalChargesMinor = (int) $chargeEntries->sum('total_amount_minor');
        $totalCreditsMinor = (int) $creditEntries->sum('total_amount_minor');

        $totalPaymentsMinor = max(0, (int) FolioPaymentAllocation::where('guest_folio_id', $folio->id)
            ->sum('allocated_amount_minor'));

        $totalRefundsMinor = (int) PaymentRefund::where('reservation_id', $folio->reservation_id)
            ->where('status', 'processed')
            ->sum('amount_minor');

        $netPaymentsAfterRefundsMinor = max(0, $totalPaymentsMinor - $totalRefundsMinor);
        $balanceDueMinor = $totalChargesMinor - $totalCreditsMinor - $netPaymentsAfterRefundsMinor;

        $folio->update([
            'subtotal_charges_minor' => $subtotalChargesMinor,
            'taxes_minor' => $taxesMinor,
            'service_charges_minor' => $serviceChargesMinor,
            'total_charges_minor' => $totalChargesMinor,
            'total_credits_minor' => $totalCreditsMinor,
            'total_payments_minor' => $totalPaymentsMinor,
            'total_refunds_minor' => $totalRefundsMinor,
            'balance_due_minor' => $balanceDueMinor,
            'total_charges_amount' => AvailabilityService::toMajor($totalChargesMinor),
            'total_credits_amount' => AvailabilityService::toMajor($totalCreditsMinor),
            'total_payments_amount' => AvailabilityService::toMajor($totalPaymentsMinor),
            'total_refunds_amount' => AvailabilityService::toMajor($totalRefundsMinor),
            'balance_due_amount' => AvailabilityService::toMajor($balanceDueMinor),
        ]);

        return $folio->fresh();
    }

    private function createSystemReversalForEntry(GuestFolio $folio, FolioEntry $orig, ?User $actor, string $reason): FolioEntry
    {
        if (FolioEntry::where('reversed_entry_id', $orig->id)->exists()) {
            return FolioEntry::where('reversed_entry_id', $orig->id)->firstOrFail();
        }

        return FolioEntry::create([
            'entry_reference' => $this->generateUniqueEntryReference(),
            'guest_folio_id' => $folio->id,
            'reservation_id' => $folio->reservation_id,
            'entry_type' => 'reversal',
            'direction' => $orig->direction,
            'category' => 'reversal',
            'description' => "System Reversal of {$orig->entry_reference}: {$orig->description}",
            'service_date' => $orig->service_date->format('Y-m-d'),
            'quantity' => $orig->quantity,
            'currency_code' => $orig->currency_code,
            'unit_amount_minor' => -$orig->unit_amount_minor,
            'net_amount_minor' => -$orig->net_amount_minor,
            'tax_amount_minor' => -$orig->tax_amount_minor,
            'service_charge_minor' => -$orig->service_charge_minor,
            'total_amount_minor' => -$orig->total_amount_minor,
            'total_amount' => -AvailabilityService::toMajor($orig->total_amount_minor),
            'status' => FolioEntry::STATUS_POSTED,
            'source_type' => 'reversal',
            'source_id' => (string) $orig->id,
            'reversed_entry_id' => $orig->id,
            'reason' => $reason,
            'posted_by' => $actor?->id,
            'posted_at' => now(),
        ]);
    }

    private function findAvailableActiveSourceKey(string $baseKey): string
    {
        // If no entry has used $baseKey yet, use it directly
        $existing = FolioEntry::where('active_source_key', $baseKey)->first();
        if (! $existing) {
            return $baseKey;
        }
        // If the entry that used $baseKey was reversed, find the next available revision suffix
        for ($rev = 2; $rev <= 50; $rev++) {
            $candidate = "{$baseKey}:r{$rev}";
            if (! FolioEntry::where('active_source_key', $candidate)->exists()) {
                return $candidate;
            }
        }

        return $baseKey.':'.Str::random(6);
    }

    private function assertFolioOpenForPosting(GuestFolio $folio): void
    {
        if ($folio->status !== GuestFolio::STATUS_OPEN) {
            throw ValidationException::withMessages([
                'folio' => ["Cannot post charges or adjustments to folio {$folio->folio_reference} because its status is [{$folio->status}]."],
            ]);
        }
    }

    private function generateUniqueFolioReference(): string
    {
        $year = now()->format('Y');
        for ($i = 0; $i < 10; $i++) {
            $ref = 'FOL-'.$year.'-'.strtoupper(Str::random(6));
            if (! GuestFolio::where('folio_reference', $ref)->exists()) {
                return $ref;
            }
        }

        return 'FOL-'.$year.'-'.strtoupper(Str::random(8));
    }

    private function generateUniqueEntryReference(): string
    {
        $date = now()->format('Ymd');
        for ($i = 0; $i < 10; $i++) {
            $ref = 'FE-'.$date.'-'.strtoupper(Str::random(7));
            if (! FolioEntry::where('entry_reference', $ref)->exists()) {
                return $ref;
            }
        }

        return 'FE-'.$date.'-'.strtoupper(Str::random(9));
    }
}
