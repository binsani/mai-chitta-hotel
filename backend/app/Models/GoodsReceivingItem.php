<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GoodsReceivingItem extends Model
{
    protected $fillable = [
        'goods_receiving_id',
        'purchase_order_item_id',
        'inventory_item_id',
        'quantity_received',
        'quantity_rejected',
        'base_quantity_received',
        'unit_cost_minor',
        'line_total_minor',
        'batch_number',
        'expiry_date',
        'has_price_discrepancy',
        'has_quantity_discrepancy',
        'discrepancy_reason',
        'stock_movement_id',
    ];

    protected function casts(): array
    {
        return [
            'goods_receiving_id' => 'integer',
            'purchase_order_item_id' => 'integer',
            'inventory_item_id' => 'integer',
            'quantity_received' => 'float',
            'quantity_rejected' => 'float',
            'base_quantity_received' => 'float',
            'unit_cost_minor' => 'integer',
            'line_total_minor' => 'integer',
            'expiry_date' => 'date:Y-m-d',
            'has_price_discrepancy' => 'boolean',
            'has_quantity_discrepancy' => 'boolean',
            'stock_movement_id' => 'integer',
        ];
    }

    public function receiving(): BelongsTo
    {
        return $this->belongsTo(GoodsReceiving::class, 'goods_receiving_id');
    }

    public function purchaseOrderItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrderItem::class, 'purchase_order_item_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function stockMovement(): BelongsTo
    {
        return $this->belongsTo(StockMovement::class, 'stock_movement_id');
    }
}
