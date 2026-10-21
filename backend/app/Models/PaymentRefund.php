<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentRefund extends Model
{
    protected $fillable = [
        'refund_reference', 'reservation_id', 'payment_transaction_id',
        'payout_transaction_id', 'currency_code', 'amount_minor', 'amount',
        'reason', 'status', 'payout_method', 'payout_reference',
        'review_notes', 'requested_by', 'reviewed_by',
        'reviewed_at', 'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'reservation_id' => 'integer',
            'payment_transaction_id' => 'integer',
            'payout_transaction_id' => 'integer',
            'amount_minor' => 'integer',
            'amount' => 'float',
            'reviewed_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
