<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuItemRecipe extends Model
{
    protected $fillable = [
        'menu_item_id',
        'menu_item_variant_id',
        'inventory_item_id',
        'quantity_required',
        'unit',
        'conversion_to_base',
        'waste_percentage',
        'version',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'menu_item_id' => 'integer',
            'menu_item_variant_id' => 'integer',
            'inventory_item_id' => 'integer',
            'quantity_required' => 'float',
            'conversion_to_base' => 'float',
            'waste_percentage' => 'float',
            'version' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(MenuItemVariant::class, 'menu_item_variant_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
