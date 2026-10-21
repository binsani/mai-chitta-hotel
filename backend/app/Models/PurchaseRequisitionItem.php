<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseRequisitionItem extends Model
{
    protected $fillable = [
        'purchase_requisition_id',
        'inventory_item_id',
        'quantity_requested',
        'quantity_approved',
        'unit',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'purchase_requisition_id' => 'integer',
            'inventory_item_id' => 'integer',
            'quantity_requested' => 'float',
            'quantity_approved' => 'float',
        ];
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function inventoryItem(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }
}
