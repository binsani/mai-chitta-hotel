<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class JournalEntry extends Model
{
    use HasFactory;

    protected $table = 'journal_entries';

    protected $fillable = [
        'entry_number',
        'entry_date',
        'source_module',
        'source_reference',
        'description',
        'status',
        'created_by_user_id',
        'posted_by_user_id',
        'reversed_by_user_id',
        'reversal_journal_entry_id',
        'posted_at',
        'reversed_at',
    ];

    protected $casts = [
        'entry_date' => 'date',
        'posted_at' => 'datetime',
        'reversed_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(JournalEntryLine::class, 'journal_entry_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function postedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'posted_by_user_id');
    }

    public function reversedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reversed_by_user_id');
    }

    public function reversalEntry(): BelongsTo
    {
        return $this->belongsTo(JournalEntry::class, 'reversal_journal_entry_id');
    }

    public function totalDebitMinor(): int
    {
        return (int) $this->lines()->sum('debit_minor');
    }

    public function totalCreditMinor(): int
    {
        return (int) $this->lines()->sum('credit_minor');
    }

    public function isBalanced(): bool
    {
        return $this->totalDebitMinor() === $this->totalCreditMinor();
    }
}
