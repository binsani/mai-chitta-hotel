<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryBatch extends Model
{
    protected $fillable = [
        'inventory_item_id',
        'storage_location_id',
        'batch_number',
        'expiry_date',
        'received_quantity',
        'remaining_quantity',
        'unit_cost_minor',
        'goods_receiving_id',
    ];

    protected function casts(): array
    {
        return [
            'inventory_item_id' => 'integer',
            'storage_location_id' => 'integer',
            'expiry_date' => 'date:Y-m-d',
            'received_quantity' => 'float',
            'remaining_quantity' => 'float',
            'unit_cost_minor' => 'integer',
            'goods_receiving_id' => 'integer',
        ];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class, 'storage_location_id');
    }
}
