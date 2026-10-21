<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PosOrderItem extends Model
{
    protected $fillable = [
        'pos_order_id',
        'menu_item_id',
        'menu_item_variant_id',
        'item_name_snapshot',
        'variant_name_snapshot',
        'prep_station_name_snapshot',
        'quantity',
        'currency_code',
        'unit_price_minor',
        'modifiers_unit_minor',
        'line_subtotal_minor',
        'unit_price',
        'line_subtotal',
        'modifiers_snapshot',
        'special_instructions',
        'prep_status',
    ];

    protected function casts(): array
    {
        return [
            'pos_order_id' => 'integer',
            'menu_item_id' => 'integer',
            'menu_item_variant_id' => 'integer',
            'quantity' => 'integer',
            'unit_price_minor' => 'integer',
            'modifiers_unit_minor' => 'integer',
            'line_subtotal_minor' => 'integer',
            'unit_price' => 'float',
            'line_subtotal' => 'float',
            'modifiers_snapshot' => 'array',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(PosOrder::class, 'pos_order_id');
    }

    public function menuItem(): BelongsTo
    {
        return $this->belongsTo(MenuItem::class, 'menu_item_id');
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(MenuItemVariant::class, 'menu_item_variant_id');
    }
}
