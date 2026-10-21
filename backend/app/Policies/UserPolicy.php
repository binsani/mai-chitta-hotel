<?php

namespace App\Policies;

use App\Models\User;

/**
 * Guards user administration. Prevents privilege escalation:
 * only a Super Administrator can manage another Super Administrator,
 * and nobody can deactivate or demote themselves.
 */
class UserPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->hasPermission('users.view');
    }

    public function view(User $actor, User $target): bool
    {
        return $actor->is($target) || $actor->hasPermission('users.view');
    }

    public function create(User $actor): bool
    {
        return $actor->hasPermission('users.manage');
    }

    public function update(User $actor, User $target): bool
    {
        if (! $actor->hasPermission('users.manage')) {
            return false;
        }

        return ! $target->isSuperAdmin() || $actor->isSuperAdmin();
    }

    public function assignRoles(User $actor, User $target): bool
    {
        return $this->update($actor, $target) && ! $actor->is($target) && $actor->hasPermission('roles.manage');
    }

    public function deactivate(User $actor, User $target): bool
    {
        return $this->update($actor, $target) && ! $actor->is($target);
    }
}
