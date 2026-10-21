<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Supplier extends Model
{
    protected $fillable = [
        'supplier_code',
        'name',
        'contact_person',
        'phone',
        'email',
        'address',
        'status',
        'tax_id',
        'bank_name',
        'bank_account_number',
        'bank_account_name',
        'payment_terms',
        'notes',
        'created_by',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function supportedItems(): HasMany
    {
        return $this->hasMany(SupplierInventoryItem::class, 'supplier_id');
    }

    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'supplier_id');
    }

    /**
     * Redact sensitive supplier banking and tax details if the user lacks `procurement.suppliers.sensitive`.
     */
    public function toArrayForUser(?User $user): array
    {
        $data = $this->toArray();
        if (! $user || ! $user->hasPermission('procurement.suppliers.sensitive')) {
            unset(
                $data['tax_id'],
                $data['bank_name'],
                $data['bank_account_number'],
                $data['bank_account_name']
            );
        }

        return $data;
    }
}
