<?php

namespace App\Services;

use App\Models\PaymentRefund;
use App\Models\PaymentTransaction;
use App\Models\Reservation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Payment & Refund Service (Manual + Online Gateway Webhook Verification for Paystack & Flutterwave).
 *
 * Security Rules:
 * - Never trusts frontend payment redirects; online payments are only marked completed via verified webhook signatures
 *   and server-side amount/currency/reference validation.
 * - Duplicate webhook deliveries are handled idempotently via `webhook_event_id` and `provider_reference`.
 * - Manual payment corrections use audited reversal transactions rather than overwriting historical ledger rows.
 * - Refund approvals require `finance.refund` permission and are separated from routine front-desk payment recording.
 */
class PaymentService
{
    public function __construct(
        private ReservationService $reservations,
        private AuditLogger $audit,
    ) {}

    /**
     * Record an authorized manual payment (cash, bank_transfer, pos).
     */
    public function recordManualPayment(Reservation $reservation, array $data, User $actor): PaymentTransaction
    {
        return DB::transaction(function () use ($reservation, $data, $actor) {
            /** @var Reservation $res */
            $res = Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();

            $currency = strtoupper(trim((string) ($data['currency_code'] ?? $res->currency_code)));
            if ($currency !== strtoupper($res->currency_code)) {
                throw ValidationException::withMessages([
                    'currency_code' => ["Payment currency [{$currency}] must match reservation currency [{$res->currency_code}]."],
                ]);
            }

            $amountMinor = AvailabilityService::toMinor($data['amount'] ?? 0);
            if ($amountMinor <= 0) {
                throw ValidationException::withMessages([
                    'amount' => ['Payment amount must be greater than zero.'],
                ]);
            }

            $tx = PaymentTransaction::create([
                'transaction_reference' => 'PAY-'.now()->format('Ymd').'-'.strtoupper(Str::random(8)),
                'reservation_id' => $res->id,
                'type' => 'payment',
                'method' => $data['method'], // cash | bank_transfer | pos
                'provider' => 'manual',
                'provider_reference' => isset($data['provider_reference']) && filled($data['provider_reference'])
                    ? strip_tags((string) $data['provider_reference'])
                    : null,
                'currency_code' => $res->currency_code,
                'amount_minor' => $amountMinor,
                'amount' => AvailabilityService::toMajor($amountMinor),
                'status' => 'completed',
                'receipt_number' => isset($data['receipt_number']) && filled($data['receipt_number'])
                    ? strip_tags((string) $data['receipt_number'])
                    : ('RCP-'.strtoupper(Str::random(6))),
                'notes' => isset($data['notes']) ? strip_tags((string) $data['notes']) : null,
                'paid_at' => isset($data['paid_at']) && filled($data['paid_at']) ? $data['paid_at'] : now(),
                'recorded_by' => $actor->id,
            ]);

            $this->reservations->recalculatePaymentStatus($res);

            $this->audit->log('payment.manual_recorded', $tx, [
                'transaction_reference' => $tx->transaction_reference,
                'reservation_reference' => $res->reference,
                'method' => $tx->method,
                'amount_minor' => $tx->amount_minor,
                'currency_code' => $tx->currency_code,
            ], 'success', $actor->id);

            return $tx->load('recorder');
        }, 3);
    }

