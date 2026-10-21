<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StorageLocation extends Model
{
    protected $fillable = [
        'code',
        'name',
        'location_type',
        'description',
        'is_default',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function balances(): HasMany
    {
        return $this->hasMany(InventoryStockBalance::class, 'storage_location_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'storage_location_id');
    }
}
