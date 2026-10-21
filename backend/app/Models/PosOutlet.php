<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PosOutlet extends Model
{
    protected $fillable = [
        'code',
        'name',
        'type',
        'description',
        'default_storage_location_id',
        'is_active',
        'accepts_orders',
        'supports_room_service',
        'supports_room_charge',
        'tax_percentage',
        'service_charge_percentage',
        'operating_hours',
    ];

    protected function casts(): array
    {
        return [
            'default_storage_location_id' => 'integer',
            'is_active' => 'boolean',
            'accepts_orders' => 'boolean',
            'supports_room_service' => 'boolean',
            'supports_room_charge' => 'boolean',
            'tax_percentage' => 'float',
            'service_charge_percentage' => 'float',
            'operating_hours' => 'array',
        ];
    }

    public function defaultStorageLocation(): BelongsTo
    {
        return $this->belongsTo(StorageLocation::class, 'default_storage_location_id');
    }

    public function prepStations(): HasMany
    {
        return $this->hasMany(PosPrepStation::class, 'pos_outlet_id');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(MenuCategory::class, 'pos_outlet_id');
    }

    public function menuItems(): HasMany
    {
        return $this->hasMany(MenuItem::class, 'pos_outlet_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(PosOrder::class, 'pos_outlet_id');
    }
}
