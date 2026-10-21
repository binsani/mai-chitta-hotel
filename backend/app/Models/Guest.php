<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Guest extends Model
{
    protected $fillable = [
        'full_name', 'email', 'phone', 'nationality',
        'id_document_type', 'id_document_number',
        'address', 'city', 'state', 'country',
        'preferences', 'internal_notes', 'is_vip', 'created_by',
    ];

    protected $hidden = [
        'id_document_type',
        'id_document_number',
        'internal_notes',
    ];

    protected function casts(): array
    {
        return [
            'is_vip' => 'boolean',
        ];
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class, 'guest_id');
    }
}
