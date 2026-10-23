<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\PayrollEntry;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class HumanResourcesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('hr.view');
        $data = $request->validate(['search' => ['nullable', 'string', 'max:120']]);
        $items = Employee::query()
            ->when($data['search'] ?? null, fn ($q, $search) => $q->where(fn ($q) => $q->where('name', 'like', "%{$search}%")->orWhere('staff_number', 'like', "%{$search}%")->orWhere('department', 'like', "%{$search}%")))
            ->orderBy('name')->paginate(25);
        if ($request->user()->can('hr.confidential')) {
            $items->getCollection()->each->makeVisible('confidential_notes');
        }

        return response()->json($items);
    }

    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('hr.edit');
        $employee = Employee::create($this->employeeData($request));
        $audit->log('hr.employee.created', $employee);

        return response()->json(['data' => $employee], 201);
    }

    public function update(Request $request, Employee $employee, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('hr.edit');
        $employee->update($this->employeeData($request, $employee));
        $audit->log('hr.employee.updated', $employee);

        return response()->json(['data' => $employee]);
    }

    private function employeeData(Request $request, ?Employee $employee = null): array
    {
        if ($request->exists('confidential_notes')) {
            Gate::authorize('hr.confidential');
        }

        return $request->validate([
            'staff_number' => ['required', 'string', 'max:40', Rule::unique('employees')->ignore($employee?->id)],
            'name' => ['required', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:32'],
            'department' => ['required', 'string', 'max:100'],
            'job_title' => ['required', 'string', 'max:120'],
            'start_date' => ['nullable', 'date_format:Y-m-d'],
            'status' => ['required', Rule::in(['active', 'on_leave', 'inactive'])],
            'confidential_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
        ]);
    }

    public function payroll(Request $request): JsonResponse
    {
        Gate::authorize('payroll.view');
        $data = $request->validate(['period' => ['nullable', 'date_format:Y-m']]);

        return response()->json(PayrollEntry::with('employee:id,staff_number,name,department')
            ->when($data['period'] ?? null, fn ($q, $period) => $q->where('period', $period))
            ->orderByDesc('period')->orderByDesc('id')->paginate(25));
    }

    public function preparePayroll(Request $request, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('payroll.prepare');
        $data = $request->validate([
            'employee_id' => ['required', 'integer', Rule::exists('employees', 'id')->where('status', 'active')],
            'period' => ['required', 'date_format:Y-m'],
            'basic_minor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'allowances_minor' => ['required', 'integer', 'min:0', 'max:100000000000'],
            'deductions_minor' => ['required', 'integer', 'min:0', 'max:100000000000'],
        ]);
        $net = (int) $data['basic_minor'] + (int) $data['allowances_minor'] - (int) $data['deductions_minor'];
        if ($net < 0) {
            throw ValidationException::withMessages(['deductions_minor' => 'Deductions cannot exceed basic pay plus allowances.']);
        }
        $entry = DB::transaction(function () use ($data, $net, $request, $audit) {
            $employee = Employee::lockForUpdate()->findOrFail($data['employee_id']);
            if ($employee->status !== 'active') {
                throw ValidationException::withMessages(['employee_id' => 'Payroll requires an active employee.']);
            }
            $existing = PayrollEntry::where('employee_id', $employee->id)->where('period', $data['period'])->lockForUpdate()->first();
            if ($existing && $existing->status !== 'rejected') {
                throw ValidationException::withMessages(['period' => 'Payroll already exists for this employee and month.']);
            }
            $entry = $existing ?? new PayrollEntry;
            $entry->fill([...$data, 'net_minor' => $net, 'status' => 'pending', 'prepared_by' => $request->user()->id, 'reviewed_by' => null, 'reviewed_at' => null])->save();
            $audit->log('payroll.prepared', $entry);

            return $entry;
        });

        return response()->json(['data' => $entry], 201);
    }

    public function reviewPayroll(Request $request, PayrollEntry $payrollEntry, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('payroll.approve');
        $data = $request->validate(['status' => ['required', Rule::in(['approved', 'rejected'])]]);
        $entry = DB::transaction(function () use ($request, $payrollEntry, $data, $audit) {
            $entry = PayrollEntry::lockForUpdate()->findOrFail($payrollEntry->id);
            if ($entry->prepared_by === $request->user()->id) {
                abort(403, 'A different authorized staff member must review this payroll.');
            }
            if ($entry->status !== 'pending') {
                throw ValidationException::withMessages(['status' => 'Only pending payroll can be reviewed.']);
            }
            $entry->update([...$data, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now()]);
            $audit->log('payroll.'.$data['status'], $entry);

            return $entry;
        });

        return response()->json(['data' => $entry]);
    }

    public function exportPayroll(Request $request): StreamedResponse
    {
        Gate::authorize('payroll.export');
        $data = $request->validate(['period' => ['required', 'date_format:Y-m']]);

        return response()->streamDownload(function () use ($data): void {
            $output = fopen('php://output', 'w');
            fputcsv($output, ['Staff number', 'Name', 'Period', 'Basic NGN', 'Allowances NGN', 'Deductions NGN', 'Net NGN', 'Status'], ',', '"', '');
            PayrollEntry::with('employee:id,staff_number,name')->where('period', $data['period'])->orderBy('id')->chunkById(100, function ($entries) use ($output): void {
                foreach ($entries as $entry) {
                    $safe = fn (string $value): string => preg_match('/^[\s]*[=+@-]/u', $value) ? "'".$value : $value;
                    fputcsv($output, [$safe($entry->employee->staff_number), $safe($entry->employee->name), $entry->period,
                        number_format($entry->basic_minor / 100, 2, '.', ''), number_format($entry->allowances_minor / 100, 2, '.', ''),
                        number_format($entry->deductions_minor / 100, 2, '.', ''), number_format($entry->net_minor / 100, 2, '.', ''), $entry->status], ',', '"', '');
                }
            });
            fclose($output);
        }, 'payroll-'.$data['period'].'.csv', ['Content-Type' => 'text/csv; charset=UTF-8', 'Cache-Control' => 'no-store']);
    }
}
