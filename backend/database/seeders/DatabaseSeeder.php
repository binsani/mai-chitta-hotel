<?php

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     * Production: only roles/permissions. Local: also one demo user per role
     * with a random password printed to the console (never committed).
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
            CmsContentSeeder::class,
        ]);

        if (! app()->environment('local', 'testing')) {
            return;
        }

        $password = env('DEV_SEED_PASSWORD') ?: \Illuminate\Support\Str::password(14, symbols: false);
        foreach (Role::all() as $role) {
            $user = User::firstOrCreate(
                ['email' => "{$role->slug}@example.test"],
                ['name' => "Demo {$role->name}", 'password' => $password],
            );
            $user->roles()->syncWithoutDetaching([$role->id => ['created_at' => now()]]);
        }
        $this->command?->warn("Local demo users created (<role-slug>@example.test). Password for newly created users: {$password}");
    }
}
