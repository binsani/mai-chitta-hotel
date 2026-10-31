<?php

namespace Tests\Feature;

use App\Models\OperationalNotification;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OperationalNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private function staff(): User
    {
        $this->seed(RolesAndPermissionsSeeder::class);
        $staff = User::factory()->create();
        $staff->roles()->attach(Role::where('slug', 'receptionist')->value('id'));

        return $staff;
    }

    public function test_alerts_are_scoped_before_the_limit_and_exclude_private_metadata(): void
    {
        $staff = $this->staff();
        $visible = OperationalNotification::create(['type' => 'room.ready', 'title' => 'Room ready', 'message' => 'Reception can assign this room.', 'severity' => 'info', 'target_permission' => 'frontdesk.view', 'metadata' => ['private' => 'internal']]);
        for ($i = 0; $i < 31; $i++) {
            OperationalNotification::create(['type' => 'finance.review', 'title' => 'Finance alert', 'message' => 'Private finance details', 'severity' => 'warning', 'target_permission' => 'finance.approve']);
        }
        OperationalNotification::create(['type' => 'staff.private', 'title' => 'Other staff', 'message' => 'Other staff only', 'severity' => 'info', 'target_user_id' => User::factory()->create()->id]);

        $this->actingAs($staff)->getJson('/api/v1/admin/operational-notifications')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('unread_count', 1)
            ->assertJsonPath('data.0.id', $visible->id)->assertJsonMissingPath('data.0.metadata');
    }

    public function test_staff_cannot_acknowledge_alerts_for_another_user_or_permission(): void
    {
        $staff = $this->staff();
        $private = OperationalNotification::create(['type' => 'staff.private', 'title' => 'Private', 'message' => 'Other staff only', 'severity' => 'info', 'target_user_id' => User::factory()->create()->id]);
        $restricted = OperationalNotification::create(['type' => 'finance.review', 'title' => 'Finance', 'message' => 'Finance only', 'severity' => 'warning', 'target_permission' => 'finance.approve']);
        $this->actingAs($staff);

        foreach ([$private, $restricted] as $alert) {
            $this->postJson('/api/v1/admin/operational-notifications/'.$alert->id.'/read')->assertNotFound();
            $this->assertDatabaseHas('operational_notifications', ['id' => $alert->id, 'is_read' => false, 'read_by' => null]);
        }
    }

    public function test_allowed_alerts_can_be_acknowledged_and_guests_cannot_access_them(): void
    {
        $staff = $this->staff();
        $alert = OperationalNotification::create(['type' => 'room.ready', 'title' => 'Room ready', 'message' => 'Reception alert', 'severity' => 'info', 'target_user_id' => $staff->id, 'target_permission' => 'frontdesk.view']);
        $this->getJson('/api/v1/admin/operational-notifications')->assertUnauthorized();
        $this->postJson('/api/v1/admin/operational-notifications/'.$alert->id.'/read')->assertUnauthorized();

        $this->actingAs($staff)->postJson('/api/v1/admin/operational-notifications/'.$alert->id.'/read')->assertOk();
        $this->assertDatabaseHas('operational_notifications', ['id' => $alert->id, 'is_read' => true, 'read_by' => $staff->id]);
        $this->getJson('/api/v1/admin/operational-notifications')->assertOk()->assertJsonPath('unread_count', 0);
    }
}
