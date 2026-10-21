<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BookingHold extends Model
{
    protected $fillable = [
        'hold_token', 'idempotency_key', 'room_type_id', 'rate_plan_id',
        'check_in_date', 'check_out_date', 'nights', 'rooms_count',
        'adults', 'children', 'currency_code',
        'subtotal_minor', 'taxes_minor', 'fees_minor', 'total_minor', 'total_amount',
        'quote_snapshot', 'guest_name', 'guest_email', 'guest_phone',
        'status', 'expires_at', 'ip_address',
    ];

    protected function casts(): array
    {
        return [
            'room_type_id' => 'integer',
            'rate_plan_id' => 'integer',
            'check_in_date' => 'date:Y-m-d',
            'check_out_date' => 'date:Y-m-d',
            'nights' => 'integer',
            'rooms_count' => 'integer',
            'adults' => 'integer',
            'children' => 'integer',
            'subtotal_minor' => 'integer',
            'taxes_minor' => 'integer',
            'fees_minor' => 'integer',
            'total_minor' => 'integer',
            'total_amount' => 'float',
            'quote_snapshot' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function scopeActiveUnexpired(Builder $query): Builder
    {
        return $query->where('status', 'active')->where('expires_at', '>', now());
    }

    public function isExpired(): bool
    {
        return $this->status !== 'active' || ($this->expires_at && $this->expires_at->isPast());
    }

    public function roomType(): BelongsTo
    {
        return $this->belongsTo(RoomType::class, 'room_type_id');
    }

    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class, 'rate_plan_id');
    }
}
