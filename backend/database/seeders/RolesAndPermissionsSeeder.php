<?php

namespace Database\Seeders;

use App\Models\Permission;
use App\Models\Role;
use App\Support\Rbac\PermissionCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Idempotent: syncs permissions and system roles from PermissionCatalog.
 * Safe to run in production (`php artisan db:seed --class=RolesAndPermissionsSeeder --force`).
 * Creates no users and no passwords. Custom (non-system) roles are left untouched.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            foreach (PermissionCatalog::permissions() as $module => $actions) {
                foreach ($actions as $action => $label) {
                    Permission::updateOrCreate(["slug" => "$module.$action"], ['module' => $module, 'name' => $label]);
                }
            }
            $ids = Permission::pluck('id', 'slug');

            foreach (PermissionCatalog::roles() as $slug => $def) {
                $role = Role::updateOrCreate(['slug' => $slug], ['name' => $def['name'], 'description' => $def['description'], 'is_system' => true]);
                $role->permissions()->sync(collect(PermissionCatalog::expand($def['permissions']))->map(fn ($s) => $ids[$s])->all());
            }
        });
    }
}
