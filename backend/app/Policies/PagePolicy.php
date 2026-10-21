<?php

namespace App\Policies;

use App\Models\Page;
use App\Models\User;

class PagePolicy
{
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('website.view');
    }

    public function view(User $user, Page $page): bool
    {
        return $user->hasPermission('website.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermission('website.create');
    }

    public function update(User $user, Page $page): bool
    {
        return $user->hasPermission('website.edit');
    }

    public function publish(User $user, Page $page): bool
    {
        return $user->hasPermission('website.publish');
    }

    public function delete(User $user, Page $page): bool
    {
        return $user->hasPermission('website.delete') && ! $page->is_system;
    }
}
