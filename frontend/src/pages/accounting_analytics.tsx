import { useState } from 'react'
import { useQuery, useMutation, useQueryClient } from '@tanstack/react-query'
import { api } from '../lib/api'
import { Card, Button, Input, Badge, DataTable, Modal, FormField } from '../components/ui'
import { Landmark, Moon, BarChart2, BookOpen, AlertCircle, RefreshCw } from 'lucide-react'

export function FinanceAdminPage() {
  const [tab, setTab] = useState<'coa' | 'journals' | 'reports'>('coa')
  const [reportType, setReportType] = useState<'trial_balance' | 'pnl' | 'balance_sheet'>('trial_balance')
  const [reverseModalOpen, setReverseModalOpen] = useState(false)
  const [selectedJournalId, setSelectedJournalId] = useState<number | null>(null)
  const [reverseReason, setReverseReason] = useState('')

  const qc = useQueryClient()

  // Chart of accounts query
  const coaQuery = useQuery({
    queryKey: ['accounting', 'accounts'],
    queryFn: () => api.get<{ data: any[] }>('/api/v1/admin/accounting/accounts').then((r) => r.data.data),
  })

  // Journal entries query
  const journalsQuery = useQuery({
    queryKey: ['accounting', 'journals'],
    queryFn: () => api.get<{ data: any[] }>('/api/v1/admin/accounting/journals').then((r) => r.data.data),
  })

  // Financial reports query
  const reportQuery = useQuery({
    queryKey: ['accounting', 'report', reportType],
    queryFn: () => {
      const endpoint =
        reportType === 'trial_balance'
          ? '/api/v1/admin/accounting/reports/trial-balance'
          : reportType === 'pnl'
          ? '/api/v1/admin/accounting/reports/income-statement'
          : '/api/v1/admin/accounting/reports/balance-sheet'
      return api.get<{ data: any }>(endpoint).then((r) => r.data.data)
    },
  })

  // Reverse journal mutation
  const reverseMutation = useMutation({
    mutationFn: ({ id, reason }: { id: number; reason: string }) =>
      api.post(`/api/v1/admin/accounting/journals/${id}/reverse`, { reason }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['accounting'] })
      setReverseModalOpen(false)
      setReverseReason('')
    },
  })

  const formatNaira = (kobo: number) => `₦${(kobo / 100).toLocaleString('en-NG', { minimumFractionDigits: 2 })}`

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900 flex items-center gap-2">
            <Landmark className="w-7 h-7 text-brand-600" />
            General Ledger & Financial Accounting
          </h1>
          <p className="text-sm text-slate-600">
            Multi-department Chart of Accounts, Double-Entry Journals, Trial Balance and Financial Statements
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Button variant="secondary" size="sm" onClick={() => qc.invalidateQueries({ queryKey: ['accounting'] })}>
            <RefreshCw className="w-4 h-4 mr-1" /> Refresh
          </Button>
        </div>
      </div>

      {/* Tabs */}
      <div className="flex border-b border-slate-200 gap-4">
        <button
          onClick={() => setTab('coa')}
          className={`pb-3 text-sm font-medium border-b-2 flex items-center gap-1.5 ${
            tab === 'coa'
              ? 'border-brand-600 text-brand-600'
              : 'border-transparent text-slate-500 hover:text-slate-700'
          }`}
        >
          <BookOpen className="w-4 h-4" /> Chart of Accounts
        </button>
        <button
          onClick={() => setTab('journals')}
          className={`pb-3 text-sm font-medium border-b-2 flex items-center gap-1.5 ${
            tab === 'journals'
              ? 'border-brand-600 text-brand-600'
              : 'border-transparent text-slate-500 hover:text-slate-700'
          }`}
        >
          <Landmark className="w-4 h-4" /> Journal Entries
        </button>
        <button
          onClick={() => setTab('reports')}
          className={`pb-3 text-sm font-medium border-b-2 flex items-center gap-1.5 ${
            tab === 'reports'
              ? 'border-brand-600 text-brand-600'
              : 'border-transparent text-slate-500 hover:text-slate-700'
          }`}
        >
          <BarChart2 className="w-4 h-4" /> Financial Statements
        </button>
      </div>

      {/* Chart of Accounts Tab */}
      {tab === 'coa' && (
        <Card className="p-4 space-y-4">
          <DataTable
            rows={coaQuery.data ?? []}
            rowKey={(r: any) => r.id}
            columns={[
              { key: 'code', header: 'Code', cell: (r: any) => <span className="font-mono font-semibold">{r.code}</span> },
              { key: 'name', header: 'Account Name', cell: (r: any) => r.name },
              {
                key: 'type',
                header: 'Type',
                cell: (r: any) => (
                  <Badge
                    tone={
                      r.type === 'revenue'
                        ? 'success'
                        : r.type === 'expense'
                        ? 'warning'
                        : r.type === 'asset'
                        ? 'brand'
                        : 'neutral'
                    }
                  >
                    {r.type.toUpperCase()}
                  </Badge>
                ),
              },
              { key: 'department', header: 'Dept', cell: (r: any) => <span className="text-xs font-mono">{r.department}</span> },
              { key: 'normal_balance', header: 'Normal', cell: (r: any) => <span className="capitalize text-xs">{r.normal_balance}</span> },
              { key: 'description', header: 'Description', cell: (r: any) => <span className="text-slate-500 text-xs">{r.description || '—'}</span> },
            ]}
          />
        </Card>
      )}

      {/* Journal Entries Tab */}
      {tab === 'journals' && (
        <Card className="p-4 space-y-4">
          <DataTable
            rows={journalsQuery.data ?? []}
            rowKey={(r: any) => r.id}
            columns={[
              { key: 'entry_number', header: 'Entry #', cell: (r: any) => <span className="font-mono font-semibold">{r.entry_number}</span> },
              { key: 'entry_date', header: 'Date', cell: (r: any) => r.entry_date },
              { key: 'description', header: 'Description', cell: (r: any) => r.description },
              { key: 'source_module', header: 'Source', cell: (r: any) => <Badge tone="neutral">{r.source_module}</Badge> },
              {
                key: 'status',
                header: 'Status',
                cell: (r: any) => (
                  <Badge tone={r.status === 'posted' ? 'success' : r.status === 'reversed' ? 'danger' : 'warning'}>
                    {r.status.toUpperCase()}
                  </Badge>
                ),
              },
              {
                key: 'actions',
                header: '',
                cell: (r: any) =>
                  r.status === 'posted' ? (
                    <Button
                      variant="secondary"
                      size="sm"
                      onClick={() => {
                        setSelectedJournalId(r.id)
                        setReverseModalOpen(true)
                      }}
                    >
                      Reverse
                    </Button>
                  ) : null,
              },
            ]}
          />
        </Card>
      )}

      {/* Financial Statements Tab */}
      {tab === 'reports' && (
        <div className="space-y-4">
          <div className="flex gap-2">
            <Button
              variant={reportType === 'trial_balance' ? 'primary' : 'secondary'}
              size="sm"
              onClick={() => setReportType('trial_balance')}
            >
              Trial Balance
            </Button>
            <Button variant={reportType === 'pnl' ? 'primary' : 'secondary'} size="sm" onClick={() => setReportType('pnl')}>
              Income Statement (P&L)
            </Button>
            <Button
              variant={reportType === 'balance_sheet' ? 'primary' : 'secondary'}
              size="sm"
              onClick={() => setReportType('balance_sheet')}
            >
              Balance Sheet
            </Button>
          </div>

          <Card className="p-6">
            {reportType === 'trial_balance' && reportQuery.data && (
              <div className="space-y-4">
                <div className="flex justify-between items-center border-b pb-3">
                  <h3 className="font-semibold text-lg">Trial Balance as of {reportQuery.data.as_of_date}</h3>
                  <Badge tone={reportQuery.data.is_balanced ? 'success' : 'danger'}>
                    {reportQuery.data.is_balanced ? 'In Balance' : 'Out of Balance'}
                  </Badge>
                </div>
                <DataTable
                  rows={reportQuery.data.accounts ?? []}
                  rowKey={(r: any) => r.code}
                  columns={[
                    { key: 'code', header: 'Code', cell: (r: any) => <span className="font-mono">{r.code}</span> },
                    { key: 'name', header: 'Account Name', cell: (r: any) => r.name },
                    { key: 'type', header: 'Type', cell: (r: any) => r.type.toUpperCase() },
                    {
                      key: 'debit',
                      header: 'Debit',
                      cell: (r: any) => (r.debit_balance_minor > 0 ? formatNaira(r.debit_balance_minor) : '—'),
                    },
                    {
                      key: 'credit',
                      header: 'Credit',
                      cell: (r: any) => (r.credit_balance_minor > 0 ? formatNaira(r.credit_balance_minor) : '—'),
                    },
                  ]}
                />
                <div className="flex justify-end gap-8 pt-4 border-t font-semibold text-base">
                  <div>Total Debit: {formatNaira(reportQuery.data.total_debit_minor || 0)}</div>
                  <div>Total Credit: {formatNaira(reportQuery.data.total_credit_minor || 0)}</div>
                </div>
              </div>
            )}

            {reportType === 'pnl' && reportQuery.data && (
              <div className="space-y-4">
                <div className="border-b pb-3">
                  <h3 className="font-semibold text-lg">
                    Income Statement ({reportQuery.data.start_date} to {reportQuery.data.end_date})
                  </h3>
                </div>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                  <div>
                    <h4 className="font-medium text-emerald-700 mb-2">Revenues</h4>
                    <div className="space-y-2">
                      {reportQuery.data.revenue_lines?.map((line: any) => (
                        <div key={line.code} className="flex justify-between text-sm py-1 border-b">
                          <span>
                            {line.code} - {line.name} ({line.department})
                          </span>
                          <span className="font-semibold">{formatNaira(line.amount_minor)}</span>
                        </div>
                      ))}
                      <div className="flex justify-between text-base font-bold pt-2 text-emerald-700">
                        <span>Total Revenue</span>
                        <span>{formatNaira(reportQuery.data.total_revenue_minor || 0)}</span>
                      </div>
                    </div>
                  </div>
                  <div>
                    <h4 className="font-medium text-red-700 mb-2">Expenses</h4>
                    <div className="space-y-2">
                      {reportQuery.data.expense_lines?.map((line: any) => (
                        <div key={line.code} className="flex justify-between text-sm py-1 border-b">
                          <span>
                            {line.code} - {line.name} ({line.department})
                          </span>
                          <span className="font-semibold">{formatNaira(line.amount_minor)}</span>
                        </div>
                      ))}
                      <div className="flex justify-between text-base font-bold pt-2 text-red-700">
                        <span>Total Expense</span>
                        <span>{formatNaira(reportQuery.data.total_expense_minor || 0)}</span>
                      </div>
                    </div>
                  </div>
                </div>
                <div className="flex justify-end pt-4 border-t text-xl font-bold">
                  Net Profit: {formatNaira(reportQuery.data.net_profit_minor || 0)}
                </div>
              </div>
            )}

            {reportType === 'balance_sheet' && reportQuery.data && (
              <div className="space-y-4">
                <div className="flex justify-between items-center border-b pb-3">
                  <h3 className="font-semibold text-lg">Balance Sheet as of {reportQuery.data.as_of_date}</h3>
                  <Badge tone={reportQuery.data.is_in_balance ? 'success' : 'danger'}>
                    {reportQuery.data.is_in_balance ? 'Balanced' : 'Discrepancy'}
                  </Badge>
                </div>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                  <div>
                    <h4 className="font-medium text-blue-700 mb-2">Assets</h4>
                    <div className="space-y-2">
                      {reportQuery.data.asset_lines?.map((line: any) => (
                        <div key={line.code} className="flex justify-between text-sm py-1 border-b">
                          <span>
                            {line.code} - {line.name}
                          </span>
                          <span className="font-semibold">{formatNaira(line.amount_minor)}</span>
                        </div>
                      ))}
                      <div className="flex justify-between text-base font-bold pt-2 text-blue-700">
                        <span>Total Assets</span>
                        <span>{formatNaira(reportQuery.data.total_assets_minor || 0)}</span>
                      </div>
                    </div>
                  </div>
                  <div>
                    <h4 className="font-medium text-amber-700 mb-2">Liabilities & Equity</h4>
                    <div className="space-y-2">
                      {reportQuery.data.liability_lines?.map((line: any) => (
                        <div key={line.code} className="flex justify-between text-sm py-1 border-b">
                          <span>
                            {line.code} - {line.name}
                          </span>
                          <span className="font-semibold">{formatNaira(line.amount_minor)}</span>
                        </div>
                      ))}
                      <div className="flex justify-between text-sm py-1 border-b text-slate-600">
                        <span>Retained Earnings / Current Period Net</span>
                        <span className="font-semibold">{formatNaira(reportQuery.data.current_period_earnings_minor || 0)}</span>
                      </div>
                      <div className="flex justify-between text-base font-bold pt-2 text-amber-700">
                        <span>Total Liabilities & Equity</span>
                        <span>{formatNaira(reportQuery.data.total_liabilities_and_equity_minor || 0)}</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            )}
          </Card>
        </div>
      )}

      {/* Reverse Modal */}
      <Modal open={reverseModalOpen} onOpenChange={setReverseModalOpen} title="Reverse Journal Entry">
        <div className="space-y-4">
          <p className="text-sm text-slate-600">
            Reversing this entry will post an inverted offsetting journal entry into the General Ledger.
          </p>
          <FormField id="reversal-reason" label="Reversal Reason">
            <Input
              id="reversal-reason"
              value={reverseReason}
              onChange={(e) => setReverseReason(e.target.value)}
              placeholder="e.g. Inadvertent duplicate entry"
            />
          </FormField>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setReverseModalOpen(false)}>
              Cancel
            </Button>
            <Button
              variant="danger"
              disabled={!reverseReason || reverseMutation.isPending}
              onClick={() => {
                if (selectedJournalId) {
                  reverseMutation.mutate({ id: selectedJournalId, reason: reverseReason })
                }
              }}
            >
              Confirm Reversal
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}

