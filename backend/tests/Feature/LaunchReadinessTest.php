<?php

namespace Tests\Feature;

use App\Models\ContactInquiry;
use App\Models\Employee;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LaunchReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolesAndPermissionsSeeder::class);
        $this->withHeaders(['Referer' => 'http://localhost:8000', 'Origin' => 'http://localhost:8000']);
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->roles()->attach(Role::where('slug', $role)->value('id'));

        return $user;
    }

    private function employee(): Employee
    {
        return Employee::create(['staff_number' => 'STAFF-001', 'name' => 'Hotel Staff', 'department' => 'Reception', 'job_title' => 'Receptionist', 'status' => 'active', 'confidential_notes' => 'Private record']);
    }

    private function payrollData(Employee $employee): array
    {
        return ['employee_id' => $employee->id, 'period' => '2026-10', 'basic_minor' => 10000000, 'allowances_minor' => 1000000, 'deductions_minor' => 500000];
    }

    public function test_staff_endpoints_return_401_without_authentication(): void
    {
        $this->getJson('/api/v1/admin/contact-inquiries')->assertUnauthorized();
        $this->getJson('/api/v1/admin/employees')->assertUnauthorized();
        $this->getJson('/api/v1/admin/payroll')->assertUnauthorized();
        $this->getJson('/api/v1/admin/payroll/export?period=2026-10')->assertUnauthorized();
    }

    public function test_website_team_can_find_and_resolve_guest_inquiries(): void
    {
        $inquiry = ContactInquiry::create(['name' => 'Guest', 'email' => 'guest@example.test', 'subject' => 'Conference booking', 'message' => 'Please send your event details.', 'ip_address' => '127.0.0.1', 'status' => 'new']);
        $this->actingAs($this->actor('hotel-manager'));
        $this->getJson('/api/v1/admin/contact-inquiries?search=Conference&status=new')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.ip_address');
        $this->putJson('/api/v1/admin/contact-inquiries/'.$inquiry->id, ['status' => 'resolved'])->assertOk();
        $this->assertDatabaseHas('contact_inquiries', ['id' => $inquiry->id, 'status' => 'resolved']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'cms.inquiry.updated', 'subject_id' => (string) $inquiry->id]);
        $this->putJson('/api/v1/admin/contact-inquiries/'.$inquiry->id, ['status' => 'unknown'])->assertUnprocessable()->assertJsonValidationErrors('status');
    }

    public function test_receptionist_gets_403_for_inquiries_hr_and_payroll(): void
    {
        $employee = $this->employee();
        $this->actingAs($this->actor('receptionist'));
        $this->getJson('/api/v1/admin/contact-inquiries')->assertForbidden();
        $this->getJson('/api/v1/admin/employees')->assertForbidden();
        $this->postJson('/api/v1/admin/employees', [])->assertForbidden();
        $this->getJson('/api/v1/admin/payroll')->assertForbidden();
        $this->postJson('/api/v1/admin/payroll', $this->payrollData($employee))->assertForbidden();
    }

    public function test_hr_manager_can_create_and_edit_staff_with_encrypted_confidential_notes(): void
    {
        $this->actingAs($this->actor('hr-manager'));
        $data = ['staff_number' => 'HR-001', 'name' => 'Staff Member', 'department' => 'Housekeeping', 'job_title' => 'Supervisor', 'status' => 'active', 'confidential_notes' => 'Confidential emergency information'];
        $id = $this->postJson('/api/v1/admin/employees', $data)->assertCreated()->json('data.id');
        $this->assertDatabaseHas('employees', ['id' => $id, 'name' => 'Staff Member']);
        $this->assertNotSame('Confidential emergency information', DB::table('employees')->where('id', $id)->value('confidential_notes'));
        $this->getJson('/api/v1/admin/employees')->assertOk()->assertJsonPath('data.0.confidential_notes', 'Confidential emergency information');
        $this->putJson('/api/v1/admin/employees/'.$id, [...$data, 'status' => 'on_leave'])->assertOk();
        $this->assertDatabaseHas('employees', ['id' => $id, 'status' => 'on_leave']);
        $this->postJson('/api/v1/admin/employees', $data)->assertUnprocessable()->assertJsonValidationErrors('staff_number');
        $this->postJson('/api/v1/admin/employees', [])->assertUnprocessable()->assertJsonValidationErrors(['staff_number', 'name', 'department', 'job_title', 'status']);
    }

    public function test_hotel_manager_cannot_read_confidential_notes_or_edit_staff(): void
    {
        $employee = $this->employee();
        $this->actingAs($this->actor('hotel-manager'));
        $this->getJson('/api/v1/admin/employees')->assertOk()->assertJsonMissingPath('data.0.confidential_notes');
        $this->putJson('/api/v1/admin/employees/'.$employee->id, ['confidential_notes' => 'changed'])->assertForbidden();
        $this->assertSame('Private record', $employee->fresh()->confidential_notes);
    }

    public function test_payroll_calculates_net_and_requires_independent_once_only_approval(): void
    {
        $employee = $this->employee();
        $this->actingAs($this->actor('super-admin'));
        $data = $this->payrollData($employee);
        $id = $this->postJson('/api/v1/admin/payroll', [...$data, 'net_minor' => 1, 'status' => 'approved'])->assertCreated()->assertJsonPath('data.net_minor', 10500000)->assertJsonPath('data.status', 'pending')->json('data.id');
        $this->postJson('/api/v1/admin/payroll', $data)->assertUnprocessable()->assertJsonValidationErrors('period');
        $this->postJson('/api/v1/admin/payroll/'.$id.'/review', ['status' => 'approved'])->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->actor('general-manager'));
        $this->postJson('/api/v1/admin/payroll/'.$id.'/review', ['status' => 'approved'])->assertOk();
        $this->assertDatabaseHas('payroll_entries', ['id' => $id, 'status' => 'approved', 'net_minor' => 10500000]);
        $this->postJson('/api/v1/admin/payroll/'.$id.'/review', ['status' => 'rejected'])->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertDatabaseHas('audit_logs', ['action' => 'payroll.approved', 'subject_id' => (string) $id]);
    }

    public function test_payroll_rejects_negative_net_inactive_employees_and_invalid_periods(): void
    {
        $employee = $this->employee();
        $this->actingAs($this->actor('payroll-officer'));
        $data = $this->payrollData($employee);
        $this->postJson('/api/v1/admin/payroll', [...$data, 'deductions_minor' => 11000001])->assertUnprocessable()->assertJsonValidationErrors('deductions_minor');
        $this->postJson('/api/v1/admin/payroll', [...$data, 'period' => '2026-13'])->assertUnprocessable()->assertJsonValidationErrors('period');
        $this->postJson('/api/v1/admin/payroll', [...$data, 'basic_minor' => -1])->assertUnprocessable()->assertJsonValidationErrors('basic_minor');
        $employee->update(['status' => 'inactive']);
        $this->postJson('/api/v1/admin/payroll', $data)->assertUnprocessable()->assertJsonValidationErrors('employee_id');
        $this->assertDatabaseCount('payroll_entries', 0);
    }

    public function test_rejected_payroll_can_be_corrected_without_duplicate_month_records(): void
    {
        $employee = $this->employee();
        $preparer = $this->actor('payroll-officer');
        $this->actingAs($preparer);
        $data = $this->payrollData($employee);
        $id = $this->postJson('/api/v1/admin/payroll', $data)->assertCreated()->json('data.id');
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->actor('general-manager'));
        $this->postJson('/api/v1/admin/payroll/'.$id.'/review', ['status' => 'rejected'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->actingAs($preparer);
        $this->postJson('/api/v1/admin/payroll', [...$data, 'basic_minor' => 12000000])->assertCreated()->assertJsonPath('data.id', $id)->assertJsonPath('data.status', 'pending')->assertJsonPath('data.reviewed_by', null)->assertJsonPath('data.net_minor', 12500000);
        $this->assertDatabaseCount('payroll_entries', 1);
    }

    public function test_export_requires_permission_filters_month_and_escapes_spreadsheet_formulas(): void
    {
        $employee = $this->employee();
        $employee->update(['name' => '=HYPERLINK("test")']);
        $this->actingAs($this->actor('payroll-officer'));
        $this->postJson('/api/v1/admin/payroll', $this->payrollData($employee))->assertCreated();
        $csv = $this->get('/api/v1/admin/payroll/export?period=2026-10')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('105000.00', $csv);
        $emptyCsv = $this->get('/api/v1/admin/payroll/export?period=2026-11')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('105000.00', $emptyCsv);
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->actor('hr-manager'));
        $this->getJson('/api/v1/admin/payroll/export?period=2026-10')->assertForbidden();
    }

    public function test_readiness_reports_incomplete_production_configuration(): void
    {
        $this->artisan('hotel:check-readiness')->expectsOutputToContain('Production environment')->assertExitCode(1);
    }

    public function test_public_site_loads_with_missing_navigation_menus(): void
    {
        $this->getJson('/api/v1/public/bootstrap')->assertOk()->assertJsonPath('data.menus.header', [])->assertJsonPath('data.menus.footer', []);
    }

    public function test_bootstrap_survives_database_cache_serialization_on_repeated_requests(): void
    {
        config(['cache.default' => 'database']);
        $this->getJson('/api/v1/public/bootstrap')->assertOk()->assertJsonPath('data.featured_rooms', []);
        $this->getJson('/api/v1/public/bootstrap')->assertOk()->assertJsonPath('data.featured_rooms', [])->assertJsonPath('data.featured_offers', [])->assertJsonPath('data.gallery_preview', [])->assertJsonPath('data.testimonials', []);
    }
}
