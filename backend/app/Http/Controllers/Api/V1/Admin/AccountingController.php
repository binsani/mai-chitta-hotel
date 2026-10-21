<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\NightAudit;
use App\Services\AccountingService;
use App\Services\NightAuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AccountingController extends Controller
{
    public function __construct(
        protected AccountingService $accountingService,
        protected NightAuditService $nightAuditService
    ) {}

    /**
     * Chart of Accounts list.
     */
    public function accounts(Request $request): JsonResponse
    {
        Gate::authorize('accounting.view');

        $query = ChartOfAccount::query();

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }
        if ($request->filled('department')) {
            $query->where('department', $request->query('department'));
        }
        if ($request->filled('search')) {
            $s = $request->query('search');
            $query->where(fn ($q) => $q->where('code', 'like', "%{$s}%")->orWhere('name', 'like', "%{$s}%"));
        }

        $accounts = $query->orderBy('code')->get();

        return response()->json(['data' => $accounts]);
    }

    /**
     * Create custom account in Chart of Accounts.
     */
    public function storeAccount(Request $request): JsonResponse
    {
        Gate::authorize('accounting.accounts.manage');

        $validated = $request->validate([
            'code' => ['required', 'string', 'max:32', 'unique:chart_of_accounts,code'],
            'name' => ['required', 'string', 'max:128'],
            'type' => ['required', 'in:asset,liability,equity,revenue,expense'],
            'normal_balance' => ['required', 'in:debit,credit'],
            'department' => ['nullable', 'string', 'max:32'],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $account = ChartOfAccount::create([
            ...$validated,
            'is_active' => true,
            'is_system' => false,
        ]);

        return response()->json(['data' => $account, 'message' => 'Account created successfully.'], 201);
    }

    /**
     * List journal entries.
     */
    public function journals(Request $request): JsonResponse
    {
        Gate::authorize('accounting.view');

        $query = JournalEntry::with(['lines.account', 'createdBy']);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('source_module')) {
            $query->where('source_module', $request->query('source_module'));
        }
        if ($request->filled('start_date') && $request->filled('end_date')) {
            $query->whereBetween('entry_date', [$request->query('start_date'), $request->query('end_date')]);
        }

        $entries = $query->orderByDesc('entry_date')->orderByDesc('id')->paginate(25);

        return response()->json($entries);
    }

    /**
     * Show single journal entry.
     */
    public function showJournal(JournalEntry $journalEntry): JsonResponse
    {
        Gate::authorize('accounting.view');

        $journalEntry->load(['lines.account', 'createdBy', 'postedBy', 'reversedBy', 'reversalEntry']);

        return response()->json(['data' => $journalEntry]);
    }

    /**
     * Post manual journal entry.
     */
    public function storeJournal(Request $request): JsonResponse
    {
        Gate::authorize('accounting.journals.create');

        $validated = $request->validate([
            'entry_date' => ['nullable', 'date'],
            'description' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2'],
            'lines.*.account_code' => ['nullable', 'string'],
            'lines.*.account_id' => ['nullable', 'integer'],
            'lines.*.debit_minor' => ['nullable', 'integer', 'min:0'],
            'lines.*.credit_minor' => ['nullable', 'integer', 'min:0'],
            'lines.*.department' => ['nullable', 'string', 'max:32'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ]);

        $entry = $this->accountingService->createAndPostJournalEntry($validated, $request->user());

        return response()->json(['data' => $entry, 'message' => 'Journal entry posted successfully.'], 201);
    }

    /**
     * Reverse a posted journal entry.
     */
    public function reverseJournal(Request $request, JournalEntry $journalEntry): JsonResponse
    {
        Gate::authorize('accounting.journals.reverse');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:255'],
        ]);

        $reversal = $this->accountingService->reverseJournalEntry($journalEntry, $validated['reason'], $request->user());

        return response()->json(['data' => $reversal, 'message' => 'Journal entry reversed successfully.']);
    }

    /**
     * Trial Balance report.
     */
    public function trialBalance(Request $request): JsonResponse
    {
        Gate::authorize('accounting.reports.view');

        $asOf = $request->query('as_of_date');
        $report = $this->accountingService->getTrialBalance($asOf);

        return response()->json(['data' => $report]);
    }

    /**
     * Income statement (P&L).
     */
    public function incomeStatement(Request $request): JsonResponse
    {
        Gate::authorize('accounting.reports.view');

        $startDate = $request->query('start_date', now()->startOfMonth()->toDateString());
        $endDate = $request->query('end_date', now()->endOfMonth()->toDateString());

        $report = $this->accountingService->getIncomeStatement($startDate, $endDate);

        return response()->json(['data' => $report]);
    }

    /**
     * Balance Sheet.
     */
    public function balanceSheet(Request $request): JsonResponse
    {
        Gate::authorize('accounting.reports.view');

        $asOf = $request->query('as_of_date');
        $report = $this->accountingService->getBalanceSheet($asOf);

        return response()->json(['data' => $report]);
    }

    /**
     * Night audit pre-run checklist.
     */
    public function nightAuditChecklist(Request $request): JsonResponse
    {
        Gate::authorize('night_audit.view');

        $auditDate = $request->query('audit_date', now()->toDateString());
        $checklist = $this->nightAuditService->getPreAuditChecklist($auditDate);

        return response()->json(['data' => $checklist]);
    }

    /**
     * List night audit historical runs.
     */
    public function nightAudits(): JsonResponse
    {
        Gate::authorize('night_audit.view');

        $audits = NightAudit::with('auditor')
            ->orderByDesc('audit_date')
            ->paginate(30);

        return response()->json($audits);
    }

    /**
     * Execute night audit.
     */
    public function runNightAudit(Request $request): JsonResponse
    {
        Gate::authorize('night_audit.run');

        $validated = $request->validate([
            'audit_date' => ['required', 'date'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        $audit = $this->nightAuditService->executeNightAudit(
            auditDate: $validated['audit_date'],
            actor: $request->user(),
            options: ['notes' => $validated['notes'] ?? null]
        );

        return response()->json(['data' => $audit, 'message' => "Night audit for {$audit->audit_date->toDateString()} completed successfully."]);
    }

    /**
     * Hotel KPI Analytics.
     */
    public function analytics(Request $request): JsonResponse
    {
        Gate::authorize('analytics.view');

        $startDate = $request->query('start_date', now()->subDays(30)->toDateString());
        $endDate = $request->query('end_date', now()->toDateString());

        $summary = $this->nightAuditService->getAnalyticsSummary($startDate, $endDate);

        return response()->json(['data' => $summary]);
    }
}
