<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GoodsReceiving extends Model
{
    protected $fillable = [
        'receiving_reference',
        'idempotency_key',
        'purchase_order_id',
        'supplier_id',
        'storage_location_id',
        'supplier_delivery_note',
        'supplier_invoice_reference',
        'currency_code',
        'total_received_cost_minor',
        'total_received_cost',
        'has_discrepancy',
        'discrepancy_notes',
        'notes',
        'received_by',
        'received_at',
    ];

    protected function casts(): array
    {
        return [
            'purchase_order_id' => 'integer',
            'supplier_id' => 'integer',
            'storage_location_id' => 'integer',
            'total_received_cost_minor' => 'integer',
            'total_received_cost' => 'float',
            'has_discrepancy' => 'boolean',
            'received_by' => 'integer',
            'received_at' => 'datetime',
        ];
    }

    public function purchaseOrder(): BelongsTo
    {
        return $this->belongsTo(PurchaseOrder::class, 'purchase_order_id');
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class, 'storage_location_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(GoodsReceivingItem::class, 'goods_receiving_id');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'received_by');
    }

    public function toArrayForUser(?User $user): array
    {
        $data = $this->toArray();
        if (! $user || ! $user->hasPermission('procurement.costs.view')) {
            unset($data['total_received_cost_minor'], $data['total_received_cost']);
            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as &$line) {
                    unset($line['unit_cost_minor'], $line['line_total_minor']);
                }
            }
        }

        return $data;
    }
}
