<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class Phase1FoundationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        // Simulate the same-origin SPA so Sanctum treats requests as stateful.
        $this->withHeaders(['Referer' => 'http://localhost:8000', 'Origin' => 'http://localhost:8000']);
    }

    private function userWithRole(string $slug, array $attrs = []): User
    {
        $user = User::factory()->create($attrs + ['password' => 'Secret-pass-123']);
        $user->roles()->attach(Role::where('slug', $slug)->value('id'), ['created_at' => now()]);

        return $user;
    }

    public function test_login_logout_and_current_user(): void
    {
        $user = $this->userWithRole('receptionist', ['email' => 'r@example.test']);

        $this->postJson('/api/v1/auth/login', ['email' => 'r@example.test', 'password' => 'Secret-pass-123'])
            ->assertOk()->assertJsonPath('user.email', 'r@example.test');
        $this->getJson('/api/v1/auth/user')->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonFragment(['frontdesk.checkin']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'auth.login', 'outcome' => 'success', 'user_id' => $user->id]);

        $this->postJson('/api/v1/auth/logout')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/auth/user')->assertUnauthorized();
    }

    public function test_failed_login_is_audited_without_password_and_rate_limited(): void
    {
        $this->userWithRole('receptionist', ['email' => 'r@example.test']);
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/v1/auth/login', ['email' => 'r@example.test', 'password' => 'wrong'])->assertUnprocessable();
        }
        $this->postJson('/api/v1/auth/login', ['email' => 'r@example.test', 'password' => 'wrong'])->assertStatus(429);

        $log = AuditLog::where('action', 'auth.login')->where('outcome', 'failure')->first();
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('wrong', json_encode($log->context));
    }

    public function test_inactive_user_cannot_login(): void
    {
        $this->userWithRole('receptionist', ['email' => 'x@example.test', 'is_active' => false]);
        $this->postJson('/api/v1/auth/login', ['email' => 'x@example.test', 'password' => 'Secret-pass-123'])->assertUnprocessable();
    }

    public function test_backend_enforces_permissions(): void
    {
        $receptionist = $this->userWithRole('receptionist');
        $this->actingAs($receptionist)->getJson('/api/v1/admin/users')->assertForbidden();
        $this->actingAs($receptionist)->getJson('/api/v1/admin/audit-logs')->assertForbidden();
        $this->actingAs($receptionist)->putJson('/api/v1/admin/settings', ['hotel' => ['name' => 'X']])->assertForbidden();

        $auditor = $this->userWithRole('auditor');
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->actingAs($auditor)->getJson('/api/v1/admin/users')->assertOk();
        $this->actingAs($auditor)->postJson('/api/v1/admin/users', ['name' => 'a', 'email' => 'a@b.c', 'password' => 'Secret-pass-123'])->assertForbidden();
    }

    public function test_website_role_separation_from_finance_and_hr(): void
    {
        $manager = $this->userWithRole('hotel-manager');
        $this->assertFalse($manager->hasPermission('finance.refund'));
        $this->assertFalse($manager->hasPermission('hr.confidential'));
        $this->assertFalse($manager->hasPermission('payroll.approve'));
        $pos = $this->userWithRole('pos-staff');
        $this->assertFalse($pos->hasPermission('website.publish'));
    }

    public function test_non_super_admin_cannot_grant_super_admin(): void
    {
        $gm = $this->userWithRole('general-manager');
        Role::where('slug', 'general-manager')->first()->permissions()->attach(
            \App\Models\Permission::whereIn('slug', ['users.manage', 'roles.manage'])->pluck('id')
        );
        $target = $this->userWithRole('receptionist');
        $this->actingAs($gm->fresh())->putJson("/api/v1/admin/users/{$target->id}", [
            'role_ids' => [Role::where('slug', 'super-admin')->value('id')],
        ])->assertUnprocessable()->assertJsonValidationErrors('role_ids');
    }

    public function test_super_admin_manages_users_and_changes_are_audited(): void
    {
        $admin = $this->userWithRole('super-admin');
        $res = $this->actingAs($admin)->postJson('/api/v1/admin/users', [
            'name' => 'New Staff', 'email' => 'new@example.test', 'password' => 'Secret-pass-123',
            'role_ids' => [Role::where('slug', 'housekeeping-staff')->value('id')],
        ])->assertCreated();
        $id = $res->json('data.id');
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.created', 'subject_id' => (string) $id]);

        $this->actingAs($admin)->putJson("/api/v1/admin/users/{$id}", ['role_ids' => [Role::where('slug', 'pos-staff')->value('id')]])->assertOk();
        $this->assertDatabaseHas('audit_logs', ['action' => 'user.roles_changed', 'subject_id' => (string) $id]);

        // Self-deactivation is blocked.
        $this->actingAs($admin)->putJson("/api/v1/admin/users/{$admin->id}", ['is_active' => false])->assertUnprocessable();
    }

    public function test_super_admin_role_is_immutable(): void
    {
        $admin = $this->userWithRole('super-admin');
        $role = Role::where('slug', 'super-admin')->first();
        $this->actingAs($admin)->putJson("/api/v1/admin/roles/{$role->id}", ['permissions' => []])->assertForbidden();
        $this->actingAs($admin)->deleteJson("/api/v1/admin/roles/{$role->id}")->assertForbidden();
    }

    public function test_settings_defaults_update_and_public_endpoint(): void
    {
        $this->getJson('/api/v1/settings/public')->assertOk()
            ->assertJsonPath('data.hotel.name', 'Mai Chitta Hotel')
            ->assertJsonPath('data.hotel.currency', 'NGN')
            ->assertJsonPath('data.hotel.phone', null)
            ->assertJsonMissingPath('data.hotel.timezone');

        $admin = $this->userWithRole('super-admin');
        $this->actingAs($admin)->putJson('/api/v1/admin/settings', ['hotel' => ['currency' => 'ngn']])->assertUnprocessable();
        $this->actingAs($admin)->putJson('/api/v1/admin/settings', ['hotel' => ['phone' => '+234 800 000 0000']])
            ->assertOk()->assertJsonPath('changed', ['hotel.phone']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'settings.updated']);
        $this->actingAs($admin)->getJson('/api/v1/admin/settings')->assertOk()->assertJsonPath('data.hotel.timezone', 'Africa/Lagos');
    }

    public function test_audit_logs_are_immutable(): void
    {
        $log = AuditLog::create(['action' => 'test']);
        $this->expectException(\LogicException::class);
        $log->update(['action' => 'tampered']);
    }

    public function test_password_reset_flow(): void
    {
        Notification::fake();
        $user = $this->userWithRole('receptionist', ['email' => 'p@example.test']);
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'p@example.test'])->assertOk();
        $this->postJson('/api/v1/auth/forgot-password', ['email' => 'unknown@example.test'])->assertOk(); // no enumeration
        Notification::assertSentTo($user, \App\Notifications\ResetPasswordNotification::class);

        $token = Password::createToken($user);
        $this->postJson('/api/v1/auth/reset-password', [
            'token' => $token, 'email' => 'p@example.test', 'password' => 'Brand-new-pass-9', 'password_confirmation' => 'Brand-new-pass-9',
        ])->assertOk();
        $this->postJson('/api/v1/auth/login', ['email' => 'p@example.test', 'password' => 'Brand-new-pass-9'])->assertOk();
    }

    public function test_deactivated_user_session_is_rejected(): void
    {
        $user = $this->userWithRole('receptionist');
        $user->update(['is_active' => false]);
        $this->actingAs($user)->getJson('/api/v1/auth/user')->assertUnauthorized();
    }

    public function test_user_without_roles_manage_cannot_assign_roles_on_user_creation(): void
    {
        $actor = $this->userWithRole('hotel-manager');
        Role::where('slug', 'hotel-manager')->first()->permissions()->attach(
            \App\Models\Permission::where('slug', 'users.manage')->pluck('id')
        );
        $this->actingAs($actor->fresh())->postJson('/api/v1/admin/users', [
            'name' => 'Test Staff',
            'email' => 'staff@example.test',
            'password' => 'Secret-pass-123',
            'role_ids' => [Role::where('slug', 'receptionist')->value('id')],
        ])->assertUnprocessable()->assertJsonValidationErrors('role_ids');
    }

    public function test_change_password_keeps_current_session_authenticated(): void
    {
        $user = $this->userWithRole('receptionist', ['email' => 'pw@example.test']);
        $this->postJson('/api/v1/auth/login', ['email' => 'pw@example.test', 'password' => 'Secret-pass-123'])->assertOk();

        $this->putJson('/api/v1/auth/password', [
            'current_password' => 'Secret-pass-123',
            'password' => 'New-Secret-Pass-456',
            'password_confirmation' => 'New-Secret-Pass-456',
        ])->assertOk();

        // Subsequent stateful request must remain authenticated with the new password hash in session.
        $this->getJson('/api/v1/auth/user')->assertOk()->assertJsonPath('data.email', 'pw@example.test');
    }
}
