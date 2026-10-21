<?php

namespace Tests\Feature;

use App\Models\ChartOfAccount;
use App\Models\Guest;
use App\Models\GuestFolio;
use App\Models\JournalEntry;
use App\Models\NightAudit;
use App\Models\RatePlan;
use App\Models\Reservation;
use App\Models\Role;
use App\Models\Room;
use App\Models\RoomType;
use App\Models\Setting;
use App\Models\User;
use App\Services\AccountingService;
use App\Services\NightAuditService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class Phase6AccountingNightAuditAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    protected User $superAdmin;
    protected User $accountant;
    protected User $frontDeskSupervisor;
    protected User $regularStaff;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->seed(ChartOfAccountsSeeder::class);

        $this->superAdmin = $this->createUserWithRole('super-admin', 'admin@maichitta.test');
        $this->accountant = $this->createUserWithRole('accountant', 'accountant@maichitta.test');
        $this->frontDeskSupervisor = $this->createUserWithRole('front-desk-supervisor', 'supervisor@maichitta.test');
        $this->regularStaff = $this->createUserWithRole('housekeeping-staff', 'staff@maichitta.test');
    }

    private function createUserWithRole(string $roleSlug, string $email): User
    {
        $user = User::factory()->create(['email' => $email, 'is_active' => true]);
        $role = Role::where('slug', $roleSlug)->firstOrFail();
        $user->roles()->sync([$role->id => ['created_at' => now()]]);

        return $user;
    }

    public function test_chart_of_accounts_are_seeded_with_standard_departments_and_types(): void
    {
        $this->assertDatabaseHas('chart_of_accounts', ['code' => '1010', 'type' => 'asset', 'normal_balance' => 'debit']);
        $this->assertDatabaseHas('chart_of_accounts', ['code' => '2010', 'type' => 'liability', 'normal_balance' => 'credit']);
        $this->assertDatabaseHas('chart_of_accounts', ['code' => '4010', 'type' => 'revenue', 'normal_balance' => 'credit']);
        $this->assertDatabaseHas('chart_of_accounts', ['code' => '5010', 'type' => 'expense', 'normal_balance' => 'debit']);

        $res = $this->actingAs($this->accountant)
            ->getJson('/api/v1/admin/accounting/accounts');

        $res->assertOk()
            ->assertJsonPath('data.0.code', '1010');
    }

    public function test_journal_entry_must_balance_strictly(): void
    {
        $service = app(AccountingService::class);

        // Out of balance: 10,000 debit vs 8,000 credit
        $this->expectException(ValidationException::class);
        $service->createAndPostJournalEntry([
            'description' => 'Unbalanced entry test',
            'lines' => [
                ['account_code' => '1010', 'debit_minor' => 1000000, 'credit_minor' => 0],
                ['account_code' => '4010', 'debit_minor' => 0, 'credit_minor' => 800000],
            ],
        ], $this->accountant);
    }

    public function test_balanced_journal_entry_creates_immutable_records_and_audit_trail(): void
    {
        $res = $this->actingAs($this->accountant)
            ->postJson('/api/v1/admin/accounting/journals', [
                'entry_date' => now()->toDateString(),
                'description' => 'Cash capital injection test',
                'lines' => [
                    ['account_code' => '1010', 'debit_minor' => 5000000, 'credit_minor' => 0, 'memo' => 'Vault deposit'],
                    ['account_code' => '3010', 'debit_minor' => 0, 'credit_minor' => 5000000, 'memo' => 'Owner capital contribution'],
                ],
            ]);

        $res->assertCreated()
            ->assertJsonPath('data.status', 'posted');

        $entry = JournalEntry::where('description', 'Cash capital injection test')->first();
        $this->assertNotNull($entry);
        $this->assertTrue($entry->isBalanced());
        $this->assertEquals(5000000, $entry->totalDebitMinor());
        $this->assertEquals(5000000, $entry->totalCreditMinor());

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'accounting.journal_entry_posted',
            'subject_type' => 'JournalEntry',
            'subject_id' => (string) $entry->id,
        ]);
    }

    public function test_reversing_journal_entry_inverts_debits_and_credits_accurately(): void
    {
        $service = app(AccountingService::class);

        $original = $service->createAndPostJournalEntry([
            'entry_date' => now()->toDateString(),
            'description' => 'Erroneous utility expense',
            'lines' => [
                ['account_code' => '5060', 'debit_minor' => 2500000, 'credit_minor' => 0],
                ['account_code' => '1010', 'debit_minor' => 0, 'credit_minor' => 2500000],
            ],
        ], $this->accountant);

        $res = $this->actingAs($this->accountant)
            ->postJson("/api/v1/admin/accounting/journals/{$original->id}/reverse", [
                'reason' => 'Duplicate generator fuel invoice posted',
            ]);

        $res->assertOk();

        $original->refresh();
        $this->assertEquals('reversed', $original->status);
        $this->assertNotNull($original->reversal_journal_entry_id);

        $reversal = JournalEntry::find($original->reversal_journal_entry_id);
        $this->assertNotNull($reversal);
        $this->assertTrue($reversal->isBalanced());
        $this->assertEquals('posted', $reversal->status);

        // Inverted: Account 1010 should now have debit 2,500,000, and 5060 should have credit 2,500,000
        $this->assertDatabaseHas('journal_entry_lines', [
            'journal_entry_id' => $reversal->id,
            'account_id' => ChartOfAccount::where('code', '1010')->first()->id,
            'debit_minor' => 2500000,
            'credit_minor' => 0,
        ]);
    }

    public function test_trial_balance_and_financial_statements_reconcile(): void
    {
        $service = app(AccountingService::class);

        // 1. Capital contribution: Debit Cash (1010) ₦100,000 / Credit Capital (3010) ₦100,000
        $service->createAndPostJournalEntry([
            'description' => 'Capital',
            'lines' => [
                ['account_code' => '1010', 'debit_minor' => 10000000, 'credit_minor' => 0],
                ['account_code' => '3010', 'debit_minor' => 0, 'credit_minor' => 10000000],
            ],
        ], $this->superAdmin);

        // 2. Room revenue: Debit Guest Ledger (1110) ₦50,000 / Credit Room Revenue (4010) ₦50,000
        $service->createAndPostJournalEntry([
            'description' => 'Room Revenue',
            'lines' => [
                ['account_code' => '1110', 'debit_minor' => 5000000, 'credit_minor' => 0],
                ['account_code' => '4010', 'debit_minor' => 0, 'credit_minor' => 5000000],
            ],
        ], $this->superAdmin);

        // 3. Operating expense: Debit Fuel (5060) ₦10,000 / Credit Cash (1010) ₦10,000
        $service->createAndPostJournalEntry([
            'description' => 'Fuel Expense',
            'lines' => [
                ['account_code' => '5060', 'debit_minor' => 1000000, 'credit_minor' => 0],
                ['account_code' => '1010', 'debit_minor' => 0, 'credit_minor' => 1000000],
            ],
        ], $this->superAdmin);

        // Check Trial Balance
        $tb = $service->getTrialBalance();
        $this->assertTrue($tb['is_balanced']);
        $this->assertEquals($tb['total_debit_minor'], $tb['total_credit_minor']);

        // Check Income Statement: Revenue ₦50,000 - Expense ₦10,000 = Net Profit ₦40,000
        $pnl = $service->getIncomeStatement('2026-01-01', '2026-12-31');
        $this->assertEquals(5000000, $pnl['total_revenue_minor']);
        $this->assertEquals(1000000, $pnl['total_expense_minor']);
        $this->assertEquals(4000000, $pnl['net_profit_minor']);

        // Check Balance Sheet: Assets = Liabilities + Equity + Net Profit
        $bs = $service->getBalanceSheet();
        $this->assertTrue($bs['is_in_balance']);
        $this->assertEquals(4000000, $bs['current_period_earnings_minor']);
    }

    public function test_night_audit_pre_run_checklist_identifies_in_house_stays(): void
    {
        $guest = Guest::create(['full_name' => 'Aliyu Bello', 'email' => 'aliyu@example.test', 'phone' => '08011112222']);
        $roomType = RoomType::create(['name' => 'Deluxe Suite', 'slug' => 'deluxe-suite', 'base_price_minor' => 4500000, 'max_occupancy' => 2]);
        $ratePlan = RatePlan::create(['name' => 'Standard Rate', 'code' => 'STD', 'cancellation_policy' => 'flexible']);
        $room = Room::create(['room_type_id' => $roomType->id, 'room_number' => 'D-101', 'operational_status' => 'available', 'cleaning_status' => 'clean']);

        $res = Reservation::create([
            'reference' => 'RES-20261021-001',
            'public_access_token' => 'tok-001',
            'guest_id' => $guest->id,
            'guest_name' => $guest->full_name,
            'guest_email' => $guest->email,
            'guest_phone' => $guest->phone,
            'room_type_id' => $roomType->id,
            'rate_plan_id' => $ratePlan->id,
            'assigned_room_id' => $room->id,
            'check_in_date' => '2026-10-21',
            'check_out_date' => '2026-10-23',
            'nights' => 2,
            'rooms_count' => 1,
            'adults' => 2,
            'subtotal_minor' => 9000000,
            'total_minor' => 9000000,
            'price_snapshot' => ['daily_rate' => 4500000],
            'currency_code' => 'NGN',
            'source' => 'frontdesk',
            'status' => 'checked_in',
        ]);

        GuestFolio::create([
            'reservation_id' => $res->id,
            'folio_reference' => 'FOL-20261021-001',
            'guest_id' => $guest->id,
            'room_id' => $room->id,
            'guest_name' => $guest->full_name,
            'currency_code' => 'NGN',
            'status' => 'open',
            'balance_due_minor' => 0,
        ]);

        $auditService = app(NightAuditService::class);
        $checklist = $auditService->getPreAuditChecklist('2026-10-21');

        $this->assertEquals(1, $checklist['in_house_stays_count']);
        $this->assertTrue($checklist['can_proceed']);
    }

    public function test_night_audit_execution_posts_room_charges_generates_gl_entry_and_rolls_business_date(): void
    {
        $guest = Guest::create(['full_name' => 'Fatima Garba', 'email' => 'fatima@example.test', 'phone' => '08022223333']);
        $roomType = RoomType::create(['name' => 'Executive Suite', 'slug' => 'exec-suite', 'base_price_minor' => 6000000, 'max_occupancy' => 2]);
        $ratePlan = RatePlan::create(['name' => 'Executive Standard', 'code' => 'EXEC-STD', 'cancellation_policy' => 'flexible']);
        $room = Room::create(['room_type_id' => $roomType->id, 'room_number' => 'E-201', 'operational_status' => 'available', 'cleaning_status' => 'clean']);

        $res = Reservation::create([
            'reference' => 'RES-20261021-002',
            'public_access_token' => 'tok-002',
            'guest_id' => $guest->id,
            'guest_name' => $guest->full_name,
            'guest_email' => $guest->email,
            'guest_phone' => $guest->phone,
            'room_type_id' => $roomType->id,
            'rate_plan_id' => $ratePlan->id,
            'assigned_room_id' => $room->id,
            'check_in_date' => '2026-10-21',
            'check_out_date' => '2026-10-23',
            'nights' => 2,
            'rooms_count' => 1,
            'adults' => 2,
            'subtotal_minor' => 12000000,
            'total_minor' => 12000000,
            'price_snapshot' => ['daily_rate' => 4500000],
            'currency_code' => 'NGN',
            'source' => 'frontdesk',
            'status' => 'checked_in',
        ]);

        $folio = GuestFolio::create([
            'reservation_id' => $res->id,
            'folio_reference' => 'FOL-20261021-002',
            'guest_id' => $guest->id,
            'room_id' => $room->id,
            'guest_name' => $guest->full_name,
            'currency_code' => 'NGN',
            'status' => 'open',
            'balance_due_minor' => 0,
        ]);

        $response = $this->actingAs($this->frontDeskSupervisor)
            ->postJson('/api/v1/admin/night-audit/run', [
                'audit_date' => '2026-10-21',
                'notes' => 'End of day audit performed by supervisor',
            ]);

        $response->assertOk()
            ->assertJsonPath('data.status', 'completed')
            ->assertJsonPath('data.occupied_rooms', 1);

        $folio->refresh();
        $this->assertGreaterThan(0, $folio->balance_due_minor);

        // General Ledger journal entry created
        $audit = NightAudit::first();
        $this->assertNotNull($audit);
        $this->assertNotNull($audit->journal_entry_id);
        $je = JournalEntry::find($audit->journal_entry_id);
        $this->assertNotNull($je);
        $this->assertTrue($je->isBalanced());

        // Verified business date rolled to next calendar day
        $dateSetting = Setting::where('key', 'hotel.business_date')->first();
        $this->assertEquals('2026-10-22', $dateSetting->value);
    }

    public function test_unauthorized_users_are_forbidden_from_running_night_audit_or_posting_journals(): void
    {
        $this->actingAs($this->regularStaff)
            ->postJson('/api/v1/admin/night-audit/run', ['audit_date' => '2026-10-21'])
            ->assertForbidden();

        $this->actingAs($this->regularStaff)
            ->postJson('/api/v1/admin/accounting/journals', [
                'description' => 'Staff unpermitted posting',
                'lines' => [],
            ])
            ->assertForbidden();
    }
}
