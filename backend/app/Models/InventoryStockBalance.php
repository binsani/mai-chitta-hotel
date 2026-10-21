<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryStockBalance extends Model
{
    protected $fillable = [
        'inventory_item_id',
        'storage_location_id',
        'quantity',
    ];

    protected function casts(): array
    {
        return [
            'inventory_item_id' => 'integer',
            'storage_location_id' => 'integer',
            'quantity' => 'float',
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
