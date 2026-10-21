<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosPrepStation extends Model
{
    protected $fillable = [
        'pos_outlet_id',
        'code',
        'name',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'pos_outlet_id' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(PosOutlet::class, 'pos_outlet_id');
    }
}