    /**
     * Reverse a manual payment via an append-only reversal transaction (never overwrites financial history).
     */
    public function reversePayment(PaymentTransaction $transaction, string $reason, User $actor): PaymentTransaction
    {
        return DB::transaction(function () use ($transaction, $reason, $actor) {
            /** @var PaymentTransaction $orig */
            $orig = PaymentTransaction::where('id', $transaction->id)->lockForUpdate()->firstOrFail();

            if ($orig->type !== 'payment' || $orig->status !== 'completed') {
                throw ValidationException::withMessages([
                    'transaction' => ['Only completed payment transactions can be reversed.'],
                ]);
            }

            if (PaymentTransaction::where('reversed_transaction_id', $orig->id)->exists()) {
                throw ValidationException::withMessages([
                    'transaction' => ['This payment transaction has already been reversed.'],
                ]);
            }

            if (PaymentRefund::where('payment_transaction_id', $orig->id)->whereIn('status', ['pending', 'approved', 'processed'])->exists()) {
                throw ValidationException::withMessages([
                    'transaction' => ['Cannot reverse a payment transaction that has an active or processed refund attached to it.'],
                ]);
            }

            /** @var Reservation $res */
            $res = Reservation::where('id', $orig->reservation_id)->lockForUpdate()->firstOrFail();

            $pendingRefundsMinor = (int) PaymentRefund::where('reservation_id', $res->id)
                ->whereIn('status', ['pending', 'approved'])
                ->sum('amount_minor');

            $availableNetPaidMinor = max(0, $res->amount_paid_minor - $res->amount_refunded_minor - $pendingRefundsMinor);
            if (abs($orig->amount_minor) > $availableNetPaidMinor) {
                throw ValidationException::withMessages([
                    'transaction' => ['Cannot reverse this payment because part or all of the reservation balance has already been refunded or is pending refund approval.'],
                ]);
            }

            $reversal = PaymentTransaction::create([
                'transaction_reference' => 'REV-'.now()->format('Ymd').'-'.strtoupper(Str::random(8)),
                'reservation_id' => $res->id,
                'type' => 'reversal',
                'method' => $orig->method,
                'provider' => $orig->provider,
                'currency_code' => $orig->currency_code,
                'amount_minor' => -abs($orig->amount_minor),
                'amount' => -abs($orig->amount),
                'status' => 'completed',
                'notes' => strip_tags($reason),
                'reversed_transaction_id' => $orig->id,
                'paid_at' => now(),
                'recorded_by' => $actor->id,
            ]);

            $this->reservations->recalculatePaymentStatus($res);

            $this->audit->log('payment.reversed', $reversal, [
                'reversal_reference' => $reversal->transaction_reference,
                'original_reference' => $orig->transaction_reference,
                'reservation_reference' => $res->reference,
                'amount_minor' => $reversal->amount_minor,
                'reason' => strip_tags($reason),
            ], 'success', $actor->id);

            return $reversal->load('recorder');
        }, 3);
    }

    /**
     * Request a refund on a reservation.
     */
    public function requestRefund(Reservation $reservation, array $data, User $actor): PaymentRefund
    {
        return DB::transaction(function () use ($reservation, $data, $actor) {
            /** @var Reservation $res */
            $res = Reservation::where('id', $reservation->id)->lockForUpdate()->firstOrFail();

            $amountMinor = AvailabilityService::toMinor($data['amount'] ?? 0);
            $pendingRefundsMinor = (int) PaymentRefund::where('reservation_id', $res->id)
                ->whereIn('status', ['pending', 'approved'])
                ->sum('amount_minor');
            $netPaidMinor = max(0, $res->amount_paid_minor - $res->amount_refunded_minor - $pendingRefundsMinor);

            if ($amountMinor <= 0) {
                throw ValidationException::withMessages(['amount' => ['Refund amount must be greater than zero.']]);
            }
            if ($amountMinor > $netPaidMinor) {
                throw ValidationException::withMessages([
                    'amount' => ['Refund amount cannot exceed the remaining net paid amount (less any pending refund requests) on this reservation.'],
                ]);
            }

            $refund = PaymentRefund::create([
                'refund_reference' => 'RFD-'.now()->format('Ymd').'-'.strtoupper(Str::random(8)),
                'reservation_id' => $res->id,
                'payment_transaction_id' => $data['payment_transaction_id'] ?? null,
                'currency_code' => $res->currency_code,
                'amount_minor' => $amountMinor,
                'amount' => AvailabilityService::toMajor($amountMinor),
                'reason' => strip_tags((string) $data['reason']),
                'status' => 'pending',
                'payout_method' => $data['payout_method'] ?? 'bank_transfer',
                'requested_by' => $actor->id,
            ]);

            $this->audit->log('payment.refund_requested', $refund, [
                'refund_reference' => $refund->refund_reference,
                'reservation_reference' => $res->reference,
                'amount_minor' => $refund->amount_minor,
            ], 'success', $actor->id);

            return $refund->load(['requester', 'reservation']);
        }, 3);
    }