export function NightAuditAdminPage() {
  const [auditDate, setAuditDate] = useState(() => new Date().toISOString().split('T')[0])
  const [runModalOpen, setRunModalOpen] = useState(false)
  const [auditNotes, setAuditNotes] = useState('')

  const qc = useQueryClient()

  const checklistQuery = useQuery({
    queryKey: ['night-audit', 'checklist', auditDate],
    queryFn: () => api.get<{ data: any }>(`/api/v1/admin/night-audit/checklist?audit_date=${auditDate}`).then((r) => r.data.data),
  })

  const historyQuery = useQuery({
    queryKey: ['night-audit', 'audits'],
    queryFn: () => api.get<{ data: any[] }>('/api/v1/admin/night-audit/audits').then((r) => r.data.data),
  })

  const runMutation = useMutation({
    mutationFn: () => api.post('/api/v1/admin/night-audit/run', { audit_date: auditDate, notes: auditNotes }),
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['night-audit'] })
      qc.invalidateQueries({ queryKey: ['accounting'] })
      setRunModalOpen(false)
      setAuditNotes('')
    },
  })

  const formatNaira = (kobo: number) => `₦${(kobo / 100).toLocaleString('en-NG', { minimumFractionDigits: 2 })}`

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900 flex items-center gap-2">
            <Moon className="w-7 h-7 text-indigo-600" />
            Night Audit & Daily Business Date Close
          </h1>
          <p className="text-sm text-slate-600">
            Operational checklist, automated room charge & tax posting, and General Ledger daily closure
          </p>
        </div>
        <Button onClick={() => setRunModalOpen(true)} disabled={checklistQuery.data?.can_proceed === false}>
          Execute Night Audit
        </Button>
      </div>

      <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
        <Card className="p-4 space-y-2">
          <div className="text-xs font-semibold text-slate-500 uppercase">Audit Target Date</div>
          <Input type="date" value={auditDate} onChange={(e) => setAuditDate(e.target.value)} />
          <div className="text-xs text-slate-400">In-house guests: {checklistQuery.data?.in_house_stays_count ?? 0}</div>
        </Card>
        <Card className="p-4 space-y-2">
          <div className="text-xs font-semibold text-slate-500 uppercase">Pre-Audit Checklist</div>
          <div className="text-sm space-y-1">
            <div className="flex justify-between">
              <span>Pending Arrivals:</span>
              <span className="font-semibold">{checklistQuery.data?.pending_arrivals_count ?? 0}</span>
            </div>
            <div className="flex justify-between">
              <span>Open POS Orders:</span>
              <span className={`font-semibold ${checklistQuery.data?.open_pos_orders_count > 0 ? 'text-red-600' : 'text-emerald-600'}`}>
                {checklistQuery.data?.open_pos_orders_count ?? 0}
              </span>
            </div>
          </div>
        </Card>
        <Card className="p-4 space-y-2">
          <div className="text-xs font-semibold text-slate-500 uppercase">Readiness Gate</div>
          {checklistQuery.data?.can_proceed ? (
            <Badge tone="success">All Systems Clear for Close</Badge>
          ) : (
            <div className="flex items-center gap-1.5 text-xs text-red-600">
              <AlertCircle className="w-4 h-4" /> Please settle or void all open POS orders before closing.
            </div>
          )}
        </Card>
      </div>

      {/* History table */}
      <Card className="p-4 space-y-4">
        <h3 className="font-semibold text-base">Historical Night Audit Runs</h3>
        <DataTable
          rows={historyQuery.data ?? []}
          rowKey={(r: any) => r.id}
          columns={[
            { key: 'audit_date', header: 'Date', cell: (r: any) => <span className="font-semibold">{r.audit_date}</span> },
            {
              key: 'status',
              header: 'Status',
              cell: (r: any) => <Badge tone={r.status === 'completed' ? 'success' : 'danger'}>{r.status.toUpperCase()}</Badge>,
            },
            { key: 'occupancy', header: 'Occupancy', cell: (r: any) => `${r.occupancy_percentage}% (${r.occupied_rooms}/${r.total_rooms})` },
            { key: 'adr', header: 'ADR', cell: (r: any) => formatNaira(r.adr_minor) },
            { key: 'revpar', header: 'RevPAR', cell: (r: any) => formatNaira(r.revpar_minor) },
            { key: 'total_revenue', header: 'Total Revenue', cell: (r: any) => formatNaira(r.total_revenue_minor) },
            { key: 'completed_at', header: 'Completed', cell: (r: any) => r.completed_at || '—' },
          ]}
        />
      </Card>

      {/* Run Confirmation Modal */}
      <Modal open={runModalOpen} onOpenChange={setRunModalOpen} title={`Execute Night Audit for ${auditDate}`}>
        <div className="space-y-4">
          <p className="text-sm text-slate-600">
            This will post room night charges and VAT to all in-house guest folios, aggregate daily revenue, generate a
            General Ledger journal entry, and roll the hotel business date to the next calendar day.
          </p>
          <FormField id="audit-notes" label="Audit Notes">
            <Input id="audit-notes" value={auditNotes} onChange={(e) => setAuditNotes(e.target.value)} placeholder="Optional closure notes" />
          </FormField>
          <div className="flex justify-end gap-2">
            <Button variant="secondary" onClick={() => setRunModalOpen(false)}>
              Cancel
            </Button>
            <Button disabled={runMutation.isPending} onClick={() => runMutation.mutate()}>
              Confirm & Roll Date
            </Button>
          </div>
        </div>
      </Modal>
    </div>
  )
}

