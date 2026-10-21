<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NightAudit extends Model
{
    use HasFactory;

    protected $table = 'night_audits';

    protected $fillable = [
        'audit_date',
        'status',
        'auditor_user_id',
        'started_at',
        'completed_at',
        'total_rooms',
        'occupied_rooms',
        'vacant_clean_rooms',
        'vacant_dirty_rooms',
        'out_of_order_rooms',
        'occupancy_percentage',
        'room_revenue_minor',
        'fnb_revenue_minor',
        'other_revenue_minor',
        'total_revenue_minor',
        'tax_collected_minor',
        'payments_received_minor',
        'adr_minor',
        'revpar_minor',
        'journal_entry_id',
        'operational_checklist',
        'notes',
    ];

    protected $casts = [
        'audit_date' => 'date',
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
        'total_rooms' => 'integer',
        'occupied_rooms' => 'integer',
        'vacant_clean_rooms' => 'integer',
        'vacant_dirty_rooms' => 'integer',
        'out_of_order_rooms' => 'integer',
        'occupancy_percentage' => 'float',
        'room_revenue_minor' => 'integer',
        'fnb_revenue_minor' => 'integer',
        'other_revenue_minor' => 'integer',
        'total_revenue_minor' => 'integer',
        'tax_collected_minor' => 'integer',
        'payments_received_minor' => 'integer',
        'adr_minor' => 'integer',
        'revpar_minor' => 'integer',
        'operational_checklist' => 'array',
    ];

    public function auditor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'auditor_user_id');
    }

    public function journalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'journal_entry_id');
    }
}
