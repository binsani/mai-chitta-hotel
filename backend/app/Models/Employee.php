<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Employee extends Model
{
    protected $fillable = ['staff_number', 'name', 'email', 'phone', 'department', 'job_title', 'start_date', 'status', 'confidential_notes'];

    protected $hidden = ['confidential_notes'];

    protected function casts(): array
    {
        return ['confidential_notes' => 'encrypted'];
    }
}
