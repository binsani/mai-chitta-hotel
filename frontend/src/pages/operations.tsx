import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  AlertTriangle, CheckCircle2, ClipboardCheck, Play, Plus, Printer,
  Receipt, RotateCcw, Search, UserCheck,
} from 'lucide-react'
import { useAuth } from '@/auth/AuthProvider'
import { api, errorMessage } from '@/lib/api'
import { formatCurrency, type PhysicalRoomRecord } from '@/lib/cms'
import {
  Alert, Badge, Button, Card, CardHeader, DataTable, EmptyState, ErrorState,
  FormField, Input, Modal, PageHeader, Select, Spinner, Textarea,
} from '@/components/ui'

function statusTone(status: string): 'neutral' | 'brand' | 'success' | 'warning' | 'danger' {
  switch (status) {
    case 'inspected':
    case 'clean':
    case 'ready':
    case 'passed':
    case 'resolved':
    case 'closed':
    case 'posted':
    case 'settled':
      return 'success'
    case 'in_progress':
    case 'assigned':
    case 'acknowledged':
    case 'open':
      return 'brand'
    case 'pending':
    case 'completed':
    case 'inspecting':
    case 'awaiting_cleaning':
    case 'awaiting_inspection':
    case 'awaiting_parts':
    case 'reported':
    case 'pending_approval':
    case 'dirty':
      return 'warning'
    case 'failed_inspection':
    case 'failed':
    case 'cancelled':
    case 'rejected':
    case 'maintenance':
    case 'blocked_by_maintenance':
    case 'out_of_service':
    case 'emergency':
    case 'urgent':
      return 'danger'
    default:
      return 'neutral'
  }
}

/* ========================================================================
 * TYPES
 * ======================================================================== */
export type HousekeepingTaskRecord = {
  id: number
  task_reference: string
  room_id: number
  reservation_id: number | null
  parent_task_id: number | null
  task_type: string
  priority: 'low' | 'normal' | 'high' | 'urgent'
  status: 'pending' | 'assigned' | 'in_progress' | 'completed' | 'inspected' | 'failed_inspection' | 'cancelled'
  scheduled_date: string
  due_time: string | null
  title: string
  instructions: string | null
  completion_notes: string | null
  internal_notes: string | null
  assigned_to: number | null
  started_at: string | null
  completed_at: string | null
  inspected_at: string | null
  room?: PhysicalRoomRecord & { readiness_status?: string }
  assignee?: { id: number; name: string; email: string } | null
  completer?: { id: number; name: string } | null
  inspector?: { id: number; name: string } | null
  reservation?: { id: number; reference: string; guest_name: string; status: string } | null
  inspections?: {
    id: number
    outcome: 'passed' | 'failed'
    notes: string | null
    inspected_at: string
    inspector?: { id: number; name: string }
  }[]
  histories?: {
    id: number
    from_status: string | null
    to_status: string
    notes: string | null
    created_at: string
    actor?: { id: number; name: string }
  }[]
}

export type MaintenanceWorkOrderRecord = {
  id: number
  work_order_reference: string
  room_id: number | null
  area_label: string | null
  category: string
  priority: 'low' | 'normal' | 'high' | 'emergency'
  status: 'reported' | 'acknowledged' | 'assigned' | 'in_progress' | 'awaiting_parts' | 'resolved' | 'closed' | 'cancelled'
  title: string
  description: string
  diagnosis_notes: string | null
  resolution_notes: string | null
  parts_Wait_notes: string | null
  blocks_room: boolean
  blocked_from: string | null
  blocked_until: string | null
  has_arrival_conflict: boolean
  conflicting_reservation_ids: number[] | null
  currency_code: string
  parts_cost?: number
  labor_cost?: number
  total_cost?: number
  vendor_name?: string | null
  vendor_invoice_reference?: string | null
  reported_at: string
  resolved_at: string | null
  closed_at: string | null
  room?: PhysicalRoomRecord
  reporter?: { id: number; name: string } | null
  assignee?: { id: number; name: string; email: string } | null
  resolver?: { id: number; name: string } | null
  followup_housekeeping_task?: { id: number; task_reference: string; status: string } | null
  histories?: {
    id: number
    from_status: string | null
    to_status: string
    notes: string | null
    created_at: string
    actor?: { id: number; name: string }
  }[]
}

export type FolioEntryRecord = {
  id: number
  entry_reference: string
  guest_folio_id: number
  reservation_id: number
  entry_type: string
  direction: 'charge' | 'credit'
  category: string
  description: string
  service_date: string
  quantity: number
  currency_code: string
  unit_amount_minor: number
  net_amount_minor: number
  tax_amount_minor: number
  service_charge_minor: number
  total_amount_minor: number
  total_amount: number
  status: 'pending_approval' | 'posted' | 'rejected'
  source_type: string | null
  reason: string | null
  reversed_entry_id: number | null
  posted_at: string | null
  requester?: { id: number; name: string } | null
  approver?: { id: number; name: string } | null
  poster?: { id: number; name: string } | null
}

export type FolioPaymentAllocationRecord = {
  id: number
  allocation_reference: string
  payment_transaction_id: number
  allocation_type: string
  currency_code: string
  allocated_amount_minor: number
  allocated_amount: number
  notes: string | null
  allocated_at: string
  payment_transaction?: {
    id: number
    transaction_reference: string
    method: string
    provider: string
    receipt_number: string | null
    amount: number
  }
  allocator?: { id: number; name: string } | null
}

export type GuestFolioRecord = {
  id: number
  folio_reference: string
  reservation_id: number
  guest_name: string
  status: 'open' | 'settled' | 'closed' | 'voided'
  currency_code: string
  total_charges_amount: number
  total_credits_amount: number
  total_payments_amount: number
  total_refunds_amount: number
  balance_due_amount: number
  balance_due_minor: number
  settled_with_exception: boolean
  settlement_exception_reason: string | null
  settled_at: string | null
  closed_at: string | null
  room?: { id: number; room_number: string; floor?: string | null } | null
  reservation?: {
    id: number
    reference: string
    status: string
    check_in_date: string
    check_out_date: string
    nights: number
    room_type?: { id: number; name: string }
    payments?: { id: number; transaction_reference: string; amount: number; method: string; status: string; type: string }[]
  }
  entries?: FolioEntryRecord[]
  payment_allocations?: FolioPaymentAllocationRecord[]
}

/* ========================================================================
 * 1. HOUSEKEEPING ADMIN PAGE (/admin/housekeeping)
 * ======================================================================== */
