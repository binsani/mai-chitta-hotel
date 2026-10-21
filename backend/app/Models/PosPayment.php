<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class PosPayment extends Model
{
    protected $fillable = [
        'payment_reference',
        'idempotency_key',
        'pos_order_id',
        'pos_outlet_id',
        'type',
        'method',
        'currency_code',
        'amount_minor',
        'amount',
        'status',
        'receipt_number',
        'external_reference',
        'reversed_payment_id',
        'notes',
        'recorded_by',
        'approved_by',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'pos_order_id' => 'integer',
            'pos_outlet_id' => 'integer',
            'amount_minor' => 'integer',
            'amount' => 'float',
            'reversed_payment_id' => 'integer',
            'recorded_by' => 'integer',
            'approved_by' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('POS payment ledger rows are append-only and immutable.');
        });

        static::deleting(function () {
            throw new LogicException('POS payment ledger rows are append-only and cannot be deleted.');
        });
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(PosOutlet::class, 'pos_outlet_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function reversedPayment(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_payment_id');
    }
}