    /**
     * Approve & process or reject a pending refund request (requires `finance.refund`).
     */
    public function reviewRefund(PaymentRefund $refund, array $data, User $actor): PaymentRefund
    {
        return DB::transaction(function () use ($refund, $data, $actor) {
            /** @var PaymentRefund $rfd */
            $rfd = PaymentRefund::where('id', $refund->id)->lockForUpdate()->firstOrFail();
            if ($rfd->status !== 'pending') {
                throw ValidationException::withMessages([
                    'status' => ["Refund {$rfd->refund_reference} has already been {$rfd->status}."],
                ]);
            }

            /** @var Reservation $res */
            $res = Reservation::where('id', $rfd->reservation_id)->lockForUpdate()->firstOrFail();
            $decision = $data['decision']; // approve | reject

            if ($decision === 'reject') {
                $rfd->update([
                    'status' => 'rejected',
                    'review_notes' => isset($data['review_notes']) ? strip_tags((string) $data['review_notes']) : null,
                    'reviewed_by' => $actor->id,
                    'reviewed_at' => now(),
                ]);

                $this->audit->log('payment.refund_rejected', $rfd, [
                    'refund_reference' => $rfd->refund_reference,
                    'reservation_reference' => $res->reference,
                ], 'success', $actor->id);

                return $rfd->fresh(['requester', 'reviewer', 'reservation']);
            }

            $netPaidMinor = max(0, $res->amount_paid_minor - $res->amount_refunded_minor);
            if ($rfd->amount_minor > $netPaidMinor) {
                throw ValidationException::withMessages([
                    'amount' => ['Refund amount exceeds current net paid balance on the reservation.'],
                ]);
            }

            $payoutTx = PaymentTransaction::create([
                'transaction_reference' => 'RFDPAY-'.now()->format('Ymd').'-'.strtoupper(Str::random(7)),
                'reservation_id' => $res->id,
                'type' => 'refund_payout',
                'method' => $data['payout_method'] ?? $rfd->payout_method ?? 'bank_transfer',
                'provider' => 'manual',
                'provider_reference' => isset($data['payout_reference']) ? strip_tags((string) $data['payout_reference']) : null,
                'currency_code' => $res->currency_code,
                'amount_minor' => -abs($rfd->amount_minor),
                'amount' => -abs($rfd->amount),
                'status' => 'completed',
                'notes' => "Refund payout for {$rfd->refund_reference}: {$rfd->reason}",
                'paid_at' => now(),
                'recorded_by' => $actor->id,
            ]);

            $rfd->update([
                'status' => 'processed',
                'payout_transaction_id' => $payoutTx->id,
                'payout_method' => $payoutTx->method,
                'payout_reference' => $payoutTx->provider_reference,
                'review_notes' => isset($data['review_notes']) ? strip_tags((string) $data['review_notes']) : null,
                'reviewed_by' => $actor->id,
                'reviewed_at' => now(),
                'processed_at' => now(),
            ]);

            $this->reservations->recalculatePaymentStatus($res);

            $this->audit->log('payment.refund_approved', $rfd, [
                'refund_reference' => $rfd->refund_reference,
                'reservation_reference' => $res->reference,
                'amount_minor' => $rfd->amount_minor,
                'payout_transaction_reference' => $payoutTx->transaction_reference,
            ], 'success', $actor->id);

            return $rfd->fresh(['requester', 'reviewer', 'reservation']);
        }, 3);
    }

