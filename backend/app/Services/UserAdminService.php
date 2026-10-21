<?php

namespace App\Services;

use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserAdminService
{
    public function __construct(private AuditLogger $audit) {}

    public function create(array $data, User $actor): User
    {
        return DB::transaction(function () use ($data, $actor) {
            if (! empty($data['role_ids']) && ! $actor->hasPermission('roles.manage')) {
                throw ValidationException::withMessages(['role_ids' => 'You are not allowed to assign roles.']);
            }
            $user = User::create(Arr::only($data, ['name', 'email', 'phone', 'password', 'is_active']));
            if (isset($data['role_ids'])) {
                $this->syncRoles($user, $data['role_ids'], $actor);
            }
            $this->audit->log('user.created', $user, ['email' => $user->email, 'roles' => $user->roles()->pluck('slug')]);

            return $user;
        });
    }

    public function update(User $user, array $data, User $actor): User
    {
        return DB::transaction(function () use ($user, $data, $actor) {
            $attrs = Arr::only($data, ['name', 'email', 'phone', 'is_active']);
            if (! empty($data['password'])) {
                $attrs['password'] = $data['password'];
            }
            if (array_key_exists('is_active', $attrs) && ! $attrs['is_active'] && ! $actor->can('deactivate', $user)) {
                throw ValidationException::withMessages(['is_active' => 'You cannot deactivate this account.']);
            }

            $user->fill($attrs);
            $dirty = array_keys($user->getDirty());
            $user->save();

            if ($dirty) {
                $this->audit->log('user.updated', $user, ['fields' => array_values(array_diff($dirty, ['password'])), 'password_changed' => in_array('password', $dirty, true)]);
            }
            // Revoke sessions on deactivation or admin password reset.
            if (in_array('password', $dirty, true) || ($user->wasChanged('is_active') && ! $user->is_active)) {
                $this->revokeSessions($user);
            }

            if (array_key_exists('role_ids', $data)) {
                if (! $actor->can('assignRoles', $user)) {
                    throw ValidationException::withMessages(['role_ids' => 'You are not allowed to change roles for this user.']);
                }
                $this->syncRoles($user, $data['role_ids'], $actor);
            }

            return $user->fresh('roles');
        });
    }

    private function syncRoles(User $user, array $roleIds, User $actor): void
    {
        $roles = Role::whereIn('id', $roleIds)->get();
        if ($roles->contains('slug', 'super-admin') && ! $actor->isSuperAdmin()) {
            throw ValidationException::withMessages(['role_ids' => 'Only a Super Administrator can grant the Super Administrator role.']);
        }

        $before = $user->roles()->pluck('slug')->all();
        $user->roles()->sync($roles->mapWithKeys(fn ($r) => [$r->id => ['assigned_by' => $actor->id, 'created_at' => now()]])->all());
        $after = $roles->pluck('slug')->all();
        $user->flushPermissionCache();

        if (array_diff($before, $after) || array_diff($after, $before)) {
            $this->audit->log('user.roles_changed', $user, ['added' => array_values(array_diff($after, $before)), 'removed' => array_values(array_diff($before, $after))]);
        }
    }

    public function revokeSessions(User $user): void
    {
        if (config('session.driver') === 'database') {
            DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)->delete();
        }
        $user->tokens()->delete();
        $user->forceFill(['remember_token' => null])->saveQuietly();
    }
}
