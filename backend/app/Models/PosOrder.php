<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosOrder extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_SUBMITTED = 'submitted';
    public const STATUS_PREPARING = 'preparing';
    public const STATUS_READY = 'ready';
    public const STATUS_OUT_FOR_DELIVERY = 'out_for_delivery';
    public const STATUS_SERVED = 'served';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_VOIDED = 'voided';

    public const PAYMENT_UNPAID = 'unpaid';
    public const PAYMENT_PARTIALLY_PAID = 'partially_paid';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_CHARGED_TO_ROOM = 'charged_to_room';
    public const PAYMENT_REFUNDED = 'refunded';
    public const PAYMENT_VOIDED = 'voided';

    protected $fillable = [
        'order_reference',
        'idempotency_key',
        'pos_outlet_id',
        'order_type',
        'table_or_location',
        'covers',
        'reservation_id',
        'guest_folio_id',
        'room_id',
        'guest_name_snapshot',
        'delivery_instructions',
        'status',
        'payment_status',
        'settlement_method',
        'currency_code',
        'subtotal_minor',
        'discount_type',
        'discount_value',
        'discount_minor',
        'discount_reason',
        'discount_approved_by',
        'tax_percentage_snapshot',
        'tax_minor',
        'service_charge_percentage_snapshot',
        'service_charge_minor',
        'total_minor',
        'amount_paid_minor',
        'amount_refunded_minor',
        'balance_due_minor',
        'total_amount',
        'amount_paid',
        'balance_due_amount',
        'stock_consumed',
        'stock_consumed_at',
        'folio_entry_id',
        'room_charged_at',
        'special_instructions',
        'void_reason',
        'created_by',
        'submitted_by',
        'submitted_at',
        'preparing_at',
        'ready_at',
        'out_for_delivery_at',
        'served_by',
        'served_at',
        'delivered_by',
        'delivered_at',
        'voided_by',
        'voided_at',
    ];

    protected function casts(): array
    {
        return [
            'pos_outlet_id' => 'integer',
            'covers' => 'integer',
            'reservation_id' => 'integer',
            'guest_folio_id' => 'integer',
            'room_id' => 'integer',
            'subtotal_minor' => 'integer',
            'discount_value' => 'float',
            'discount_minor' => 'integer',
            'discount_approved_by' => 'integer',
            'tax_percentage_snapshot' => 'float',
            'tax_minor' => 'integer',
            'service_charge_percentage_snapshot' => 'float',
            'service_charge_minor' => 'integer',
            'total_minor' => 'integer',
            'amount_paid_minor' => 'integer',
            'amount_refunded_minor' => 'integer',
            'balance_due_minor' => 'integer',
            'total_amount' => 'float',
            'amount_paid' => 'float',
            'balance_due_amount' => 'float',
            'stock_consumed' => 'boolean',
            'stock_consumed_at' => 'datetime',
            'folio_entry_id' => 'integer',
            'room_charged_at' => 'datetime',
            'created_by' => 'integer',
            'submitted_by' => 'integer',
            'submitted_at' => 'datetime',
            'preparing_at' => 'datetime',
            'ready_at' => 'datetime',
            'out_for_delivery_at' => 'datetime',
            'served_by' => 'integer',
            'served_at' => 'datetime',
            'delivered_by' => 'integer',
            'delivered_at' => 'datetime',
            'voided_by' => 'integer',
            'voided_at' => 'datetime',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(PosOutlet::class, 'pos_outlet_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    public function guestFolio(): BelongsTo
    {
        return $this->belongsTo(GuestFolio::class, 'guest_folio_id');
    }

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'room_id');
    }

    public function folioEntry(): BelongsTo
    {
        return $this->belongsTo(FolioEntry::class, 'folio_entry_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PosOrderItem::class, 'pos_order_id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(PosOrderHistory::class, 'pos_order_id')->orderByDesc('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PosPayment::class, 'pos_order_id')->orderByDesc('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(User::class, 'served_by');
    }

    public function deliverer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'delivered_by');
    }

    public function voider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'voided_by');
    }
}
