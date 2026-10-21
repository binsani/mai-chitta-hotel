<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseOrderItem extends Model
{
    protected $fillable = [
        'purchase_order_id',
        'inventory_item_id',
        'unit',
        'conversion_to_base',
        'quantity_ordered',
        'quantity_received',
        'quantity_rejected',
        'unit_cost_minor',
        'line_total_minor',
        'unit_cost',
        'line_total',
    ];

    protected $appends = [
        'quantity_outstanding',
    ];

    protected function casts(): array
    {
        return [
            'purchase_order_id' => 'integer',
            'inventory_item_id' => 'integer',
            'conversion_to_base' => 'float',
            'quantity_ordered' => 'float',
            'quantity_received' => 'float',
            'quantity_rejected' => 'float',
            'unit_cost_minor' => 'integer',
            'line_total_minor' => 'integer',
            'unit_cost' => 'float',
            'line_total' => 'float',
        ];
    }

    public function getQuantityOutstandingAttribute(): float
    {
        return max(0.0, round(((float) $this->quantity_ordered) - ((float) $this->quantity_received), 4));
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
