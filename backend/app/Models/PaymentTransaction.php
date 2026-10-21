<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only financial ledger record.
 * Completed, failed, or reversed payment transactions are immutable and can never be updated or deleted.
 */
class PaymentTransaction extends Model
{
    protected $fillable = [
        'transaction_reference', 'reservation_id', 'type', 'method',
        'provider', 'provider_reference', 'webhook_event_id',
        'currency_code', 'amount_minor', 'amount', 'status',
        'receipt_number', 'notes', 'provider_payload',
        'reversed_transaction_id', 'paid_at', 'recorded_by',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $tx): void {
            throw new LogicException('Financial payment transactions are immutable. Record a reversal or refund transaction instead.');
        });

        static::deleting(function (self $tx): void {
            throw new LogicException('Financial payment transactions cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'reservation_id' => 'integer',
            'amount_minor' => 'integer',
            'amount' => 'float',
            'provider_payload' => 'array',
            'reversed_transaction_id' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function reversedTransaction(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_transaction_id');
    }
}
