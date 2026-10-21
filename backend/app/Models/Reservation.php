<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Reservation extends Model
{
    public const STATUS_PENDING_PAYMENT = 'pending_payment';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_CHECKED_IN = 'checked_in';
    public const STATUS_CHECKED_OUT = 'checked_out';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_NO_SHOW = 'no_show';
    public const STATUS_EXPIRED = 'expired';

    public const PAYMENT_UNPAID = 'unpaid';
    public const PAYMENT_PARTIALLY_PAID = 'partially_paid';
    public const PAYMENT_PAID = 'paid';
    public const PAYMENT_PARTIALLY_REFUNDED = 'partially_refunded';
    public const PAYMENT_REFUNDED = 'refunded';
    public const PAYMENT_FAILED = 'payment_failed';

    /** Statuses that actively hold room inventory. */
    public const INVENTORY_CONSUMING_STATUSES = [
        self::STATUS_PENDING_PAYMENT,
        self::STATUS_CONFIRMED,
        self::STATUS_CHECKED_IN,
    ];

    protected $fillable = [
        'reference', 'public_access_token', 'idempotency_key',
        'booking_hold_id', 'guest_id', 'room_type_id', 'rate_plan_id', 'assigned_room_id',
        'guest_name', 'guest_email', 'guest_phone',
        'check_in_date', 'check_out_date', 'nights', 'rooms_count', 'adults', 'children',
        'source', 'status', 'payment_status', 'currency_code',
        'subtotal_minor', 'taxes_minor', 'fees_minor', 'discount_minor', 'total_minor',
        'amount_paid_minor', 'amount_refunded_minor', 'cancellation_fee_minor',
        'subtotal_amount', 'taxes_amount', 'fees_amount', 'total_amount',
        'amount_paid', 'amount_refunded',
        'price_snapshot', 'cancellation_policy_snapshot',
        'special_requests', 'internal_notes',
        'cancellation_reason', 'no_show_reason',
        'confirmed_at', 'checked_in_at', 'checked_out_at', 'cancelled_at', 'no_show_at',
        'created_by', 'updated_by', 'checked_in_by', 'checked_out_by', 'cancelled_by',
    ];

    protected $hidden = [
        'public_access_token',
        'internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'booking_hold_id' => 'integer',
            'guest_id' => 'integer',
            'room_type_id' => 'integer',
            'rate_plan_id' => 'integer',
            'assigned_room_id' => 'integer',
            'check_in_date' => 'date:Y-m-d',
            'check_out_date' => 'date:Y-m-d',
            'nights' => 'integer',
            'rooms_count' => 'integer',
            'adults' => 'integer',
            'children' => 'integer',
            'subtotal_minor' => 'integer',
            'taxes_minor' => 'integer',
            'fees_minor' => 'integer',
            'discount_minor' => 'integer',
            'total_minor' => 'integer',
            'amount_paid_minor' => 'integer',
            'amount_refunded_minor' => 'integer',
            'cancellation_fee_minor' => 'integer',
            'subtotal_amount' => 'float',
            'taxes_amount' => 'float',
            'fees_amount' => 'float',
            'total_amount' => 'float',
            'amount_paid' => 'float',
            'amount_refunded' => 'float',
            'price_snapshot' => 'array',
            'cancellation_policy_snapshot' => 'array',
            'confirmed_at' => 'datetime',
            'checked_in_at' => 'datetime',
            'checked_out_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'no_show_at' => 'datetime',
        ];
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class, 'guest_id');
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }

    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class, 'rate_plan_id');
    }

    public function assignedRoom(): BelongsTo
    {
        return $this->belongsTo(Room::class, 'assigned_room_id');
    }

    public function room(): BelongsTo
    {
        return $this->assignedRoom();
    }

    public function bookingHold(): BelongsTo
    {
        return $this->belongsTo(BookingHold::class, 'booking_hold_id');
    }

    public function nightsAllocations(): HasMany
    {
        return $this->hasMany(ReservationNight::class, 'reservation_id')->orderBy('stay_date')->orderBy('unit_index');
    }

    public function statusHistories(): HasMany
    {
        return $this->hasMany(ReservationStatusHistory::class, 'reservation_id')->orderByDesc('id');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class, 'reservation_id')->orderByDesc('id');
    }

    public function refunds(): HasMany
    {
        return $this->hasMany(PaymentRefund::class, 'reservation_id')->orderByDesc('id');
    }

    public function folio(): HasOne
    {
        return $this->hasOne(GuestFolio::class, 'reservation_id');
    }

    public function housekeepingTasks(): HasMany
    {
        return $this->hasMany(HousekeepingTask::class, 'reservation_id')->orderByDesc('id');
    }

    public function balanceDueMinor(): int
    {
        $netPaid = max(0, $this->amount_paid_minor - $this->amount_refunded_minor);
        $obligation = in_array($this->status, [self::STATUS_CANCELLED, self::STATUS_NO_SHOW], true)
            ? $this->cancellation_fee_minor
            : $this->total_minor;

        return max(0, $obligation - $netPaid);
    }
}
