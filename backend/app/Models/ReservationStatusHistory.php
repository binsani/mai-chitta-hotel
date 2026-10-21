<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class ReservationStatusHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'reservation_id', 'from_status', 'to_status',
        'from_payment_status', 'to_payment_status',
        'reason', 'metadata', 'changed_by', 'created_at',
    ];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Reservation status history is immutable.'));
        static::deleting(fn () => throw new LogicException('Reservation status history is immutable.'));
    }

    protected function casts(): array
    {
        return [
            'reservation_id' => 'integer',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
