<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\Reservation;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class PaymentController extends Controller
{
    public function __construct(
        private PaymentService $payments,
    ) {}

    public function index(Request $request): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['payments.view', 'finance.view'])) {
            abort(403, 'You do not have permission to view payment records.');
        }

        $txs = PaymentTransaction::query()
            ->with(['reservation:id,reference,guest_name,total_amount,currency_code,status,payment_status', 'recorder:id,name'])
            ->when($request->filled('reservation_id'), fn ($q) => $q->where('reservation_id', (int) $request->query('reservation_id')))
            ->when($request->filled('method'), fn ($q) => $q->where('method', (string) $request->query('method')))
            ->when($request->filled('type'), fn ($q) => $q->where('type', (string) $request->query('type')))
            ->orderByDesc('id')
            ->paginate(25);

        return response()->json($txs);
    }

    public function storeManualPayment(Request $request, Reservation $reservation): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['payments.record', 'finance.record'])) {
            abort(403, 'You do not have permission to record manual payments.');
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'currency_code' => ['nullable', 'string', 'max:8'],
            'method' => ['required', 'string', Rule::in(['cash', 'bank_transfer', 'pos'])],
            'provider_reference' => ['nullable', 'string', 'max:120'],
            'receipt_number' => ['nullable', 'string', 'max:64'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'paid_at' => ['nullable', 'date'],
        ]);

        $tx = $this->payments->recordManualPayment($reservation, $data, $request->user());

        return response()->json([
            'message' => 'Payment recorded.',
            'data' => $tx,
            'reservation' => $reservation->fresh(),
        ], 201);
    }

    public function reversePayment(Request $request, PaymentTransaction $paymentTransaction): JsonResponse
    {
        // Only finance.approve or finance.refund may reverse a recorded historical payment
        if (! $request->user()->hasAnyPermission(['finance.approve', 'finance.refund'])) {
            abort(403, 'Only authorized finance officers or management may reverse historical payment transactions.');
        }

        $data = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $reversal = $this->payments->reversePayment($paymentTransaction, $data['reason'], $request->user());

        return response()->json([
            'message' => 'Payment reversal recorded.',
            'data' => $reversal,
        ], 201);
    }

    public function refunds(Request $request): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['payments.view', 'finance.view', 'finance.refund'])) {
            abort(403, 'You do not have permission to view refunds.');
        }

        $refunds = PaymentRefund::query()
            ->with(['reservation:id,reference,guest_name,total_amount,amount_paid,currency_code', 'requester:id,name', 'reviewer:id,name'])
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->query('status')))
            ->orderByDesc('id')
            ->paginate(25);

        return response()->json($refunds);
    }

    public function requestRefund(Request $request, Reservation $reservation): JsonResponse
    {
        if (! $request->user()->hasAnyPermission(['payments.record', 'finance.record', 'finance.refund', 'reservations.cancel'])) {
            abort(403, 'You do not have permission to request a refund.');
        }

        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0', 'max:99999999'],
            'payment_transaction_id' => ['nullable', 'integer', 'exists:payment_transactions,id'],
            'payout_method' => ['nullable', 'string', Rule::in(['cash', 'bank_transfer', 'pos', 'provider_refund'])],
            'reason' => ['required', 'string', 'min:3', 'max:255'],
        ]);

        $refund = $this->payments->requestRefund($reservation, $data, $request->user());

        return response()->json([
            'message' => 'Refund request submitted for approval.',
            'data' => $refund,
        ], 201);
    }

    public function reviewRefund(Request $request, PaymentRefund $paymentRefund): JsonResponse
    {
        Gate::authorize('finance.refund');

        $data = $request->validate([
            'decision' => ['required', 'string', Rule::in(['approve', 'reject'])],
            'payout_method' => ['nullable', 'string', Rule::in(['cash', 'bank_transfer', 'pos', 'provider_refund'])],
            'payout_reference' => ['nullable', 'string', 'max:120'],
            'review_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $updated = $this->payments->reviewRefund($paymentRefund, $data, $request->user());

        return response()->json([
            'message' => $data['decision'] === 'approve' ? 'Refund approved and processed.' : 'Refund request rejected.',
            'data' => $updated,
        ]);
    }
}