export function HousekeepingAdminPage() {
  const { can } = useAuth()
  const qc = useQueryClient()
  const [statusFilter, setStatusFilter] = useState('')
  const [priorityFilter, setPriorityFilter] = useState('')
  const [search, setSearch] = useState('')
  const [creating, setCreating] = useState(false)
  const [newTask, setNewTask] = useState({
    room_id: '',
    task_type: 'checkout_cleaning',
    priority: 'normal',
    scheduled_date: '',
    title: '',
    instructions: '',
    assigned_to: '',
  })
  const [selectedTask, setSelectedTask] = useState<HousekeepingTaskRecord | null>(null)
  const [assignUserId, setAssignUserId] = useState('')
  const [completionNotes, setCompletionNotes] = useState('')
  const [inspectionOutcome, setInspectionOutcome] = useState<'passed' | 'failed'>('passed')
  const [inspectionNotes, setInspectionNotes] = useState('')
  const [msg, setMsg] = useState<{ t: 'success' | 'error'; m: string } | null>(null)

  const q = useQuery({
    queryKey: ['admin', 'housekeeping', statusFilter, priorityFilter, search],
    queryFn: async () => {
      const params = new URLSearchParams()
      if (statusFilter) params.set('status', statusFilter)
      if (priorityFilter) params.set('priority', priorityFilter)
      if (search) params.set('search', search)
      return (await api.get<{
        data: HousekeepingTaskRecord[]
        summary: Record<string, number>
        rooms: (PhysicalRoomRecord & { readiness_status?: string })[]
        assignable_staff: { id: number; name: string; email: string }[]
      }>(`/admin/housekeeping/tasks?${params.toString()}`)).data
    },
  })

  const createMut = useMutation({
    mutationFn: async () =>
      (await api.post<{ message: string; data: HousekeepingTaskRecord }>('/admin/housekeeping/tasks', {
        room_id: Number(newTask.room_id),
        task_type: newTask.task_type,
        priority: newTask.priority,
        scheduled_date: newTask.scheduled_date || undefined,
        title: newTask.title || undefined,
        instructions: newTask.instructions || undefined,
        assigned_to: newTask.assigned_to ? Number(newTask.assigned_to) : null,
      })).data,
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['admin', 'housekeeping'] })
      setMsg({ t: 'success', m: res.message })
      setCreating(false)
    },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  const actionMut = useMutation({
    mutationFn: async ({ id, action, body }: { id: number; action: string; body?: Record<string, unknown> }) =>
      (await api.post<{ message: string; data: HousekeepingTaskRecord }>(`/admin/housekeeping/tasks/${id}/${action}`, body ?? {})).data,
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['admin', 'housekeeping'] })
      setMsg({ t: 'success', m: res.message })
      setSelectedTask(res.data)
    },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  const summary = q.data?.summary
  const rooms = q.data?.rooms ?? []
  const staff = q.data?.assignable_staff ?? []

  return (
    <div className="space-y-6">
      <PageHeader
        title="Housekeeping & Room Readiness"
        description="Schedule, assign, complete, and inspect room cleaning tasks across Mai Chitta Hotel."
        actions={
          can('housekeeping.manage', 'housekeeping.assign') ? (
            <Button onClick={() => { setMsg(null); setCreating(true) }}>
              <Plus className="h-4 w-4" /> New Cleaning Task
            </Button>
          ) : undefined
        }
      />

      {msg && <Alert variant={msg.t === 'error' ? 'error' : 'success'} title={msg.m} />}

      {summary && (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
          <Card className="p-4">
            <p className="text-xs font-medium uppercase text-slate-500">Dirty Rooms</p>
            <p className="mt-1 text-2xl font-bold text-amber-600">{summary.rooms_dirty ?? 0}</p>
            <p className="text-xs text-slate-400">Awaiting cleaning</p>
          </Card>
          <Card className="p-4">
            <p className="text-xs font-medium uppercase text-slate-500">Awaiting Inspection</p>
            <p className="mt-1 text-2xl font-bold text-brand-700">{summary.completed_awaiting_inspection ?? 0}</p>
            <p className="text-xs text-slate-400">Completed tasks to verify</p>
          </Card>
          <Card className="p-4">
            <p className="text-xs font-medium uppercase text-slate-500">In Progress / Assigned</p>
            <p className="mt-1 text-2xl font-bold text-slate-900">{(summary.in_progress ?? 0) + (summary.assigned ?? 0)}</p>
            <p className="text-xs text-slate-400">{summary.pending ?? 0} unassigned pending</p>
          </Card>
          <Card className="p-4">
            <p className="text-xs font-medium uppercase text-slate-500">Ready Rooms</p>
            <p className="mt-1 text-2xl font-bold text-emerald-700">{summary.rooms_ready ?? 0}</p>
            <p className="text-xs text-slate-400">Clean & ready for check-in</p>
          </Card>
          <Card className="p-4">
            <p className="text-xs font-medium uppercase text-slate-500">Failed Inspections</p>
            <p className="mt-1 text-2xl font-bold text-rose-600">{summary.failed_inspection ?? 0}</p>
            <p className="text-xs text-slate-400">Follow-up required</p>
          </Card>
        </div>
      )}

      {rooms.length > 0 && (
        <Card>
          <CardHeader title="Room Readiness Board" description="Live physical room cleanliness, inspection, and maintenance readiness." />
          <div className="grid gap-3 p-5 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
            {rooms.map((rm) => (
              <div key={rm.id} className="rounded-lg border border-slate-200 p-3 text-sm">
                <div className="flex items-center justify-between">
                  <span className="font-bold text-slate-900">Room {rm.room_number}</span>
                  <Badge tone={statusTone(rm.readiness_status || rm.housekeeping_status)}>
                    {(rm.readiness_status || rm.housekeeping_status).replace(/_/g, ' ')}
                  </Badge>
                </div>
                <p className="mt-1 truncate text-xs text-slate-500">{rm.room_type?.name}</p>
                <p className="mt-0.5 text-[11px] text-slate-400">HK: {rm.housekeeping_status}</p>
              </div>
            ))}
          </div>
        </Card>
      )}

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4">
          <div className="relative flex-1 min-w-[200px]">
            <Search className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
            <Input
              className="pl-9"
              placeholder="Search reference, room number, title..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
          <Select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)} className="w-44">
            <option value="">All Statuses</option>
            <option value="pending">Pending</option>
            <option value="assigned">Assigned</option>
            <option value="in_progress">In Progress</option>
            <option value="completed">Completed (Inspect)</option>
            <option value="inspected">Inspected</option>
            <option value="failed_inspection">Failed Inspection</option>
            <option value="cancelled">Cancelled</option>
          </Select>
          <Select value={priorityFilter} onChange={(e) => setPriorityFilter(e.target.value)} className="w-40">
            <option value="">All Priorities</option>
            <option value="urgent">Urgent</option>
            <option value="high">High</option>
            <option value="normal">Normal</option>
            <option value="low">Low</option>
          </Select>
        </div>

        {q.isLoading ? (
          <Spinner />
        ) : q.isError ? (
          <ErrorState message={errorMessage(q.error)} onRetry={() => q.refetch()} />
        ) : !q.data?.data.length ? (
          <EmptyState title="No housekeeping tasks found" description="Create a cleaning task or adjust your filters." />
        ) : (
          <DataTable
            rows={q.data.data}
            rowKey={(t) => t.id}
            columns={[
              { key: 'ref', header: 'Reference', cell: (t) => <span className="font-mono text-xs font-semibold">{t.task_reference}</span> },
              { key: 'room', header: 'Room', cell: (t) => <span className="font-medium">Room {t.room?.room_number ?? t.room_id}</span> },
              {
                key: 'task',
                header: 'Task',
                cell: (t) => (
                  <div>
                    <div className="font-medium text-slate-900">{t.title}</div>
                    <div className="text-xs text-slate-500">{t.task_type.replace(/_/g, ' ')} · Scheduled: {t.scheduled_date}</div>
                  </div>
                ),
              },
              { key: 'priority', header: 'Priority', cell: (t) => <Badge tone={statusTone(t.priority)}>{t.priority}</Badge> },
              { key: 'status', header: 'Status', cell: (t) => <Badge tone={statusTone(t.status)}>{t.status.replace(/_/g, ' ')}</Badge> },
              { key: 'assignee', header: 'Assigned To', cell: (t) => t.assignee?.name ?? <span className="text-xs text-slate-400">Unassigned</span> },
              {
                key: 'actions',
                header: 'Actions',
                cell: (t) => (
                  <div className="flex items-center gap-2">
                    <Button
                      size="sm"
                      variant="secondary"
                      onClick={() => {
                        setSelectedTask(t)
                        setAssignUserId(t.assigned_to ? String(t.assigned_to) : '')
                        setCompletionNotes(t.completion_notes || '')
                        setInspectionNotes('')
                      }}
                    >
                      Manage
                    </Button>
                  </div>
                ),
              },
            ]}
          />
        )}
      </Card>

      <Modal open={creating} onOpenChange={(o) => !o && setCreating(false)} title="Create Housekeeping Task">
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault()
            createMut.mutate()
          }}
        >
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField id="hk-room" label="Physical Room *">
              <Select id="hk-room" value={newTask.room_id} onChange={(e) => setNewTask({ ...newTask, room_id: e.target.value })} required>
                <option value="">Select room...</option>
                {rooms.map((r) => (
                  <option key={r.id} value={r.id}>Room {r.room_number} ({r.housekeeping_status})</option>
                ))}
              </Select>
            </FormField>
            <FormField id="hk-type" label="Task Type *">
              <Select id="hk-type" value={newTask.task_type} onChange={(e) => setNewTask({ ...newTask, task_type: e.target.value })}>
                <option value="checkout_cleaning">Checkout Cleaning</option>
                <option value="stayover_cleaning">Stayover Cleaning</option>
                <option value="deep_cleaning">Deep Cleaning</option>
                <option value="inspection_followup">Inspection Follow-up</option>
                <option value="maintenance_followup">Post-Maintenance Cleaning</option>
                <option value="manual_request">Manual Guest/Staff Request</option>
              </Select>
            </FormField>
            <FormField id="hk-priority" label="Priority *">
              <Select id="hk-priority" value={newTask.priority} onChange={(e) => setNewTask({ ...newTask, priority: e.target.value })}>
                <option value="low">Low</option>
                <option value="normal">Normal</option>
                <option value="high">High</option>
                <option value="urgent">Urgent</option>
              </Select>
            </FormField>
            <FormField id="hk-date" label="Scheduled Date">
              <Input id="hk-date" type="date" value={newTask.scheduled_date} onChange={(e) => setNewTask({ ...newTask, scheduled_date: e.target.value })} />
            </FormField>
          </div>
          <FormField id="hk-assignee" label="Assign To Staff (Optional)">
            <Select id="hk-assignee" value={newTask.assigned_to} onChange={(e) => setNewTask({ ...newTask, assigned_to: e.target.value })}>
              <option value="">Leave unassigned (Pending)</option>
              {staff.map((s) => (
                <option key={s.id} value={s.id}>{s.name} ({s.email})</option>
              ))}
            </Select>
          </FormField>
          <FormField id="hk-title" label="Custom Title (Optional)">
            <Input id="hk-title" value={newTask.title} onChange={(e) => setNewTask({ ...newTask, title: e.target.value })} placeholder="Auto-generated if left blank" />
          </FormField>
          <FormField id="hk-inst" label="Cleaning Instructions">
            <Textarea id="hk-inst" rows={3} value={newTask.instructions} onChange={(e) => setNewTask({ ...newTask, instructions: e.target.value })} />
          </FormField>
          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" onClick={() => setCreating(false)}>Cancel</Button>
            <Button type="submit" loading={createMut.isPending}>Create Task</Button>
          </div>
        </form>
      </Modal>

      <Modal open={!!selectedTask} onOpenChange={(o) => !o && setSelectedTask(null)} title={selectedTask ? `Task ${selectedTask.task_reference} — Room ${selectedTask.room?.room_number ?? ''}` : ''}>
        {selectedTask && (
          <div className="space-y-5 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-slate-50 p-3">
              <div>
                <p className="font-semibold text-slate-900">{selectedTask.title}</p>
                <p className="text-xs text-slate-500">Type: {selectedTask.task_type.replace(/_/g, ' ')} · Scheduled: {selectedTask.scheduled_date}</p>
              </div>
              <Badge tone={statusTone(selectedTask.status)}>{selectedTask.status.replace(/_/g, ' ')}</Badge>
            </div>

            {selectedTask.instructions && (
              <div className="rounded border border-slate-200 p-3">
                <p className="text-xs font-semibold uppercase text-slate-400">Instructions</p>
                <p className="mt-1 text-slate-700">{selectedTask.instructions}</p>
              </div>
            )}

            {can('housekeeping.assign') && ['pending', 'assigned', 'in_progress'].includes(selectedTask.status) && (
              <div className="rounded border border-slate-200 p-3 space-y-2">
                <p className="font-medium text-slate-800">Assign / Reassign Housekeeper</p>
                <div className="flex gap-2">
                  <Select value={assignUserId} onChange={(e) => setAssignUserId(e.target.value)} className="flex-1">
                    <option value="">Select staff member...</option>
                    {staff.map((s) => (
                      <option key={s.id} value={s.id}>{s.name}</option>
                    ))}
                  </Select>
                  <Button
                    size="sm"
                    disabled={!assignUserId}
                    loading={actionMut.isPending}
                    onClick={() => actionMut.mutate({ id: selectedTask.id, action: 'assign', body: { assigned_to: Number(assignUserId) } })}
                  >
                    <UserCheck className="h-4 w-4" /> Assign
                  </Button>
                </div>
              </div>
            )}

            {can('housekeeping.complete', 'housekeeping.update', 'housekeeping.manage') && (
              <div className="space-y-3">
                {['pending', 'assigned'].includes(selectedTask.status) && (
                  <Button
                    onClick={() => actionMut.mutate({ id: selectedTask.id, action: 'start' })}
                    loading={actionMut.isPending}
                  >
                    <Play className="h-4 w-4" /> Start Cleaning Now
                  </Button>
                )}

                {['assigned', 'in_progress'].includes(selectedTask.status) && (
                  <div className="rounded border border-slate-200 p-3 space-y-2">
                    <FormField id="hk-comp-notes" label="Completion Notes">
                      <Textarea id="hk-comp-notes" rows={2} value={completionNotes} onChange={(e) => setCompletionNotes(e.target.value)} placeholder="Notes for supervisor inspection..." />
                    </FormField>
                    <Button
                      onClick={() => actionMut.mutate({ id: selectedTask.id, action: 'complete', body: { completion_notes: completionNotes } })}
                      loading={actionMut.isPending}
                    >
                      <CheckCircle2 className="h-4 w-4" /> Mark Cleaning Completed
                    </Button>
                  </div>
                )}
              </div>
            )}

            {can('housekeeping.inspect') && selectedTask.status === 'completed' && (
              <div className="rounded border border-brand-200 bg-brand-50/40 p-4 space-y-3">
                <p className="font-semibold text-slate-900 flex items-center gap-1.5">
                  <ClipboardCheck className="h-4 w-4 text-brand-700" /> Supervisor Room Inspection
                </p>
                <div className="grid gap-3 sm:grid-cols-2">
                  <FormField id="hk-insp-outcome" label="Inspection Outcome *">
                    <Select id="hk-insp-outcome" value={inspectionOutcome} onChange={(e) => setInspectionOutcome(e.target.value as 'passed' | 'failed')}>
                      <option value="passed">Passed — Mark Room Ready</option>
                      <option value="failed">Failed — Return Room to Dirty & Re-clean</option>
                    </Select>
                  </FormField>
                </div>
                <FormField id="hk-insp-notes" label="Inspection Remarks">
                  <Textarea id="hk-insp-notes" rows={2} value={inspectionNotes} onChange={(e) => setInspectionNotes(e.target.value)} placeholder="Verify linens, bathroom sanitation, amenities..." />
                </FormField>
                <Button
                  onClick={() => actionMut.mutate({
                    id: selectedTask.id,
                    action: 'inspect',
                    body: { outcome: inspectionOutcome, notes: inspectionNotes },
                  })}
                  loading={actionMut.isPending}
                >
                  Submit Inspection ({inspectionOutcome.toUpperCase()})
                </Button>
              </div>
            )}
          </div>
        )}
      </Modal>
    </div>
  )
}

