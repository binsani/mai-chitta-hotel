<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Append-only guest folio ledger entry.
 * Once posted (`status === 'posted'`) or rejected (`status === 'rejected'`), entries cannot be mutated or deleted.
 * Corrections must be posted via an explicit contra/reversal entry referencing `reversed_entry_id`.
 */
class FolioEntry extends Model
{
    public const STATUS_PENDING_APPROVAL = 'pending_approval';
    public const STATUS_POSTED = 'posted';
    public const STATUS_REJECTED = 'rejected';

    public const DIRECTION_CHARGE = 'charge';
    public const DIRECTION_CREDIT = 'credit';

    protected $fillable = [
        'entry_reference', 'idempotency_key', 'guest_folio_id', 'reservation_id',
        'entry_type', 'direction', 'category', 'description', 'service_date',
        'quantity', 'currency_code',
        'unit_amount_minor', 'net_amount_minor', 'tax_amount_minor', 'service_charge_minor', 'total_amount_minor', 'total_amount',
        'status', 'source_type', 'source_id', 'active_source_key', 'reversed_entry_id',
        'reason', 'metadata', 'requested_by', 'approved_by', 'posted_by', 'posted_at',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $entry): void {
            $originalStatus = $entry->getOriginal('status');
            if ($originalStatus !== self::STATUS_PENDING_APPROVAL) {
                throw new LogicException('Posted or reviewed folio ledger entries are immutable. Record a reversal entry instead.');
            }
        });

        static::deleting(function (): void {
            throw new LogicException('Folio ledger entries cannot be deleted.');
        });
    }

    protected function casts(): array
    {
        return [
            'guest_folio_id' => 'integer',
            'reservation_id' => 'integer',
            'service_date' => 'date:Y-m-d',
            'quantity' => 'integer',
            'unit_amount_minor' => 'integer',
            'net_amount_minor' => 'integer',
            'tax_amount_minor' => 'integer',
            'service_charge_minor' => 'integer',
            'total_amount_minor' => 'integer',
            'total_amount' => 'float',
            'reversed_entry_id' => 'integer',
            'metadata' => 'array',
            'requested_by' => 'integer',
            'approved_by' => 'integer',
            'posted_by' => 'integer',
            'posted_at' => 'datetime',
        ];
    }

    public function folio(): BelongsTo
    {
        return $this->belongsTo(GuestFolio::class, 'guest_folio_id');
    }

    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class, 'reservation_id');
    }

    public function reversedEntry(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reversed_entry_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function poster(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by');
    }
}
