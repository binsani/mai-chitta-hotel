<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PurchaseOrder extends Model
{
    public const STATUS_DRAFT = 'draft';
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_PARTIALLY_RECEIVED = 'partially_received';
    public const STATUS_RECEIVED = 'received';
    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'po_reference',
        'supplier_id',
        'purchase_requisition_id',
        'storage_location_id',
        'status',
        'expected_delivery_date',
        'currency_code',
        'subtotal_minor',
        'tax_minor',
        'total_minor',
        'total_amount',
        'notes',
        'cancellation_reason',
        'created_by',
        'approved_by',
        'approved_at',
        'cancelled_by',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'supplier_id' => 'integer',
            'purchase_requisition_id' => 'integer',
            'storage_location_id' => 'integer',
            'expected_delivery_date' => 'date:Y-m-d',
            'subtotal_minor' => 'integer',
            'tax_minor' => 'integer',
            'total_minor' => 'integer',
            'total_amount' => 'float',
            'created_by' => 'integer',
            'approved_by' => 'integer',
            'approved_at' => 'datetime',
            'cancelled_by' => 'integer',
            'cancelled_at' => 'datetime',
        ];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'supplier_id');
    }

    public function requisition(): BelongsTo
    {
        return $this->belongsTo(PurchaseRequisition::class, 'purchase_requisition_id');
    }

    public function location(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class, 'storage_location_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseOrderItem::class, 'purchase_order_id');
    }

    public function receivings(): HasMany
    {
        return $this->hasMany(GoodsReceiving::class, 'purchase_order_id')->orderByDesc('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function toArrayForUser(?User $user): array
    {
        $data = $this->toArray();
        if (! $user || ! $user->hasPermission('procurement.costs.view')) {
            unset($data['subtotal_minor'], $data['tax_minor'], $data['total_minor'], $data['total_amount']);
            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as &$line) {
                    unset($line['unit_cost_minor'], $line['line_total_minor'], $line['unit_cost'], $line['line_total']);
                }
            }
        }
        if (isset($data['supplier']) && is_array($data['supplier']) && (! $user || ! $user->hasPermission('procurement.suppliers.sensitive'))) {
            unset(
                $data['supplier']['tax_id'],
                $data['supplier']['bank_name'],
                $data['supplier']['bank_account_number'],
                $data['supplier']['bank_account_name']
            );
        }

        return $data;
    }
}
