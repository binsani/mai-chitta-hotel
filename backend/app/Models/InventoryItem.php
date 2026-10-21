<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryItem extends Model
{
    protected $fillable = [
        'sku',
        'inventory_category_id',
        'name',
        'description',
        'base_unit',
        'purchase_unit',
        'purchase_to_base_factor',
        'current_stock',
        'reorder_level',
        'currency_code',
        'costing_method',
        'unit_cost_minor',
        'unit_cost',
        'last_purchase_unit_cost_minor',
        'track_batches',
        'allow_negative_stock',
        'is_active',
        'created_by',
    ];

    protected $appends = [
        'is_low_stock',
        'stock_valuation_minor',
    ];

    protected function casts(): array
    {
        return [
            'inventory_category_id' => 'integer',
            'purchase_to_base_factor' => 'float',
            'current_stock' => 'float',
            'reorder_level' => 'float',
            'unit_cost_minor' => 'integer',
            'unit_cost' => 'float',
            'last_purchase_unit_cost_minor' => 'integer',
            'track_batches' => 'boolean',
            'allow_negative_stock' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(InventoryCategory::class, 'inventory_category_id');
    }

    public function balances(): HasMany
    {
        return $this->hasMany(InventoryStockBalance::class, 'inventory_item_id');
    }

    public function batches(): HasMany
    {
        return $this->hasMany(InventoryBatch::class, 'inventory_item_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(StockMovement::class, 'inventory_item_id');
    }

    public function getIsLowStockAttribute(): bool
    {
        return $this->is_active && (float) $this->current_stock <= (float) $this->reorder_level;
    }

    public function getStockValuationMinorAttribute(): int
    {
        if ((float) $this->current_stock <= 0) {
            return 0;
        }

        return (int) round(((float) $this->current_stock) * ((int) $this->unit_cost_minor));
    }

    /**
     * Redact sensitive unit cost and stock valuation fields if the user lacks `inventory.costs.view`.
     */
    public function toArrayForUser(?User $user): array
    {
        $data = $this->toArray();
        if (! $user || ! $user->hasPermission('inventory.costs.view')) {
            unset(
                $data['unit_cost_minor'],
                $data['unit_cost'],
                $data['last_purchase_unit_cost_minor'],
                $data['stock_valuation_minor']
            );
        }

        return $data;
    }
}
