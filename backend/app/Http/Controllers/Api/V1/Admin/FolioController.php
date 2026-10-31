<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\FolioEntry;
use App\Models\GuestFolio;
use App\Models\OperationalNotification;
use App\Models\PaymentTransaction;
use App\Models\Reservation;
use App\Services\AvailabilityService;
use App\Services\FolioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class FolioController extends Controller
{
    public function __construct(
        private FolioService $folios,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['folios.view', 'folios.reports.view', 'finance.view'])) {
            abort(403, 'You do not have permission to view guest folios.');
        }

        // Ensure any existing reservations that do not yet have a folio get one initialized
        $missingReservations = Reservation::whereDoesntHave('folio')->limit(50)->get();
        foreach ($missingReservations as $res) {
            $this->folios->ensureFolioForReservation($res, $request->user());
        }

        $query = GuestFolio::query()
            ->with(['reservation.roomType', 'room:id,room_number', 'settler:id,name', 'closer:id,name']);

        if ($status = $request->query('status')) {
            $query->where('status', (string) $status);
        }
        if ($request->boolean('unsettled_only', false)) {
            $query->where('status', GuestFolio::STATUS_OPEN)->where('balance_due_minor', '>', 0);
        }
        if ($search = trim((string) $request->query('search', ''))) {
            $query->where(function ($q) use ($search) {
                $q->where('folio_reference', 'like', "%{$search}%")
                    ->orWhere('guest_name', 'like', "%{$search}%")
                    ->orWhereHas('reservation', fn ($rq) => $rq->where('reference', 'like', "%{$search}%"))
                    ->orWhereHas('room', fn ($rmq) => $rmq->where('room_number', 'like', "%{$search}%"));
            });
        }

        $paginated = $query->orderByDesc('id')->paginate(25);

        $summary = [
            'open_folios' => GuestFolio::where('status', GuestFolio::STATUS_OPEN)->count(),
            'closed_folios' => GuestFolio::where('status', GuestFolio::STATUS_CLOSED)->count(),
            'unsettled_balance_minor' => (int) GuestFolio::where('status', GuestFolio::STATUS_OPEN)->where('balance_due_minor', '>', 0)->sum('balance_due_minor'),
            'unsettled_balance_amount' => AvailabilityService::toMajor((int) GuestFolio::where('status', GuestFolio::STATUS_OPEN)->where('balance_due_minor', '>', 0)->sum('balance_due_minor')),
            'pending_adjustments' => FolioEntry::where('status', FolioEntry::STATUS_PENDING_APPROVAL)->count(),
            'settlement_exceptions' => GuestFolio::where('settled_with_exception', true)->count(),
        ];

        return response()->json([
            'data' => $paginated->items(),
            'meta' => [
                'current_page' => $paginated->currentPage(),
                'last_page' => $paginated->lastPage(),
                'total' => $paginated->total(),
            ],
            'summary' => $summary,
        ]);
    }

    public function show(Request $request, GuestFolio $guestFolio): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['folios.view', 'folios.reports.view', 'finance.view'])) {
            abort(403, 'You do not have permission to view guest folios.');
        }

        // Sync accommodation charges and payments if open
        if ($guestFolio->status === GuestFolio::STATUS_OPEN && $guestFolio->reservation) {
            $guestFolio = $this->folios->ensureFolioForReservation($guestFolio->reservation, $request->user());
        }

        $guestFolio->load([
            'reservation.roomType',
            'reservation.ratePlan',
            'reservation.assignedRoom',
            'reservation.payments.recorder:id,name',
            'reservation.refunds.requester:id,name',
            'room:id,room_number,floor',
            'entries.requester:id,name',
            'entries.approver:id,name',
            'entries.poster:id,name',
            'entries.reversedEntry:id,entry_reference,description',
            'paymentAllocations.paymentTransaction',
            'paymentAllocations.allocator:id,name',
            'settler:id,name',
            'closer:id,name',
        ]);

        return response()->json(['data' => $guestFolio]);
    }

    public function showByReservation(Request $request, Reservation $reservation): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['folios.view', 'reservations.view', 'frontdesk.view', 'finance.view'])) {
            abort(403, 'You do not have permission to view this reservation folio.');
        }

        $folio = $this->folios->ensureFolioForReservation($reservation, $request->user());

        return $this->show($request, $folio);
    }

    public function syncAccommodation(Request $request, GuestFolio $guestFolio): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['folios.create_charge', 'folios.settle'])) {
            abort(403, 'You do not have permission to post accommodation charges.');
        }

        $updated = $this->folios->syncAccommodationCharges($guestFolio, $request->user());

        return response()->json(['message' => 'Accommodation charges synchronized.', 'data' => $updated->load(['entries.poster', 'paymentAllocations.paymentTransaction'])]);
    }

    public function postCharge(Request $request, GuestFolio $guestFolio): JsonResponse
    {
        Gate::authorize('folios.create_charge');

        $data = $request->validate([
            'category' => ['required', 'string', Rule::in([
                'laundry', 'minibar', 'extra_bed', 'late_checkout', 'early_checkin',
                'damage', 'room_service', 'miscellaneous',
            ])],
            'description' => ['required', 'string', 'min:2', 'max:255'],
            'service_date' => ['nullable', 'date_format:Y-m-d'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:100'],
            'unit_amount' => ['required', 'numeric', 'min:0.01'],
            'tax_amount' => ['nullable', 'numeric', 'min:0'],
            'service_charge_amount' => ['nullable', 'numeric', 'min:0'],
            'reason' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['nullable', 'string', 'max:96'],
        ]);

        $entry = $this->folios->postManualCharge($guestFolio, $data, $request->user());

        return response()->json([
            'message' => "Charge {$entry->entry_reference} posted to folio.",
            'data' => $entry,
            'folio' => $guestFolio->fresh(),
        ], 201);
    }

    public function postAdjustment(Request $request, GuestFolio $guestFolio): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['folios.adjust', 'folios.create_charge'])) {
            abort(403, 'You do not have permission to post or request folio adjustments.');
        }

        $data = $request->validate([
            'entry_type' => ['required', 'string', Rule::in(['discount', 'allowance', 'credit_adjustment', 'debit_adjustment'])],
            'description' => ['required', 'string', 'min:3', 'max:255'],
            'service_date' => ['nullable', 'date_format:Y-m-d'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'request_only' => ['sometimes', 'boolean'],
        ]);

        $entry = $this->folios->postOrRequestAdjustment($guestFolio, $data, $request->user());
        $statusMsg = $entry->status === FolioEntry::STATUS_POSTED
            ? "Adjustment {$entry->entry_reference} posted."
            : "Adjustment {$entry->entry_reference} submitted for supervisor/finance approval.";

        return response()->json([
            'message' => $statusMsg,
            'data' => $entry,
            'folio' => $guestFolio->fresh(),
        ], 201);
    }

    public function reviewAdjustment(Request $request, FolioEntry $folioEntry): JsonResponse
    {
        Gate::authorize('folios.adjust');

        $data = $request->validate([
            'decision' => ['required', 'string', Rule::in(['approve', 'reject'])],
            'review_notes' => ['nullable', 'string', 'max:500'],
        ]);

        $updated = $this->folios->reviewAdjustment($folioEntry, $data['decision'], $request->user(), $data['review_notes'] ?? null);

        return response()->json([
            'message' => "Adjustment {$updated->entry_reference} {$updated->status}.",
            'data' => $updated,
            'folio' => $updated->folio->fresh(),
        ]);
    }

    public function reverseEntry(Request $request, FolioEntry $folioEntry): JsonResponse
    {
        Gate::authorize('folios.adjust');

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        $reversal = $this->folios->reversePostedEntry($folioEntry, $data['reason'], $request->user());

        return response()->json([
            'message' => "Reversal {$reversal->entry_reference} posted.",
            'data' => $reversal,
            'folio' => $folioEntry->folio->fresh(),
        ], 201);
    }

    public function allocatePayment(Request $request, GuestFolio $guestFolio): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['folios.settle', 'payments.record', 'finance.record'])) {
            abort(403, 'You do not have permission to allocate payments to a folio.');
        }

        $data = $request->validate([
            'payment_transaction_id' => ['required', 'integer', 'exists:payment_transactions,id'],
            'amount' => ['nullable', 'numeric', 'min:0.01'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $tx = PaymentTransaction::findOrFail((int) $data['payment_transaction_id']);
        $amountMinor = isset($data['amount']) ? AvailabilityService::toMinor($data['amount']) : null;

        $allocation = $this->folios->allocatePaymentToFolio($guestFolio, $tx, $amountMinor, $request->user(), $data['notes'] ?? null);

        return response()->json([
            'message' => 'Payment allocated to folio.',
            'data' => $allocation,
            'folio' => $guestFolio->fresh(),
        ], 201);
    }

    public function settle(Request $request, GuestFolio $guestFolio): JsonResponse
    {
        Gate::authorize('folios.settle');

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:1000'],
            'allow_exception' => ['sometimes', 'boolean'],
            'settlement_exception_reason' => ['nullable', 'string', 'max:255'],
        ]);

        $closed = $this->folios->settleAndCloseFolio($guestFolio, $data, $request->user());

        return response()->json([
            'message' => "Folio {$closed->folio_reference} settled and closed.",
            'data' => $closed,
        ]);
    }

    public function reopen(Request $request, GuestFolio $guestFolio): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['folios.refund', 'finance.approve'])) {
            abort(403, 'You do not have permission to reopen closed folios.');
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $reopened = $this->folios->reopenFolio($guestFolio, $data['reason'], $request->user());

        return response()->json([
            'message' => "Folio {$reopened->folio_reference} reopened.",
            'data' => $reopened,
        ]);
    }

    public function notifications(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = OperationalNotification::query()
            ->where(function ($q) use ($user) {
                $q->where('target_user_id', $user->id)
                    ->orWhereNull('target_user_id');
            })
            ->where(fn ($q) => $q->whereNull('target_permission')->orWhereIn('target_permission', $user->permissionSlugs()));
        $unread = (clone $query)->where('is_read', false)->count();
        $notifications = $query
            ->orderBy('is_read')
            ->orderByDesc('id')
            ->limit(30)
            ->get(['id', 'type', 'severity', 'title', 'message', 'is_read', 'created_at']);

        return response()->json(['data' => $notifications, 'unread_count' => $unread]);
    }

    public function markNotificationRead(Request $request, OperationalNotification $operationalNotification): JsonResponse
    {
        $user = $request->user();
        abort_if(
            ($operationalNotification->target_user_id !== null && $operationalNotification->target_user_id !== $user->id)
            || ($operationalNotification->target_permission && ! $user->hasPermission($operationalNotification->target_permission)),
            404
        );
        $operationalNotification->update([
            'is_read' => true,
            'read_at' => now(),
            'read_by' => $request->user()->id,
        ]);

        return response()->json(['message' => 'Notification marked as read.', 'data' => $operationalNotification]);
    }
}
