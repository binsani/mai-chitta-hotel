<?php

namespace App\Services;

use App\Models\ChartOfAccount;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountingService
{
    public function __construct(
        protected AuditLogger $auditLogger
    ) {}

    /**
     * Post a balanced double-entry journal entry.
     *
     * @param array{
     *     entry_date?: string,
     *     source_module?: string,
     *     source_reference?: string,
     *     description: string,
     *     lines: array<int, array{
     *         account_code?: string,
     *         account_id?: int,
     *         debit_minor?: int,
     *         credit_minor?: int,
     *         department?: string,
     *         memo?: string
     *     }>
     * } $data
     */
    public function createAndPostJournalEntry(array $data, ?User $actor = null): JournalEntry
    {
        return DB::transaction(function () use ($data, $actor) {
            $entryDate = isset($data['entry_date']) ? Carbon::parse($data['entry_date']) : Carbon::today();
            $linesData = $data['lines'] ?? [];

            if (empty($linesData)) {
                throw ValidationException::withMessages(['lines' => 'Journal entry must contain at least two lines.']);
            }

            $totalDebit = 0;
            $totalCredit = 0;
            $preparedLines = [];

            foreach ($linesData as $idx => $line) {
                $accountId = $line['account_id'] ?? null;
                if (! $accountId && ! empty($line['account_code'])) {
                    $acc = ChartOfAccount::where('code', $line['account_code'])->first();
                    if (! $acc) {
                        throw ValidationException::withMessages(["lines.{$idx}.account_code" => "Account code [{$line['account_code']}] not found."]);
                    }
                    $accountId = $acc->id;
                }

                if (! $accountId) {
                    throw ValidationException::withMessages(["lines.{$idx}" => 'Account ID or code is required.']);
                }

                $debit = max(0, (int) ($line['debit_minor'] ?? 0));
                $credit = max(0, (int) ($line['credit_minor'] ?? 0));

                if ($debit === 0 && $credit === 0) {
                    continue; // skip zero-value lines
                }

                $totalDebit += $debit;
                $totalCredit += $credit;

                $preparedLines[] = [
                    'account_id' => $accountId,
                    'debit_minor' => $debit,
                    'credit_minor' => $credit,
                    'department' => $line['department'] ?? 'GA',
                    'memo' => $line['memo'] ?? null,
                ];
            }

            if ($totalDebit === 0 && $totalCredit === 0) {
                throw ValidationException::withMessages(['lines' => 'Journal entry cannot have zero total debit and credit.']);
            }

            if ($totalDebit !== $totalCredit) {
                $diff = abs($totalDebit - $totalCredit);
                throw ValidationException::withMessages([
                    'balance' => "Journal entry is out of balance. Total debits (₦".number_format($totalDebit/100, 2).") do not equal total credits (₦".number_format($totalCredit/100, 2)."). Discrepancy: ₦".number_format($diff/100, 2),
                ]);
            }

            $entryNumber = $this->generateEntryNumber($entryDate);

            $entry = JournalEntry::create([
                'entry_number' => $entryNumber,
                'entry_date' => $entryDate->toDateString(),
                'source_module' => $data['source_module'] ?? 'MANUAL',
                'source_reference' => $data['source_reference'] ?? null,
                'description' => $data['description'],
                'status' => 'posted',
                'created_by_user_id' => $actor?->id,
                'posted_by_user_id' => $actor?->id,
                'posted_at' => now(),
            ]);

            foreach ($preparedLines as $line) {
                $entry->lines()->create($line);
            }

            $this->auditLogger->log(
                action: 'accounting.journal_entry_posted',
                subject: $entry,
                context: [
                    'entry_number' => $entry->entry_number,
                    'total_minor' => $totalDebit,
                    'source_module' => $entry->source_module,
                    'source_reference' => $entry->source_reference,
                ],
                userId: $actor?->id
            );

            return $entry->load(['lines.account', 'createdBy']);
        });
    }

    /**
     * Reverse an existing posted journal entry by generating an inverted offsetting entry.
     */
    public function reverseJournalEntry(JournalEntry $originalEntry, string $reason, ?User $actor = null): JournalEntry
    {
        return DB::transaction(function () use ($originalEntry, $reason, $actor) {
            if ($originalEntry->status !== 'posted') {
                throw ValidationException::withMessages(['status' => 'Only posted journal entries can be reversed.']);
            }

            $originalEntry->load('lines.account');

            $reversalLines = [];
            foreach ($originalEntry->lines as $line) {
                // Invert debits and credits
                $reversalLines[] = [
                    'account_id' => $line->account_id,
                    'debit_minor' => $line->credit_minor,
                    'credit_minor' => $line->debit_minor,
                    'department' => $line->department,
                    'memo' => "Reversal of {$originalEntry->entry_number}: {$line->memo}",
                ];
            }

            $reversalEntry = $this->createAndPostJournalEntry([
                'entry_date' => now()->toDateString(),
                'source_module' => $originalEntry->source_module,
                'source_reference' => "REVERSAL:{$originalEntry->entry_number}",
                'description' => "Reversal of {$originalEntry->entry_number} - Reason: {$reason}",
                'lines' => $reversalLines,
            ], $actor);

            $originalEntry->update([
                'status' => 'reversed',
                'reversed_by_user_id' => $actor?->id,
                'reversal_journal_entry_id' => $reversalEntry->id,
                'reversed_at' => now(),
            ]);

            $this->auditLogger->log(
                action: 'accounting.journal_entry_reversed',
                subject: $originalEntry,
                context: [
                    'original_entry' => $originalEntry->entry_number,
                    'reversal_entry' => $reversalEntry->entry_number,
                    'reason' => $reason,
                ],
                userId: $actor?->id
            );

            return $reversalEntry;
        });
    }

    /**
     * Compute Trial Balance for a given date range.
     *
     * @return array{
     *     as_of_date: string,
     *     total_debit_minor: int,
     *     total_credit_minor: int,
     *     is_balanced: bool,
     *     accounts: array<int, array{
     *         code: string,
     *         name: string,
     *         type: string,
     *         department: string,
     *         debit_balance_minor: int,
     *         credit_balance_minor: int
     *     }>
     * }
     */
    public function getTrialBalance(?string $asOfDate = null): array
    {
        $asOf = $asOfDate ? Carbon::parse($asOfDate)->endOfDay() : Carbon::now()->endOfDay();

        $accounts = ChartOfAccount::where('is_active', true)->orderBy('code')->get();

        $rows = [];
        $grandDebit = 0;
        $grandCredit = 0;

        foreach ($accounts as $acc) {
            $debitSum = (int) JournalEntryLine::where('account_id', $acc->id)
                ->whereHas('entry', function ($q) use ($asOf) {
                    $q->where('status', 'posted')
                        ->whereDate('entry_date', '<=', $asOf->toDateString());
                })
                ->sum('debit_minor');

            $creditSum = (int) JournalEntryLine::where('account_id', $acc->id)
                ->whereHas('entry', function ($q) use ($asOf) {
                    $q->where('status', 'posted')
                        ->whereDate('entry_date', '<=', $asOf->toDateString());
                })
                ->sum('credit_minor');

            $net = $debitSum - $creditSum;
            $debitBalance = 0;
            $creditBalance = 0;

            if ($acc->normal_balance === 'debit') {
                if ($net >= 0) {
                    $debitBalance = $net;
                } else {
                    $creditBalance = abs($net);
                }
            } else { // normal balance is credit
                if ($net <= 0) {
                    $creditBalance = abs($net);
                } else {
                    $debitBalance = $net;
                }
            }

            if ($debitBalance > 0 || $creditBalance > 0 || $debitSum > 0 || $creditSum > 0) {
                $grandDebit += $debitBalance;
                $grandCredit += $creditBalance;

                $rows[] = [
                    'id' => $acc->id,
                    'code' => $acc->code,
                    'name' => $acc->name,
                    'type' => $acc->type,
                    'department' => $acc->department,
                    'normal_balance' => $acc->normal_balance,
                    'debit_balance_minor' => $debitBalance,
                    'credit_balance_minor' => $creditBalance,
                ];
            }
        }

        return [
            'as_of_date' => $asOf->toDateString(),
            'total_debit_minor' => $grandDebit,
            'total_credit_minor' => $grandCredit,
            'is_balanced' => $grandDebit === $grandCredit,
            'accounts' => $rows,
        ];
    }

    /**
     * Compute Income Statement (Profit & Loss) for a date range.
     */
    public function getIncomeStatement(string $startDate, string $endDate): array
    {
        $start = Carbon::parse($startDate)->startOfDay();
        $end = Carbon::parse($endDate)->endOfDay();

        $revenues = ChartOfAccount::where('type', 'revenue')->where('is_active', true)->orderBy('code')->get();
        $expenses = ChartOfAccount::where('type', 'expense')->where('is_active', true)->orderBy('code')->get();

        $revenueRows = [];
        $totalRevenue = 0;
        foreach ($revenues as $acc) {
            $credits = (int) JournalEntryLine::where('account_id', $acc->id)
                ->whereHas('entry', fn ($q) => $q->where('status', 'posted')->whereDate('entry_date', '>=', $start->toDateString())->whereDate('entry_date', '<=', $end->toDateString()))
                ->sum('credit_minor');
            $debits = (int) JournalEntryLine::where('account_id', $acc->id)
                ->whereHas('entry', fn ($q) => $q->where('status', 'posted')->whereDate('entry_date', '>=', $start->toDateString())->whereDate('entry_date', '<=', $end->toDateString()))
                ->sum('debit_minor');

            $net = $credits - $debits;
            if ($net !== 0) {
                $revenueRows[] = [
                    'code' => $acc->code,
                    'name' => $acc->name,
                    'department' => $acc->department,
                    'amount_minor' => $net,
                ];
                $totalRevenue += $net;
            }
        }

        $expenseRows = [];
        $totalExpense = 0;
        foreach ($expenses as $acc) {
            $debits = (int) JournalEntryLine::where('account_id', $acc->id)
                ->whereHas('entry', fn ($q) => $q->where('status', 'posted')->whereDate('entry_date', '>=', $start->toDateString())->whereDate('entry_date', '<=', $end->toDateString()))
                ->sum('debit_minor');
            $credits = (int) JournalEntryLine::where('account_id', $acc->id)
                ->whereHas('entry', fn ($q) => $q->where('status', 'posted')->whereDate('entry_date', '>=', $start->toDateString())->whereDate('entry_date', '<=', $end->toDateString()))
                ->sum('credit_minor');

            $net = $debits - $credits;
            if ($net !== 0) {
                $expenseRows[] = [
                    'code' => $acc->code,
                    'name' => $acc->name,
                    'department' => $acc->department,
                    'amount_minor' => $net,
                ];
                $totalExpense += $net;
            }
        }

        $netProfit = $totalRevenue - $totalExpense;

        return [
            'start_date' => $start->toDateString(),
            'end_date' => $end->toDateString(),
            'revenue_lines' => $revenueRows,
            'total_revenue_minor' => $totalRevenue,
            'expense_lines' => $expenseRows,
            'total_expense_minor' => $totalExpense,
            'net_profit_minor' => $netProfit,
        ];
    }

    /**
     * Compute Balance Sheet as of a specific date.
     */
    public function getBalanceSheet(?string $asOfDate = null): array
    {
        $asOf = $asOfDate ? Carbon::parse($asOfDate)->endOfDay() : Carbon::now()->endOfDay();

        $assets = ChartOfAccount::where('type', 'asset')->where('is_active', true)->orderBy('code')->get();
        $liabilities = ChartOfAccount::where('type', 'liability')->where('is_active', true)->orderBy('code')->get();
        $equities = ChartOfAccount::where('type', 'equity')->where('is_active', true)->orderBy('code')->get();

        $assetRows = [];
        $totalAssets = 0;
        foreach ($assets as $acc) {
            $debits = (int) JournalEntryLine::where('account_id', $acc->id)
                ->whereHas('entry', fn ($q) => $q->where('status', 'posted')->whereDate('entry_date', '<=', $asOf->toDateString()))
                ->sum('debit_minor');
            $credits = (int) JournalEntryLine::where('account_id', $acc->id)
                ->whereHas('entry', fn ($q) => $q->where('status', 'posted')->whereDate('entry_date', '<=', $asOf->toDateString()))
                ->sum('credit_minor');

            $balance = $debits - $credits;
            if ($balance !== 0) {
                $assetRows[] = [
                    'code' => $acc->code,
                    'name' => $acc->name,
                    'department' => $acc->department,
                    'amount_minor' => $balance,
                ];
                $totalAssets += $balance;
            }
        }

        $liabilityRows = [];
        $totalLiabilities = 0;
        foreach ($liabilities as $acc) {
            $credits = (int) JournalEntryLine::where('account_id', $acc->id)
                ->whereHas('entry', fn ($q) => $q->where('status', 'posted')->whereDate('entry_date', '<=', $asOf->toDateString()))
                ->sum('credit_minor');
            $debits = (int) JournalEntryLine::where('account_id', $acc->id)
                ->whereHas('entry', fn ($q) => $q->where('status', 'posted')->whereDate('entry_date', '<=', $asOf->toDateString()))
                ->sum('debit_minor');

            $balance = $credits - $debits;
            if ($balance !== 0) {
                $liabilityRows[] = [
                    'code' => $acc->code,
                    'name' => $acc->name,
                    'department' => $acc->department,
                    'amount_minor' => $balance,
                ];
                $totalLiabilities += $balance;
            }
        }

        $equityRows = [];
        $totalEquity = 0;
        foreach ($equities as $acc) {
            $credits = (int) JournalEntryLine::where('account_id', $acc->id)
                ->whereHas('entry', fn ($q) => $q->where('status', 'posted')->whereDate('entry_date', '<=', $asOf->toDateString()))
                ->sum('credit_minor');
            $debits = (int) JournalEntryLine::where('account_id', $acc->id)
                ->whereHas('entry', fn ($q) => $q->where('status', 'posted')->whereDate('entry_date', '<=', $asOf->toDateString()))
                ->sum('debit_minor');

            $balance = $credits - $debits;
            if ($balance !== 0) {
                $equityRows[] = [
                    'code' => $acc->code,
                    'name' => $acc->name,
                    'department' => $acc->department,
                    'amount_minor' => $balance,
                ];
                $totalEquity += $balance;
            }
        }

        // Current Year P&L retained in equity
        $pnl = $this->getIncomeStatement('2000-01-01', $asOf->toDateString());
        $currentEarnings = $pnl['net_profit_minor'];

        $totalEquityAndLiabilities = $totalLiabilities + $totalEquity + $currentEarnings;

        return [
            'as_of_date' => $asOf->toDateString(),
            'asset_lines' => $assetRows,
            'total_assets_minor' => $totalAssets,
            'liability_lines' => $liabilityRows,
            'total_liabilities_minor' => $totalLiabilities,
            'equity_lines' => $equityRows,
            'total_equity_minor' => $totalEquity,
            'current_period_earnings_minor' => $currentEarnings,
            'total_liabilities_and_equity_minor' => $totalEquityAndLiabilities,
            'is_in_balance' => $totalAssets === $totalEquityAndLiabilities,
        ];
    }

    private function generateEntryNumber(Carbon $date): string
    {
        $prefix = 'JE-' . $date->format('Ymd') . '-';
        $count = JournalEntry::where('entry_number', 'like', "{$prefix}%")->count() + 1;
        return sprintf('%s%04d', $prefix, $count);
    }
}