    /**
     * Handle and verify online payment webhooks (Paystack or Flutterwave v3) idempotently.
     *
     * Paystack scheme:
     * - HMAC-SHA512 over raw request body using `PAYSTACK_SECRET_KEY` (`config('services.paystack.secret_key')`) in `x-paystack-signature`.
     * - Server-side verification via `GET {base_url}/transaction/verify/{reference}`.
     *
     * Flutterwave v3 scheme:
     * - Constant-time `hash_equals` check of `verif-hash` header against `FLUTTERWAVE_WEBHOOK_SECRET_HASH` (`config('services.flutterwave.webhook_secret_hash')`).
     * - Server-side verification via `GET {base_url}/transactions/{id}/verify` with `FLUTTERWAVE_SECRET_KEY`.
     */
    public function handleWebhook(string $provider, string $rawPayload, ?string $signatureHeader, array $payload): array
    {
        $provider = strtolower($provider);
        $this->verifyWebhookSignature($provider, $rawPayload, $signatureHeader);

        if ($provider === 'paystack') {
            $event = (string) ($payload['event'] ?? '');
            $data = (array) ($payload['data'] ?? []);
            $providerRef = (string) ($data['reference'] ?? '');
            $eventId = 'paystack:'.($data['id'] ?? $providerRef ?: Str::uuid());
            $reservationRef = (string) ($data['metadata']['reservation_reference'] ?? $providerRef);
            $amountMinor = (int) ($data['amount'] ?? 0); // Paystack sends kobo
            $currency = strtoupper((string) ($data['currency'] ?? 'NGN'));
            $isSuccess = $event === 'charge.success' && ($data['status'] ?? '') === 'success';
            $isFailure = str_starts_with($event, 'charge.') && ($data['status'] ?? '') === 'failed';

            if ($isSuccess) {
                $verified = $this->verifyPaystackTransactionWithProvider($providerRef);
                $isSuccess = ($verified['status'] ?? '') === 'success';
                $isFailure = ($verified['status'] ?? '') === 'failed';
                $amountMinor = (int) ($verified['amount'] ?? $amountMinor);
                $currency = strtoupper((string) ($verified['currency'] ?? $currency));
                if (! empty($verified['reference']) && $verified['reference'] !== $providerRef) {
                    throw ValidationException::withMessages([
                        'reference' => ['Verified Paystack transaction reference does not match webhook reference.'],
                    ]);
                }
                if (! empty($verified['metadata']['reservation_reference'])) {
                    $verifiedResRef = (string) $verified['metadata']['reservation_reference'];
                    if ($reservationRef !== '' && $verifiedResRef !== $reservationRef) {
                        throw ValidationException::withMessages([
                            'reservation' => ['Verified Paystack reservation reference does not match webhook payload.'],
                        ]);
                    }
                    $reservationRef = $verifiedResRef;
                }
            }
        } elseif ($provider === 'flutterwave') {
            $event = (string) ($payload['event'] ?? '');
            $data = (array) ($payload['data'] ?? []);
            $txId = $data['id'] ?? null;
            $providerRef = (string) ($data['tx_ref'] ?? '');
            $eventId = 'flutterwave:'.($txId ?? $providerRef ?: Str::uuid());
            $reservationRef = (string) ($data['meta']['reservation_reference'] ?? $providerRef);
            $amountMinor = AvailabilityService::toMinor($data['amount'] ?? 0);
            $currency = strtoupper((string) ($data['currency'] ?? 'NGN'));
            $isSuccess = $event === 'charge.completed' && ($data['status'] ?? '') === 'successful';
            $isFailure = $event === 'charge.completed' && ($data['status'] ?? '') === 'failed';

            if ($isSuccess) {
                if ($txId === null || $txId === '') {
                    throw ValidationException::withMessages([
                        'provider_verification' => ['Flutterwave transaction ID is required for verification.'],
                    ]);
                }
                $verified = $this->verifyFlutterwaveTransactionWithProvider((string) $txId);
                $isSuccess = ($verified['status'] ?? '') === 'successful';
                $isFailure = ($verified['status'] ?? '') === 'failed';
                $amountMinor = AvailabilityService::toMinor($verified['amount'] ?? 0);
                $currency = strtoupper((string) ($verified['currency'] ?? $currency));
                if (! empty($verified['tx_ref']) && $verified['tx_ref'] !== $providerRef) {
                    throw ValidationException::withMessages([
                        'reference' => ['Verified Flutterwave tx_ref does not match webhook tx_ref.'],
                    ]);
                }
                if (! empty($verified['meta']['reservation_reference'])) {
                    $verifiedResRef = (string) $verified['meta']['reservation_reference'];
                    if ($reservationRef !== '' && $verifiedResRef !== $reservationRef) {
                        throw ValidationException::withMessages([
                            'reservation' => ['Verified Flutterwave reservation reference does not match webhook payload.'],
                        ]);
                    }
                    $reservationRef = $verifiedResRef;
                }
            }
        } else {
            throw ValidationException::withMessages(['provider' => ['Unsupported payment provider.']]);
        }

        if (! $providerRef || ! $reservationRef) {
            throw ValidationException::withMessages(['reference' => ['Missing provider or reservation reference in webhook payload.']]);
        }

        // Cross-reservation reuse check: a provider transaction reference or event ID must never be reused for a different reservation
        $existingForDifferentReservation = PaymentTransaction::where(function ($q) use ($eventId, $provider, $providerRef) {
            $q->where('webhook_event_id', $eventId)
                ->orWhere(fn ($sq) => $sq->where('provider', $provider)->where('provider_reference', $providerRef));
        })->with('reservation:id,reference')->first();

        if ($existingForDifferentReservation && $existingForDifferentReservation->reservation && $existingForDifferentReservation->reservation->reference !== $reservationRef) {
            throw ValidationException::withMessages([
                'reference' => ["Provider transaction [{$providerRef}] is already associated with another reservation."],
            ]);
        }

        // Idempotency check: if we already processed this exact webhook event or provider_reference as completed, return early
        $existingEvent = PaymentTransaction::where('webhook_event_id', $eventId)
            ->orWhere(fn ($q) => $q->where('provider', $provider)->where('provider_reference', $providerRef)->where('status', 'completed'))
            ->first();

        if ($existingEvent) {
            return ['status' => 'duplicate_ignored', 'transaction_reference' => $existingEvent->transaction_reference];
        }

        return DB::transaction(function () use ($provider, $providerRef, $eventId, $reservationRef, $amountMinor, $currency, $isSuccess, $isFailure, $data) {
            /** @var Reservation|null $res */
            $res = Reservation::where('reference', $reservationRef)->lockForUpdate()->first();
            if (! $res) {
                throw ValidationException::withMessages(['reservation' => ["Reservation [{$reservationRef}] not found."]]);
            }

            // Re-check inside lock against concurrent duplicate delivery or cross-reservation reuse
            $lockedDuplicate = PaymentTransaction::where('webhook_event_id', $eventId)
                ->orWhere(fn ($q) => $q->where('provider', $provider)->where('provider_reference', $providerRef))
                ->lockForUpdate()
                ->first();

            if ($lockedDuplicate) {
                if ($lockedDuplicate->reservation_id !== $res->id) {
                    throw ValidationException::withMessages([
                        'reference' => ["Provider transaction [{$providerRef}] is already associated with another reservation."],
                    ]);
                }
                if ($lockedDuplicate->webhook_event_id === $eventId || $lockedDuplicate->status === 'completed') {
                    return ['status' => 'duplicate_ignored', 'transaction_reference' => $lockedDuplicate->transaction_reference];
                }
            }

            if ($currency !== strtoupper($res->currency_code)) {
                throw ValidationException::withMessages([
                    'currency' => ["Webhook currency [{$currency}] does not match reservation currency [{$res->currency_code}]."],
                ]);
            }

            if ($isSuccess && $amountMinor < $res->total_minor) {
                throw ValidationException::withMessages([
                    'amount' => ["Paid amount ({$amountMinor}) is less than expected reservation total ({$res->total_minor})."],
                ]);
            }

            $redactedPayload = [
                'id' => $data['id'] ?? null,
                'status' => $data['status'] ?? null,
                'channel' => $data['channel'] ?? ($data['payment_type'] ?? null),
                'paid_at' => $data['paid_at'] ?? ($data['created_at'] ?? null),
            ];

            $status = $isSuccess ? 'completed' : ($isFailure ? 'failed' : 'pending');

            $tx = PaymentTransaction::create([
                'transaction_reference' => 'ONL-'.now()->format('Ymd').'-'.strtoupper(Str::random(8)),
                'reservation_id' => $res->id,
                'type' => 'payment',
                'method' => "online_{$provider}",
                'provider' => $provider,
                'provider_reference' => $providerRef,
                'webhook_event_id' => $eventId,
                'currency_code' => $currency,
                'amount_minor' => $amountMinor,
                'amount' => AvailabilityService::toMajor($amountMinor),
                'status' => $status,
                'receipt_number' => 'ONLRCP-'.strtoupper(Str::random(6)),
                'provider_payload' => $redactedPayload,
                'paid_at' => $isSuccess ? now() : null,
            ]);

            $this->reservations->recalculatePaymentStatus($res);

            $this->audit->log("payment.webhook_{$status}", $tx, [
                'provider' => $provider,
                'provider_reference' => $providerRef,
                'reservation_reference' => $res->reference,
                'reservation_status' => $res->fresh()->status,
                'amount_minor' => $amountMinor,
            ]);

            return [
                'status' => $status,
                'transaction_reference' => $tx->transaction_reference,
                'reservation_status' => $res->fresh()->status,
                'payment_status' => $res->fresh()->payment_status,
            ];
        }, 3);
    }

