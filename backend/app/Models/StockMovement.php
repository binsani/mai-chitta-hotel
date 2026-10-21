<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

class StockMovement extends Model
{
    public const TYPE_GOODS_RECEIVED = 'goods_received';
    public const TYPE_POS_CONSUMPTION = 'pos_consumption';
    public const TYPE_ROOM_SERVICE_CONSUMPTION = 'room_service_consumption';
    public const TYPE_TRANSFER_OUT = 'transfer_out';
    public const TYPE_TRANSFER_IN = 'transfer_in';
    public const TYPE_WASTE = 'waste';
    public const TYPE_ADJUSTMENT = 'adjustment';
    public const TYPE_STOCK_COUNT = 'stock_count';
    public const TYPE_SUPPLIER_RETURN = 'supplier_return';
    public const TYPE_RESTOCK_RETURN = 'restock_return';

    protected $fillable = [
        'movement_reference',
        'idempotency_key',
        'unique_source_key',
        'inventory_item_id',
        'storage_location_id',
        'batch_id',
        'movement_type',
        'quantity_delta',
        'balance_before',
        'balance_after',
        'unit',
        'currency_code',
        'unit_cost_minor',
        'total_cost_minor',
        'source_type',
        'source_id',
        'is_negative_override',
        'reason',
        'notes',
        'actor_id',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'inventory_item_id' => 'integer',
            'storage_location_id' => 'integer',
            'batch_id' => 'integer',
            'quantity_delta' => 'float',
            'balance_before' => 'float',
            'balance_after' => 'float',
            'unit_cost_minor' => 'integer',
            'total_cost_minor' => 'integer',
            'is_negative_override' => 'boolean',
            'actor_id' => 'integer',
            'occurred_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Stock movements are append-only and immutable.');
        });

        static::deleting(function () {
            throw new LogicException('Stock movements are append-only and cannot be deleted.');
        });
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(InventoryItem::class, 'inventory_item_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class, 'storage_location_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(InventoryBatch::class, 'batch_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }

    public function toArrayForUser(?User $user): array
    {
        $data = $this->toArray();
        if (! $user || ! $user->hasPermission('inventory.costs.view')) {
            unset($data['unit_cost_minor'], $data['total_cost_minor']);
        }

        return $data;
    }
}