/* ========================================================================
 * 2. MAINTENANCE WORK ORDERS ADMIN PAGE (/admin/maintenance)
 * ======================================================================== */
export function MaintenanceAdminPage() {
  const { can } = useAuth()
  const qc = useQueryClient()
  const [statusFilter, setStatusFilter] = useState('')
  const [priorityFilter, setPriorityFilter] = useState('')
  const [search, setSearch] = useState('')
  const [creating, setCreating] = useState(false)
  const [newOrder, setNewOrder] = useState({
    room_id: '',
    area_label: '',
    category: 'plumbing',
    priority: 'normal',
    title: '',
    description: '',
    blocks_room: false,
    mark_out_of_service: false,
    assigned_to: '',
  })
  const [selectedOrder, setSelectedOrder] = useState<MaintenanceWorkOrderRecord | null>(null)
  const [updateForm, setUpdateForm] = useState({
    status: '',
    diagnosis_notes: '',
    resolution_notes: '',
    parts_cost: '',
    labor_cost: '',
    vendor_name: '',
  })
  const [msg, setMsg] = useState<{ t: 'success' | 'error'; m: string } | null>(null)

  const q = useQuery({
    queryKey: ['admin', 'maintenance', statusFilter, priorityFilter, search],
    queryFn: async () => {
      const params = new URLSearchParams()
      if (statusFilter) params.set('status', statusFilter)
      if (priorityFilter) params.set('priority', priorityFilter)
      if (search) params.set('search', search)
      return (await api.get<{
        data: MaintenanceWorkOrderRecord[]
        summary: Record<string, number>
        rooms: PhysicalRoomRecord[]
        technicians: { id: number; name: string; email: string }[]
      }>(`/admin/maintenance/work-orders?${params.toString()}`)).data
    },
  })

  const createMut = useMutation({
    mutationFn: async () =>
      (await api.post<{ message: string; data: MaintenanceWorkOrderRecord }>('/admin/maintenance/work-orders', {
        room_id: newOrder.room_id ? Number(newOrder.room_id) : null,
        area_label: newOrder.area_label || undefined,
        category: newOrder.category,
        priority: newOrder.priority,
        title: newOrder.title,
        description: newOrder.description,
        blocks_room: newOrder.blocks_room,
        mark_out_of_service: newOrder.mark_out_of_service,
        assigned_to: newOrder.assigned_to ? Number(newOrder.assigned_to) : null,
      })).data,
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['admin', 'maintenance'] })
      setMsg({ t: 'success', m: res.message })
      setCreating(false)
    },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  const updateMut = useMutation({
    mutationFn: async ({ id, body }: { id: number; body: Record<string, unknown> }) =>
      (await api.put<{ message: string; data: MaintenanceWorkOrderRecord }>(`/admin/maintenance/work-orders/${id}`, body)).data,
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['admin', 'maintenance'] })
      setMsg({ t: 'success', m: res.message })
      setSelectedOrder(res.data)
    },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  const summary = q.data?.summary
  const rooms = q.data?.rooms ?? []
  const technicians = q.data?.technicians ?? []

  return (
    <div className="space-y-6">
      <PageHeader
        title="Maintenance Work Orders"
        description="Track room and facility repairs, room-blocking maintenance holds, and post-repair housekeeping handoffs."
        actions={
          can('maintenance.create') ? (
            <Button onClick={() => { setMsg(null); setCreating(true) }}>
              <Plus className="h-4 w-4" /> Report Issue
            </Button>
          ) : undefined
        }
      />

      {msg && <Alert variant={msg.t === 'error' ? 'error' : 'success'} title={msg.m} />}

      {summary && (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-5">
          <Card className="p-4">
            <p className="text-xs font-medium uppercase text-slate-500">Open Work Orders</p>
            <p className="mt-1 text-2xl font-bold text-slate-900">{summary.open_orders ?? 0}</p>
            <p className="text-xs text-rose-600">{summary.emergency_open ?? 0} emergency</p>
          </Card>
          <Card className="p-4">
            <p className="text-xs font-medium uppercase text-slate-500">Blocking Rooms</p>
            <p className="mt-1 text-2xl font-bold text-amber-600">{summary.blocking_rooms ?? 0}</p>
            <p className="text-xs text-slate-400">Cannot check in until resolved</p>
          </Card>
          <Card className="p-4">
            <p className="text-xs font-medium uppercase text-slate-500">Arrival Conflicts</p>
            <p className="mt-1 text-2xl font-bold text-rose-600">{summary.arrival_conflicts ?? 0}</p>
            <p className="text-xs text-slate-400">Assigned stays needing room move</p>
          </Card>
          <Card className="p-4">
            <p className="text-xs font-medium uppercase text-slate-500">Awaiting Parts</p>
            <p className="mt-1 text-2xl font-bold text-brand-700">{summary.awaiting_parts ?? 0}</p>
            <p className="text-xs text-slate-400">{summary.resolved ?? 0} resolved</p>
          </Card>
          {can('maintenance.costs.view') && summary.total_repair_cost_amount !== undefined && (
            <Card className="p-4">
              <p className="text-xs font-medium uppercase text-slate-500">Recorded Repair Costs</p>
              <p className="mt-1 text-2xl font-bold text-slate-900">{formatCurrency(summary.total_repair_cost_amount)}</p>
              <p className="text-xs text-slate-400">Parts + labor</p>
            </Card>
          )}
        </div>
      )}

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4">
          <div className="relative flex-1 min-w-[200px]">
            <Search className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
            <Input
              className="pl-9"
              placeholder="Search work order ref, room, title..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
          <Select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)} className="w-44">
            <option value="">All Statuses</option>
            <option value="reported">Reported</option>
            <option value="acknowledged">Acknowledged</option>
            <option value="assigned">Assigned</option>
            <option value="in_progress">In Progress</option>
            <option value="awaiting_parts">Awaiting Parts</option>
            <option value="resolved">Resolved</option>
            <option value="closed">Closed</option>
          </Select>
          <Select value={priorityFilter} onChange={(e) => setPriorityFilter(e.target.value)} className="w-40">
            <option value="">All Priorities</option>
            <option value="emergency">Emergency</option>
            <option value="high">High</option>
            <option value="normal">Normal</option>
            <option value="low">Low</option>
          </Select>
        </div>

        {q.isLoading ? (
          <Spinner />
        ) : q.isError ? (
          <ErrorState message={errorMessage(q.error)} onRetry={() => q.refetch()} />
        ) : !q.data?.data.length ? (
          <EmptyState title="No maintenance work orders" description="Report a room or facility maintenance issue to begin." />
        ) : (
          <DataTable
            rows={q.data.data}
            rowKey={(wo) => wo.id}
            columns={[
              { key: 'ref', header: 'Reference', cell: (wo) => <span className="font-mono text-xs font-semibold">{wo.work_order_reference}</span> },
              {
                key: 'loc',
                header: 'Location',
                cell: (wo) => (
                  <div>
                    <div className="font-medium text-slate-900">{wo.room ? `Room ${wo.room.room_number}` : wo.area_label || 'General'}</div>
                    {wo.blocks_room && <span className="text-[11px] font-semibold text-rose-600">Blocks Room</span>}
                    {wo.has_arrival_conflict && <span className="ml-1 text-[11px] font-semibold text-amber-700">· Arrival Conflict!</span>}
                  </div>
                ),
              },
              {
                key: 'issue',
                header: 'Issue',
                cell: (wo) => (
                  <div>
                    <div className="font-medium text-slate-900">{wo.title}</div>
                    <div className="text-xs text-slate-500">{wo.category.replace(/_/g, ' ')}</div>
                  </div>
                ),
              },
              { key: 'priority', header: 'Priority', cell: (wo) => <Badge tone={statusTone(wo.priority)}>{wo.priority}</Badge> },
              { key: 'status', header: 'Status', cell: (wo) => <Badge tone={statusTone(wo.status)}>{wo.status.replace(/_/g, ' ')}</Badge> },
              { key: 'tech', header: 'Technician', cell: (wo) => wo.assignee?.name ?? <span className="text-xs text-slate-400">Unassigned</span> },
              ...(can('maintenance.costs.view')
                ? [{ key: 'cost', header: 'Total Cost', cell: (wo: MaintenanceWorkOrderRecord) => formatCurrency(wo.total_cost ?? 0, wo.currency_code) }]
                : []),
              {
                key: 'actions',
                header: 'Actions',
                cell: (wo) => (
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => {
                      setSelectedOrder(wo)
                      setUpdateForm({
                        status: wo.status,
                        diagnosis_notes: wo.diagnosis_notes || '',
                        resolution_notes: wo.resolution_notes || '',
                        parts_cost: wo.parts_cost !== undefined ? String(wo.parts_cost) : '0',
                        labor_cost: wo.labor_cost !== undefined ? String(wo.labor_cost) : '0',
                        vendor_name: wo.vendor_name || '',
                      })
                    }}
                  >
                    Manage
                  </Button>
                ),
              },
            ]}
          />
        )}
      </Card>

      <Modal open={creating} onOpenChange={(o) => !o && setCreating(false)} title="Report Maintenance Work Order">
        <form
          className="space-y-4"
          onSubmit={(e) => {
            e.preventDefault()
            createMut.mutate()
          }}
        >
          <div className="grid gap-4 sm:grid-cols-2">
            <FormField id="mwo-room" label="Physical Room (Optional)">
              <Select id="mwo-room" value={newOrder.room_id} onChange={(e) => setNewOrder({ ...newOrder, room_id: e.target.value })}>
                <option value="">Non-room / Public Facility</option>
                {rooms.map((r) => (
                  <option key={r.id} value={r.id}>Room {r.room_number}</option>
                ))}
              </Select>
            </FormField>
            <FormField id="mwo-area" label="Area / Location Label">
              <Input id="mwo-area" value={newOrder.area_label} onChange={(e) => setNewOrder({ ...newOrder, area_label: e.target.value })} placeholder="e.g. Lobby AC, Generator House..." />
            </FormField>
            <FormField id="mwo-cat" label="Category *">
              <Select id="mwo-cat" value={newOrder.category} onChange={(e) => setNewOrder({ ...newOrder, category: e.target.value })}>
                <option value="plumbing">Plumbing</option>
                <option value="electrical">Electrical</option>
                <option value="hvac">Air Conditioning / HVAC</option>
                <option value="furniture">Furniture & Carpentry</option>
                <option value="appliance">Appliance</option>
                <option value="bathroom">Bathroom Fixture</option>
                <option value="door_lock">Door Lock / Keycard</option>
                <option value="painting_civil">Painting / Civil Works</option>
                <option value="wifi_tv">Wi-Fi / TV / Intercom</option>
                <option value="other">Other</option>
              </Select>
            </FormField>
            <FormField id="mwo-prio" label="Priority *">
              <Select id="mwo-prio" value={newOrder.priority} onChange={(e) => setNewOrder({ ...newOrder, priority: e.target.value })}>
                <option value="low">Low</option>
                <option value="normal">Normal</option>
                <option value="high">High</option>
                <option value="emergency">Emergency</option>
              </Select>
            </FormField>
          </div>
          <FormField id="mwo-title" label="Issue Title *">
            <Input id="mwo-title" value={newOrder.title} onChange={(e) => setNewOrder({ ...newOrder, title: e.target.value })} required placeholder="e.g. Split AC unit not cooling" />
          </FormField>
          <FormField id="mwo-desc" label="Detailed Description *">
            <Textarea id="mwo-desc" rows={3} value={newOrder.description} onChange={(e) => setNewOrder({ ...newOrder, description: e.target.value })} required />
          </FormField>
          {newOrder.room_id && (
            <div className="space-y-2 rounded border border-amber-200 bg-amber-50/50 p-3 text-sm">
              <label className="flex items-center gap-2 font-medium text-slate-800">
                <input
                  type="checkbox"
                  checked={newOrder.blocks_room}
                  onChange={(e) => setNewOrder({ ...newOrder, blocks_room: e.target.checked })}
                />
                Block Room from Check-In Until Resolved
              </label>
            </div>
          )}
          {can('maintenance.assign') && (
            <FormField id="mwo-tech" label="Assign Technician (Optional)">
              <Select id="mwo-tech" value={newOrder.assigned_to} onChange={(e) => setNewOrder({ ...newOrder, assigned_to: e.target.value })}>
                <option value="">Unassigned</option>
                {technicians.map((t) => (
                  <option key={t.id} value={t.id}>{t.name}</option>
                ))}
              </Select>
            </FormField>
          )}
          <div className="flex justify-end gap-2 pt-2">
            <Button type="button" variant="secondary" onClick={() => setCreating(false)}>Cancel</Button>
            <Button type="submit" loading={createMut.isPending}>Submit Work Order</Button>
          </div>
        </form>
      </Modal>

      <Modal open={!!selectedOrder} onOpenChange={(o) => !o && setSelectedOrder(null)} title={selectedOrder ? `Work Order ${selectedOrder.work_order_reference}` : ''}>
        {selectedOrder && (
          <form
            className="space-y-4 text-sm"
            onSubmit={(e) => {
              e.preventDefault()
              updateMut.mutate({
                id: selectedOrder.id,
                body: {
                  status: updateForm.status,
                  diagnosis_notes: updateForm.diagnosis_notes || null,
                  resolution_notes: updateForm.resolution_notes || null,
                  parts_cost: updateForm.parts_cost !== '' ? Number(updateForm.parts_cost) : undefined,
                  labor_cost: updateForm.labor_cost !== '' ? Number(updateForm.labor_cost) : undefined,
                  vendor_name: updateForm.vendor_name || null,
                },
              })
            }}
          >
            <div className="rounded-lg bg-slate-50 p-3">
              <p className="font-semibold text-slate-900">{selectedOrder.title}</p>
              <p className="mt-1 text-slate-600">{selectedOrder.description}</p>
              {selectedOrder.has_arrival_conflict && (
                <p className="mt-2 flex items-center gap-1.5 text-xs font-semibold text-rose-600">
                  <AlertTriangle className="h-4 w-4" /> This room has an upcoming confirmed reservation assigned. Notify Front Desk to reassign room.
                </p>
              )}
              {selectedOrder.followup_housekeeping_task && (
                <p className="mt-2 text-xs text-emerald-700">
                  Follow-up Housekeeping Task: <strong>{selectedOrder.followup_housekeeping_task.task_reference}</strong> ({selectedOrder.followup_housekeeping_task.status})
                </p>
              )}
            </div>

            <FormField id="mwo-upd-status" label="Status Transition *">
              <Select id="mwo-upd-status" value={updateForm.status} onChange={(e) => setUpdateForm({ ...updateForm, status: e.target.value })}>
                <option value="reported">Reported</option>
                <option value="acknowledged">Acknowledged</option>
                <option value="assigned">Assigned</option>
                <option value="in_progress">In Progress</option>
                <option value="awaiting_parts">Awaiting Parts</option>
                <option value="resolved">Resolved (Triggers Room Dirty + Housekeeping Task)</option>
                {can('maintenance.close') && <option value="closed">Closed</option>}
                <option value="cancelled">Cancelled</option>
              </Select>
            </FormField>

            <FormField id="mwo-upd-diag" label="Diagnosis Notes">
              <Textarea id="mwo-upd-diag" rows={2} value={updateForm.diagnosis_notes} onChange={(e) => setUpdateForm({ ...updateForm, diagnosis_notes: e.target.value })} />
            </FormField>

            <FormField id="mwo-upd-res" label="Resolution Notes (Required when marking Resolved)">
              <Textarea id="mwo-upd-res" rows={2} value={updateForm.resolution_notes} onChange={(e) => setUpdateForm({ ...updateForm, resolution_notes: e.target.value })} />
            </FormField>

            {can('maintenance.costs.view', 'maintenance.manage') && (
              <div className="grid gap-3 sm:grid-cols-3">
                <FormField id="mwo-upd-parts" label="Parts Cost (NGN)">
                  <Input id="mwo-upd-parts" type="number" step="0.01" min="0" value={updateForm.parts_cost} onChange={(e) => setUpdateForm({ ...updateForm, parts_cost: e.target.value })} />
                </FormField>
                <FormField id="mwo-upd-labor" label="Labor Cost (NGN)">
                  <Input id="mwo-upd-labor" type="number" step="0.01" min="0" value={updateForm.labor_cost} onChange={(e) => setUpdateForm({ ...updateForm, labor_cost: e.target.value })} />
                </FormField>
                <FormField id="mwo-upd-vendor" label="Vendor / Contractor">
                  <Input id="mwo-upd-vendor" value={updateForm.vendor_name} onChange={(e) => setUpdateForm({ ...updateForm, vendor_name: e.target.value })} />
                </FormField>
              </div>
            )}

            <div className="flex justify-end gap-2 pt-2">
              <Button type="button" variant="secondary" onClick={() => setSelectedOrder(null)}>Close</Button>
              <Button type="submit" loading={updateMut.isPending}>Save Changes</Button>
            </div>
          </form>
        )}
      </Modal>
    </div>
  )
}

