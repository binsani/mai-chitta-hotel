<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosOrderHistory extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'pos_order_id',
        'from_status',
        'to_status',
        'from_payment_status',
        'to_payment_status',
        'notes',
        'metadata',
        'changed_by',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'pos_order_id' => 'integer',
            'metadata' => 'array',
            'changed_by' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
