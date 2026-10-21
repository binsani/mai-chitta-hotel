<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class MenuItem extends Model
{
    protected $fillable = [
        'sku',
        'pos_outlet_id',
        'menu_category_id',
        'pos_prep_station_id',
        'name',
        'description',
        'currency_code',
        'price_minor',
        'price',
        'is_active',
        'is_available',
        'is_sold_out',
        'is_room_service_eligible',
        'stock_tracking_policy',
        'direct_inventory_item_id',
        'direct_deduction_quantity',
        'tax_percentage',
        'service_charge_percentage',
        'sort_order',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'pos_outlet_id' => 'integer',
            'menu_category_id' => 'integer',
            'pos_prep_station_id' => 'integer',
            'price_minor' => 'integer',
            'price' => 'float',
            'is_active' => 'boolean',
            'is_available' => 'boolean',
            'is_sold_out' => 'boolean',
            'is_room_service_eligible' => 'boolean',
            'direct_inventory_item_id' => 'integer',
            'direct_deduction_quantity' => 'float',
            'tax_percentage' => 'float',
            'service_charge_percentage' => 'float',
            'sort_order' => 'integer',
        ];
    }

    public function outlet(): BelongsTo
    {
        return $this->belongsTo(PosOutlet::class, 'pos_outlet_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'menu_category_id');
    }

    public function prepStation(): BelongsTo
    {
        return $this->belongsTo(PosPrepStation::class, 'pos_prep_station_id');
    }

    public function directInventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'direct_inventory_item_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(MenuItemVariant::class, 'menu_item_id');
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(MenuItemModifier::class, 'menu_item_id');
    }

    public function recipes(): HasMany
    {
        return $this->hasMany(MenuItemRecipe::class, 'menu_item_id');
    }
}