/* ========================================================================
 * 3. GUEST FOLIOS & BILLING LEDGER PAGE (/admin/folios)
 * ======================================================================== */
export function FoliosAdminPage() {
  const { can } = useAuth()
  const qc = useQueryClient()
  const [statusFilter, setStatusFilter] = useState('')
  const [search, setSearch] = useState('')
  const [activeFolioId, setActiveFolioId] = useState<number | null>(null)
  const [chargeForm, setChargeForm] = useState({
    category: 'laundry',
    description: '',
    quantity: '1',
    unit_amount: '',
  })
  const [adjForm, setAdjForm] = useState({
    entry_type: 'discount',
    description: '',
    amount: '',
    reason: '',
  })
  const [settleForm, setSettleForm] = useState({
    allow_exception: false,
    settlement_exception_reason: '',
    notes: '',
  })
  const [msg, setMsg] = useState<{ t: 'success' | 'error'; m: string } | null>(null)

  const q = useQuery({
    queryKey: ['admin', 'folios', statusFilter, search],
    queryFn: async () => {
      const params = new URLSearchParams()
      if (statusFilter) params.set('status', statusFilter)
      if (search) params.set('search', search)
      return (await api.get<{
        data: GuestFolioRecord[]
        summary: Record<string, number>
      }>(`/admin/folios?${params.toString()}`)).data
    },
  })

  const detailQ = useQuery({
    queryKey: ['admin', 'folio-detail', activeFolioId],
    enabled: !!activeFolioId,
    queryFn: async () => (await api.get<{ data: GuestFolioRecord }>(`/admin/folios/${activeFolioId}`)).data.data,
  })

  const postChargeMut = useMutation({
    mutationFn: async () =>
      (await api.post<{ message: string }>(`/admin/folios/${activeFolioId}/charges`, {
        category: chargeForm.category,
        description: chargeForm.description,
        quantity: Number(chargeForm.quantity),
        unit_amount: Number(chargeForm.unit_amount),
      })).data,
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['admin', 'folio-detail', activeFolioId] })
      qc.invalidateQueries({ queryKey: ['admin', 'folios'] })
      setMsg({ t: 'success', m: res.message })
      setChargeForm({ category: 'laundry', description: '', quantity: '1', unit_amount: '' })
    },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  const postAdjMut = useMutation({
    mutationFn: async () =>
      (await api.post<{ message: string }>(`/admin/folios/${activeFolioId}/adjustments`, {
        entry_type: adjForm.entry_type,
        description: adjForm.description,
        amount: Number(adjForm.amount),
        reason: adjForm.reason,
      })).data,
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['admin', 'folio-detail', activeFolioId] })
      qc.invalidateQueries({ queryKey: ['admin', 'folios'] })
      setMsg({ t: 'success', m: res.message })
      setAdjForm({ entry_type: 'discount', description: '', amount: '', reason: '' })
    },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  const reviewAdjMut = useMutation({
    mutationFn: async ({ entryId, decision }: { entryId: number; decision: 'approve' | 'reject' }) =>
      (await api.post<{ message: string }>(`/admin/folio-entries/${entryId}/review`, { decision })).data,
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['admin', 'folio-detail', activeFolioId] })
      qc.invalidateQueries({ queryKey: ['admin', 'folios'] })
      setMsg({ t: 'success', m: res.message })
    },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  const reverseEntryMut = useMutation({
    mutationFn: async ({ entryId, reason }: { entryId: number; reason: string }) =>
      (await api.post<{ message: string }>(`/admin/folio-entries/${entryId}/reverse`, { reason })).data,
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['admin', 'folio-detail', activeFolioId] })
      qc.invalidateQueries({ queryKey: ['admin', 'folios'] })
      setMsg({ t: 'success', m: res.message })
    },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  const settleMut = useMutation({
    mutationFn: async () =>
      (await api.post<{ message: string }>(`/admin/folios/${activeFolioId}/settle`, settleForm)).data,
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['admin', 'folio-detail', activeFolioId] })
      qc.invalidateQueries({ queryKey: ['admin', 'folios'] })
      setMsg({ t: 'success', m: res.message })
    },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  const summary = q.data?.summary
  const folio = detailQ.data

  return (
    <div className="space-y-6">
      <PageHeader
        title="Guest Folios & Billing Statements"
        description="Itemized accommodation charges, incidental room charges, credits/adjustments, payment allocations, and settlement."
      />

      {msg && <Alert variant={msg.t === 'error' ? 'error' : 'success'} title={msg.m} />}

      {summary && (
        <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
          <Card className="p-4">
            <p className="text-xs font-medium uppercase text-slate-500">Open Guest Folios</p>
            <p className="mt-1 text-2xl font-bold text-slate-900">{summary.open_folios ?? 0}</p>
            <p className="text-xs text-slate-400">{summary.closed_folios ?? 0} settled & closed</p>
          </Card>
          <Card className="p-4">
            <p className="text-xs font-medium uppercase text-slate-500">Unsettled Folio Balance</p>
            <p className="mt-1 text-2xl font-bold text-amber-600">{formatCurrency(summary.unsettled_balance_amount ?? 0)}</p>
            <p className="text-xs text-slate-400">Across open folios</p>
          </Card>
          <Card className="p-4">
            <p className="text-xs font-medium uppercase text-slate-500">Pending Adjustments</p>
            <p className="mt-1 text-2xl font-bold text-brand-700">{summary.pending_adjustments ?? 0}</p>
            <p className="text-xs text-slate-400">Awaiting supervisor approval</p>
          </Card>
          <Card className="p-4">
            <p className="text-xs font-medium uppercase text-slate-500">Settlement Exceptions</p>
            <p className="mt-1 text-2xl font-bold text-rose-600">{summary.settlement_exceptions ?? 0}</p>
            <p className="text-xs text-slate-400">Audited non-zero closes</p>
          </Card>
        </div>
      )}

      <Card>
        <div className="flex flex-wrap items-center gap-3 border-b border-slate-100 p-4">
          <div className="relative flex-1 min-w-[200px]">
            <Search className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
            <Input
              className="pl-9"
              placeholder="Search folio ref, guest name, reservation ref, room..."
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
          <Select value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)} className="w-44">
            <option value="">All Statuses</option>
            <option value="open">Open</option>
            <option value="closed">Closed / Settled</option>
          </Select>
        </div>

        {q.isLoading ? (
          <Spinner />
        ) : q.isError ? (
          <ErrorState message={errorMessage(q.error)} onRetry={() => q.refetch()} />
        ) : !q.data?.data.length ? (
          <EmptyState title="No guest folios found" description="Folios are created automatically for every reservation." />
        ) : (
          <DataTable
            rows={q.data.data}
            rowKey={(f) => f.id}
            columns={[
              { key: 'ref', header: 'Folio Ref', cell: (f) => <span className="font-mono text-xs font-semibold">{f.folio_reference}</span> },
              {
                key: 'guest',
                header: 'Guest & Stay',
                cell: (f) => (
                  <div>
                    <div className="font-medium text-slate-900">{f.guest_name}</div>
                    <div className="text-xs text-slate-500">
                      Res: {f.reservation?.reference} {f.room ? `· Room ${f.room.room_number}` : ''}
                    </div>
                  </div>
                ),
              },
              { key: 'charges', header: 'Total Charges', cell: (f) => formatCurrency(f.total_charges_amount, f.currency_code) },
              { key: 'credits', header: 'Credits', cell: (f) => formatCurrency(f.total_credits_amount, f.currency_code) },
              { key: 'payments', header: 'Payments', cell: (f) => formatCurrency(f.total_payments_amount, f.currency_code) },
              {
                key: 'balance',
                header: 'Balance Due',
                cell: (f) => (
                  <span className={f.balance_due_minor > 0 ? 'font-bold text-rose-600' : 'font-semibold text-emerald-700'}>
                    {formatCurrency(f.balance_due_amount, f.currency_code)}
                  </span>
                ),
              },
              { key: 'status', header: 'Status', cell: (f) => <Badge tone={statusTone(f.status)}>{f.status}</Badge> },
              {
                key: 'actions',
                header: 'Actions',
                cell: (f) => (
                  <Button size="sm" variant="secondary" onClick={() => { setMsg(null); setActiveFolioId(f.id) }}>
                    <Receipt className="h-4 w-4" /> Open Folio
                  </Button>
                ),
              },
            ]}
          />
        )}
      </Card>

      <Modal
        open={!!activeFolioId}
        onOpenChange={(o) => !o && setActiveFolioId(null)}
        title={folio ? `Guest Folio ${folio.folio_reference} — ${folio.guest_name}` : 'Loading Folio...'}
        wide
      >
        {detailQ.isLoading || !folio ? (
          <Spinner />
        ) : (
          <div className="space-y-6 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-slate-900 p-4 text-white">
              <div>
                <p className="text-xs uppercase text-slate-400">Reservation {folio.reservation?.reference}</p>
                <p className="text-lg font-bold">{folio.guest_name}</p>
                <p className="text-xs text-slate-300">
                  {folio.reservation?.check_in_date} to {folio.reservation?.check_out_date}
                  {folio.room ? ` · Room ${folio.room.room_number}` : ''}
                </p>
              </div>
              <div className="text-right">
                <p className="text-xs uppercase text-slate-400">Balance Due</p>
                <p className="text-2xl font-bold">{formatCurrency(folio.balance_due_amount, folio.currency_code)}</p>
                <div className="mt-1 flex items-center justify-end gap-2">
                  <Badge tone={statusTone(folio.status)}>{folio.status.toUpperCase()}</Badge>
                  <Button size="sm" variant="secondary" onClick={() => window.print()}>
                    <Printer className="h-3.5 w-3.5" /> Print Statement
                  </Button>
                </div>
              </div>
            </div>

            <div>
              <h4 className="mb-2 font-semibold text-slate-900">Chronological Folio Ledger (Immutable Append-Only)</h4>
              <div className="overflow-x-auto rounded border border-slate-200">
                <table className="min-w-full divide-y divide-slate-200 text-xs">
                  <thead className="bg-slate-50 text-left font-semibold text-slate-600">
                    <tr>
                      <th className="px-3 py-2">Date</th>
                      <th className="px-3 py-2">Reference</th>
                      <th className="px-3 py-2">Description</th>
                      <th className="px-3 py-2">Type</th>
                      <th className="px-3 py-2 text-right">Amount</th>
                      <th className="px-3 py-2">Status</th>
                      <th className="px-3 py-2">Actions</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 bg-white">
                    {(folio.entries ?? []).map((e) => (
                      <tr key={e.id}>
                        <td className="px-3 py-2 whitespace-nowrap">{e.service_date}</td>
                        <td className="px-3 py-2 font-mono">{e.entry_reference}</td>
                        <td className="px-3 py-2">
                          <div className="font-medium text-slate-900">{e.description}</div>
                          {e.reason && <div className="text-[11px] text-slate-500">Reason: {e.reason}</div>}
                        </td>
                        <td className="px-3 py-2">{e.direction === 'credit' ? 'Credit' : 'Charge'} ({e.entry_type})</td>
                        <td className={`px-3 py-2 text-right font-semibold ${e.direction === 'credit' || e.total_amount < 0 ? 'text-emerald-700' : 'text-slate-900'}`}>
                          {e.direction === 'credit' ? '-' : ''}{formatCurrency(e.total_amount, e.currency_code)}
                        </td>
                        <td className="px-3 py-2"><Badge tone={statusTone(e.status)}>{e.status}</Badge></td>
                        <td className="px-3 py-2">
                          {e.status === 'pending_approval' && can('folios.adjust') && (
                            <div className="flex gap-1">
                              <Button size="sm" onClick={() => reviewAdjMut.mutate({ entryId: e.id, decision: 'approve' })}>Approve</Button>
                              <Button size="sm" variant="secondary" onClick={() => reviewAdjMut.mutate({ entryId: e.id, decision: 'reject' })}>Reject</Button>
                            </div>
                          )}
                          {e.status === 'posted' && folio.status === 'open' && e.entry_type !== 'reversal' && can('folios.adjust') && (
                            <Button
                              size="sm"
                              variant="secondary"
                              onClick={() => {
                                const reason = window.prompt('Enter reason for reversing this posted folio entry:')
                                if (reason && reason.trim().length >= 3) {
                                  reverseEntryMut.mutate({ entryId: e.id, reason })
                                }
                              }}
                            >
                              <RotateCcw className="h-3 w-3" /> Reverse
                            </Button>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>

            {folio.status === 'open' && (
              <div className="grid gap-4 md:grid-cols-2">
                {can('folios.create_charge') && (
                  <form
                    className="space-y-3 rounded border border-slate-200 p-3"
                    onSubmit={(e) => {
                      e.preventDefault()
                      postChargeMut.mutate()
                    }}
                  >
                    <p className="font-semibold text-slate-900">Post Manual Incidental Charge</p>
                    <div className="grid gap-2 sm:grid-cols-2">
                      <FormField id="fol-cat" label="Category *">
                        <Select id="fol-cat" value={chargeForm.category} onChange={(e) => setChargeForm({ ...chargeForm, category: e.target.value })}>
                          <option value="laundry">Laundry</option>
                          <option value="minibar">Minibar</option>
                          <option value="extra_bed">Extra Bed</option>
                          <option value="late_checkout">Late Checkout Fee</option>
                          <option value="early_checkin">Early Check-in Fee</option>
                          <option value="damage">Damage / Loss</option>
                          <option value="miscellaneous">Miscellaneous</option>
                        </Select>
                      </FormField>
                      <FormField id="fol-amt" label="Unit Amount (NGN) *">
                        <Input id="fol-amt" type="number" step="0.01" min="1" value={chargeForm.unit_amount} onChange={(e) => setChargeForm({ ...chargeForm, unit_amount: e.target.value })} required />
                      </FormField>
                    </div>
                    <FormField id="fol-desc" label="Description *">
                      <Input id="fol-desc" value={chargeForm.description} onChange={(e) => setChargeForm({ ...chargeForm, description: e.target.value })} required placeholder="e.g. Express laundry (3 items)" />
                    </FormField>
                    <Button type="submit" size="sm" loading={postChargeMut.isPending}>Post Charge</Button>
                  </form>
                )}

                {can('folios.adjust', 'folios.create_charge') && (
                  <form
                    className="space-y-3 rounded border border-slate-200 p-3"
                    onSubmit={(e) => {
                      e.preventDefault()
                      postAdjMut.mutate()
                    }}
                  >
                    <p className="font-semibold text-slate-900">Post / Request Credit or Adjustment</p>
                    <div className="grid gap-2 sm:grid-cols-2">
                      <FormField id="adj-type" label="Adjustment Type *">
                        <Select id="adj-type" value={adjForm.entry_type} onChange={(e) => setAdjForm({ ...adjForm, entry_type: e.target.value })}>
                          <option value="discount">Discount</option>
                          <option value="allowance">Goodwill Allowance</option>
                          <option value="credit_adjustment">Credit Adjustment</option>
                          <option value="debit_adjustment">Debit Adjustment</option>
                        </Select>
                      </FormField>
                      <FormField id="adj-amt" label="Amount (NGN) *">
                        <Input id="adj-amt" type="number" step="0.01" min="1" value={adjForm.amount} onChange={(e) => setAdjForm({ ...adjForm, amount: e.target.value })} required />
                      </FormField>
                    </div>
                    <FormField id="adj-desc" label="Description *">
                      <Input id="adj-desc" value={adjForm.description} onChange={(e) => setAdjForm({ ...adjForm, description: e.target.value })} required placeholder="e.g. Manager courtesy discount" />
                    </FormField>
                    <FormField id="adj-reason" label="Audit Reason *">
                      <Input id="adj-reason" value={adjForm.reason} onChange={(e) => setAdjForm({ ...adjForm, reason: e.target.value })} required placeholder="Reason for adjustment..." />
                    </FormField>
                    <Button type="submit" size="sm" variant="secondary" loading={postAdjMut.isPending}>Submit Adjustment</Button>
                  </form>
                )}
              </div>
            )}

            {folio.status === 'open' && can('folios.settle') && (
              <div className="rounded border border-emerald-200 bg-emerald-50/40 p-4 space-y-3">
                <p className="font-semibold text-slate-900">Settle & Close Guest Folio</p>
                {folio.balance_due_minor !== 0 && can('folios.refund', 'finance.approve') && (
                  <div className="space-y-2">
                    <label className="flex items-center gap-2 text-xs font-medium text-amber-800">
                      <input
                        type="checkbox"
                        checked={settleForm.allow_exception}
                        onChange={(e) => setSettleForm({ ...settleForm, allow_exception: e.target.checked })}
                      />
                      Authorize Non-Zero Balance Settlement Exception (Requires Reason)
                    </label>
                    {settleForm.allow_exception && (
                      <Input
                        placeholder="Mandatory reason for closing folio with non-zero balance..."
                        value={settleForm.settlement_exception_reason}
                        onChange={(e) => setSettleForm({ ...settleForm, settlement_exception_reason: e.target.value })}
                      />
                    )}
                  </div>
                )}
                <Button onClick={() => settleMut.mutate()} loading={settleMut.isPending}>
                  <CheckCircle2 className="h-4 w-4" /> Settle & Close Folio
                </Button>
              </div>
            )}
          </div>
        )}
      </Modal>
    </div>
  )
}