    private function verifyWebhookSignature(string $provider, string $rawPayload, ?string $signatureHeader): void
    {
        if ($provider === 'paystack') {
            $secret = (string) config('services.paystack.secret_key');
            if ($secret === '') {
                abort(503, 'Paystack secret key is not configured.');
            }
            $expected = hash_hmac('sha512', $rawPayload, $secret);
            if (! $signatureHeader || ! hash_equals($expected, $signatureHeader)) {
                abort(401, 'Invalid Paystack webhook signature.');
            }

            return;
        }

        if ($provider === 'flutterwave') {
            $secretHash = (string) config('services.flutterwave.webhook_secret_hash');
            if ($secretHash === '') {
                abort(503, 'Flutterwave webhook secret hash is not configured.');
            }
            if (! $signatureHeader || ! hash_equals($secretHash, $signatureHeader)) {
                abort(401, 'Invalid Flutterwave webhook signature.');
            }
        }
    }

    /**
     * Verify transaction against Paystack's official `GET /transaction/verify/{reference}` endpoint.
     * Fails closed on HTTP errors, timeouts, connection errors, or malformed JSON.
     */
    private function verifyPaystackTransactionWithProvider(string $reference): array
    {
        $secretKey = (string) config('services.paystack.secret_key');
        if ($secretKey === '') {
            throw ValidationException::withMessages([
                'provider_verification' => ['Paystack secret key is required to verify transactions.'],
            ]);
        }
        $baseUrl = rtrim((string) config('services.paystack.base_url', 'https://api.paystack.co'), '/');

        try {
            $response = Http::withToken($secretKey)
                ->timeout(10)
                ->acceptJson()
                ->get("{$baseUrl}/transaction/verify/".urlencode($reference));
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'provider_verification' => ['Unable to reach Paystack verification API to confirm transaction.'],
            ]);
        }

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'provider_verification' => ["Paystack transaction verification failed with HTTP {$response->status()}."],
            ]);
        }

        $body = $response->json();
        if (! is_array($body) || ($body['status'] ?? false) !== true || ! isset($body['data']) || ! is_array($body['data'])) {
            throw ValidationException::withMessages([
                'provider_verification' => ['Paystack verification API returned an invalid or unverified response.'],
            ]);
        }

        return $body['data'];
    }

    /**
     * Verify transaction against Flutterwave v3's official `GET /transactions/{id}/verify` endpoint.
     * Fails closed on HTTP errors, timeouts, connection errors, or malformed JSON.
     */
    private function verifyFlutterwaveTransactionWithProvider(string $transactionId): array
    {
        $secretKey = (string) config('services.flutterwave.secret_key');
        if ($secretKey === '') {
            throw ValidationException::withMessages([
                'provider_verification' => ['Flutterwave secret key is required to verify transactions.'],
            ]);
        }
        $baseUrl = rtrim((string) config('services.flutterwave.base_url', 'https://api.flutterwave.com/v3'), '/');

        try {
            $response = Http::withToken($secretKey)
                ->timeout(10)
                ->acceptJson()
                ->get("{$baseUrl}/transactions/".urlencode($transactionId).'/verify');
        } catch (\Throwable) {
            throw ValidationException::withMessages([
                'provider_verification' => ['Unable to reach Flutterwave verification API to confirm transaction.'],
            ]);
        }

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'provider_verification' => ["Flutterwave transaction verification failed with HTTP {$response->status()}."],
            ]);
        }

        $body = $response->json();
        if (! is_array($body) || ($body['status'] ?? '') !== 'success' || ! isset($body['data']) || ! is_array($body['data'])) {
            throw ValidationException::withMessages([
                'provider_verification' => ['Flutterwave verification API returned an invalid or unverified response.'],
            ]);
        }

        return $body['data'];
    }
}