export function AnalyticsAdminPage() {
  const [startDate, setStartDate] = useState(() => {
    const d = new Date()
    d.setDate(d.getDate() - 30)
    return d.toISOString().split('T')[0]
  })
  const [endDate, setEndDate] = useState(() => new Date().toISOString().split('T')[0])

  const analyticsQuery = useQuery({
    queryKey: ['analytics', startDate, endDate],
    queryFn: () => api.get<{ data: any }>(`/api/v1/admin/analytics/kpi-summary?start_date=${startDate}&end_date=${endDate}`).then((r) => r.data.data),
  })

  const formatNaira = (kobo: number) => `₦${(kobo / 100).toLocaleString('en-NG', { minimumFractionDigits: 2 })}`

  return (
    <div className="space-y-6">
      <div className="flex flex-col gap-2 sm:flex-row sm:items-center sm:justify-between">
        <div>
          <h1 className="text-2xl font-semibold text-slate-900 flex items-center gap-2">
            <BarChart2 className="w-7 h-7 text-emerald-600" />
            Hotel Analytics & KPI Dashboard
          </h1>
          <p className="text-sm text-slate-600">
            Occupancy, Average Daily Rate (ADR), RevPAR, and departmental performance trends
          </p>
        </div>
        <div className="flex items-center gap-2">
          <Input type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} className="w-36 text-xs" />
          <span className="text-xs text-slate-500">to</span>
          <Input type="date" value={endDate} onChange={(e) => setEndDate(e.target.value)} className="w-36 text-xs" />
        </div>
      </div>

      {/* KPI Cards */}
      <div className="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
        <Card className="p-5 space-y-1">
          <div className="text-xs font-semibold text-slate-500 uppercase">Average Occupancy</div>
          <div className="text-2xl font-bold text-slate-900">
            {analyticsQuery.data?.kpis?.average_occupancy_percentage ?? 0}%
          </div>
          <div className="text-xs text-slate-500">Across {analyticsQuery.data?.period?.days_count ?? 0} audited days</div>
        </Card>
        <Card className="p-5 space-y-1">
          <div className="text-xs font-semibold text-slate-500 uppercase">Average Daily Rate (ADR)</div>
          <div className="text-2xl font-bold text-slate-900">
            {formatNaira(analyticsQuery.data?.kpis?.average_adr_minor ?? 0)}
          </div>
          <div className="text-xs text-slate-500">Per occupied room</div>
        </Card>
        <Card className="p-5 space-y-1">
          <div className="text-xs font-semibold text-slate-500 uppercase">RevPAR</div>
          <div className="text-2xl font-bold text-slate-900">
            {formatNaira(analyticsQuery.data?.kpis?.average_revpar_minor ?? 0)}
          </div>
          <div className="text-xs text-slate-500">Per available room</div>
        </Card>
        <Card className="p-5 space-y-1">
          <div className="text-xs font-semibold text-slate-500 uppercase">Total Period Revenue</div>
          <div className="text-2xl font-bold text-emerald-600">
            {formatNaira(analyticsQuery.data?.kpis?.total_revenue_minor ?? 0)}
          </div>
          <div className="text-xs text-slate-500">Rooms + Food & Beverage</div>
        </Card>
      </div>

      {/* Daily Performance Trend Table */}
      <Card className="p-4 space-y-4">
        <h3 className="font-semibold text-base">Daily Performance Trend</h3>
        <DataTable
          rows={analyticsQuery.data?.daily_trend ?? []}
          rowKey={(r: any) => r.date}
          columns={[
            { key: 'date', header: 'Audit Date', cell: (r: any) => <span className="font-semibold">{r.date}</span> },
            { key: 'occupancy', header: 'Occupancy', cell: (r: any) => `${r.occupancy_percentage}%` },
            { key: 'adr', header: 'ADR', cell: (r: any) => formatNaira(r.adr_minor) },
            { key: 'revpar', header: 'RevPAR', cell: (r: any) => formatNaira(r.revpar_minor) },
            { key: 'revenue', header: 'Total Revenue', cell: (r: any) => formatNaira(r.revenue_minor) },
          ]}
        />
      </Card>
    </div>
  )
}
