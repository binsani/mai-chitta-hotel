<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuItemVariant extends Model
{
    protected $fillable = [
        'menu_item_id',
        'code',
        'name',
        'price_minor',
        'price',
        'recipe_multiplier',
        'is_default',
        'is_active',
        'is_sold_out',
    ];

    protected function casts(): array
    {
        return [
            'menu_item_id' => 'integer',
            'price_minor' => 'integer',
            'price' => 'float',
            'recipe_multiplier' => 'float',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
            'is_sold_out' => 'boolean',
        ];
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }
}
