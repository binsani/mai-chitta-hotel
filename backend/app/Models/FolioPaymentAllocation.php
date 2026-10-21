<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only payment allocation linking a verified PaymentTransaction to a GuestFolio.
 */
class FolioPaymentAllocation extends Model
{
    protected $fillable = [
        'allocation_reference', 'guest_folio_id', 'reservation_id', 'payment_transaction_id',
        'allocation_type', 'currency_code',
        'allocated_amount_minor', 'allocated_amount',
        'notes', 'allocated_by', 'allocated_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new LogicException('Folio payment allocations are immutable. Record a reversal allocation instead.');
        });

        static::deleting(function (): void {
            throw new LogicException('Folio payment allocations cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'guest_folio_id' => 'integer',
            'reservation_id' => 'integer',
            'payment_transaction_id' => 'integer',
            'allocated_amount_minor' => 'integer',
            'allocated_amount' => 'float',
            'allocated_by' => 'integer',
            'allocated_at' => 'datetime',
        ];
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(GuestFolio::class, 'guest_folio_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    public function paymentTransaction(): BelongsTo
    {
        return $this->belongsTo(PaymentTransaction::class, 'payment_transaction_id');
    }

    public function allocator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'allocated_by');
    }
}
