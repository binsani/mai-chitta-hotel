<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class NavigationMenu extends Model
{
    protected $fillable = ['slug', 'name', 'description', 'updated_by'];

    public function items(): HasMany
    {
        return $this->hasMany(NavigationItem::class, 'menu_id')->orderBy('sort_order')->orderBy('id');
    }
}
