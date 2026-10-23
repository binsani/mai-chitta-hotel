<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PayrollEntry extends Model
{
    protected $fillable = ['employee_id', 'period', 'basic_minor', 'allowances_minor', 'deductions_minor', 'net_minor', 'status', 'prepared_by', 'reviewed_by', 'reviewed_at'];

    protected function casts(): array
    {
        return ['basic_minor' => 'integer', 'allowances_minor' => 'integer', 'deductions_minor' => 'integer', 'net_minor' => 'integer', 'reviewed_at' => 'datetime'];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
