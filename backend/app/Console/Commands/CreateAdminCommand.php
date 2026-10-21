<?php

namespace App\Console\Commands;

use App\Models\Role;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Safe first-administrator creation. Password is entered interactively
 * (hidden) so it never appears in source control, shell history or seeders.
 */
class CreateAdminCommand extends Command
{
    protected $signature = 'hotel:create-admin {--email=} {--name=}';

    protected $description = 'Create a Super Administrator account interactively';

    public function handle(AuditLogger $audit): int
    {
        if (! Role::where('slug', 'super-admin')->exists()) {
            $this->error('Roles not seeded. Run: php artisan db:seed --class=RolesAndPermissionsSeeder --force');

            return self::FAILURE;
        }

        $name = $this->option('name') ?: $this->ask('Full name');
        $email = $this->option('email') ?: $this->ask('Email');
        $password = $this->secret('Password (hidden)');
        $confirm = $this->secret('Confirm password');

        $v = Validator::make(compact('name', 'email', 'password') + ['password_confirmation' => $confirm], [
            'name' => ['required', 'max:120'],
            'email' => ['required', 'email', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(12)->letters()->mixedCase()->numbers()],
        ]);
        if ($v->fails()) {
            foreach ($v->errors()->all() as $e) {
                $this->error($e);
            }

            return self::FAILURE;
        }

        $user = DB::transaction(function () use ($name, $email, $password) {
            $user = User::create(['name' => $name, 'email' => $email, 'password' => $password, 'is_active' => true]);
            $user->forceFill(['email_verified_at' => now()])->save();
            $user->roles()->attach(Role::where('slug', 'super-admin')->value('id'), ['created_at' => now()]);

            return $user;
        });
        $audit->log('user.created', $user, ['via' => 'console', 'roles' => ['super-admin']]);

        $this->info("Super Administrator {$email} created.");

        return self::SUCCESS;
    }
}
