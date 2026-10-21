<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SupplierInventoryItem extends Model
{
    protected $fillable = [
        'supplier_id',
        'inventory_item_id',
        'supplier_sku',
        'last_quoted_unit_cost_minor',
        'lead_time_days',
        'is_preferred',
    ];

    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'inventory_item_id' => 'integer',
            'last_quoted_unit_cost_minor' => 'integer',
            'lead_time_days' => 'integer',
            'is_preferred' => 'boolean',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
