<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GuestFolio extends Model
{
    public const STATUS_OPEN = 'open';
    public const STATUS_SETTLED = 'settled';
    public const STATUS_CLOSED = 'closed';
    public const STATUS_VOIDED = 'voided';

    protected $fillable = [
        'folio_reference', 'reservation_id', 'guest_id', 'room_id', 'guest_name',
        'status', 'currency_code',
        'subtotal_charges_minor', 'taxes_minor', 'service_charges_minor',
        'total_charges_minor', 'total_credits_minor', 'total_payments_minor', 'total_refunds_minor', 'balance_due_minor',
        'total_charges_amount', 'total_credits_amount', 'total_payments_amount', 'total_refunds_amount', 'balance_due_amount',
        'notes', 'settled_with_exception', 'settlement_exception_reason',
        'settled_at', 'settled_by', 'closed_at', 'closed_by', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'reservation_id' => 'integer',
            'guest_id' => 'integer',
            'room_id' => 'integer',
            'subtotal_charges_minor' => 'integer',
            'taxes_minor' => 'integer',
            'service_charges_minor' => 'integer',
            'total_charges_minor' => 'integer',
            'total_credits_minor' => 'integer',
            'total_payments_minor' => 'integer',
            'total_refunds_minor' => 'integer',
            'balance_due_minor' => 'integer',
            'total_charges_amount' => 'float',
            'total_credits_amount' => 'float',
            'total_payments_amount' => 'float',
            'total_refunds_amount' => 'float',
            'balance_due_amount' => 'float',
            'settled_with_exception' => 'boolean',
            'settled_at' => 'datetime',
            'settled_by' => 'integer',
            'closed_at' => 'datetime',
            'closed_by' => 'integer',
            'created_by' => 'integer',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'guest_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(FolioEntry::class, 'guest_folio_id')->orderBy('service_date')->orderBy('id');
    }

    public function paymentAllocations(): HasMany
    {
        return $this->hasMany(FolioPaymentAllocation::class, 'guest_folio_id')->orderBy('id');
    }

    public function settler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'settled_by');
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }
}
