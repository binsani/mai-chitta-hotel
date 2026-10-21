<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuItemModifier extends Model
{
    protected $fillable = [
        'menu_item_id',
        'name',
        'price_minor',
        'price',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'menu_item_id' => 'integer',
            'price_minor' => 'integer',
            'price' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }
}
