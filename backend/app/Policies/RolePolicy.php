<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;

class RolePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('roles.view');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('roles.manage');
    }

    /** The Super Administrator role is immutable through the UI. */
    public function update(User $actor, Role $role): bool
    {
        return $actor->hasPermission('roles.manage') && $role->slug !== 'super-admin';
    }

    public function delete(User $actor, Role $role): bool
    {
        return $actor->hasPermission('roles.manage') && ! $role->is_system;
    }
}
