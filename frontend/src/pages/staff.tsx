import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useAuth } from '@/auth/AuthProvider'
import { api, errorMessage, type Paginated } from '@/lib/api'
import { formatDateTime } from '@/lib/utils'
import { Alert, Badge, Button, Card, DataTable, FormField, Input, Modal, PageHeader, Select, Textarea } from '@/components/ui'

type Inquiry = { id: number; name: string; email: string; phone: string | null; subject: string; message: string; status: string; created_at: string }

export function ContactInboxPage() {
  const { can } = useAuth()
  const client = useQueryClient()
  const [search, setSearch] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const [selected, setSelected] = useState<Inquiry | null>(null)
  const q = useQuery({ queryKey: ['inquiries', search, status, page], queryFn: async () => (await api.get<Paginated<Inquiry>>('/admin/contact-inquiries', { params: { search, status: status || undefined, page } })).data })
  const update = useMutation({
    mutationFn: async (status: string) => api.put(`/admin/contact-inquiries/${selected!.id}`, { status }),
    onSuccess: () => { setSelected(null); client.invalidateQueries({ queryKey: ['inquiries'] }) },
  })
  return <>
    <PageHeader title="Contact Inbox" description="Review guest questions and event inquiries received through the website." />
    <div className="mb-4 flex flex-wrap gap-3">
      <Input aria-label="Search inquiries" className="max-w-sm" placeholder="Search name, email or subject" value={search} onChange={e => { setSearch(e.target.value); setPage(1) }} />
      <Select aria-label="Filter inquiry status" className="max-w-xs" value={status} onChange={e => { setStatus(e.target.value); setPage(1) }}><option value="">All statuses</option><option value="new">New</option><option value="in_progress">In progress</option><option value="resolved">Resolved</option></Select>
    </div>
    <Card><DataTable rows={q.data?.data} rowKey={r => r.id} isLoading={q.isLoading} error={q.isError ? errorMessage(q.error) : null} onRetry={() => q.refetch()} page={page} lastPage={q.data?.last_page} onPage={setPage} columns={[
      { key: 'guest', header: 'Guest', cell: r => <><p className="font-medium">{r.name}</p><p>{r.email}</p></> },
      { key: 'subject', header: 'Subject', cell: r => r.subject },
      { key: 'status', header: 'Status', cell: r => <Badge tone={r.status === 'new' ? 'warning' : r.status === 'resolved' ? 'success' : 'neutral'}>{r.status.replace('_', ' ')}</Badge> },
      { key: 'date', header: 'Received', cell: r => formatDateTime(r.created_at) },
      { key: 'open', header: 'Details', cell: r => <Button size="sm" variant="secondary" onClick={() => { update.reset(); setSelected(r) }}>Open</Button> },
    ]} /></Card>
    <Modal open={!!selected} onOpenChange={open => { if (!open) setSelected(null) }} title={selected?.subject ?? 'Inquiry'}>
      {selected && <div className="space-y-4">
        <p><strong>{selected.name}</strong><br /><a className="text-brand-700 underline" href={`mailto:${selected.email}`}>{selected.email}</a>{selected.phone && <><br /><span>{selected.phone}</span></>}</p>
        <p className="whitespace-pre-wrap break-words rounded bg-slate-50 p-4">{selected.message}</p>
        {update.isError && <Alert variant="error">{errorMessage(update.error)}</Alert>}
        {can('website.edit') && <div className="flex flex-wrap gap-2">{['new', 'in_progress', 'resolved'].map(s => <Button key={s} variant="secondary" disabled={selected.status === s || update.isPending} onClick={() => update.mutate(s)}>Mark {s.replace('_', ' ')}</Button>)}</div>}
      </div>}
    </Modal>
  </>
}

type Employee = { id: number; staff_number: string; name: string; email: string | null; phone: string | null; department: string; job_title: string; start_date: string | null; status: string; confidential_notes?: string | null }
type Payroll = { id: number; employee: Pick<Employee, 'name' | 'staff_number'>; period: string; basic_minor: number; allowances_minor: number; deductions_minor: number; net_minor: number; status: string; prepared_by: number }
const blankEmployee = { staff_number: '', name: '', email: '', phone: '', department: '', job_title: '', start_date: '', status: 'active', confidential_notes: '' }
const money = (minor: number) => new Intl.NumberFormat('en-NG', { style: 'currency', currency: 'NGN' }).format(minor / 100)

export function HumanResourcesPage() {
  const { can, user } = useAuth()
  const client = useQueryClient()
  const [tab, setTab] = useState(can('hr.view') ? 'staff' : 'payroll')
  const [search, setSearch] = useState('')
  const [page, setPage] = useState(1)
  const [period, setPeriod] = useState(() => new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Lagos' }).slice(0, 7))
  const [editing, setEditing] = useState<number | null>(null)
  const [employeeOpen, setEmployeeOpen] = useState(false)
  const [form, setForm] = useState(blankEmployee)
  const [payrollOpen, setPayrollOpen] = useState(false)
  const [employeeSearch, setEmployeeSearch] = useState('')
  const [pay, setPay] = useState({ employee_id: '', basic: '', allowances: '0', deductions: '0' })
  const staff = useQuery({ queryKey: ['employees', search, page], enabled: can('hr.view'), queryFn: async () => (await api.get<Paginated<Employee>>('/admin/employees', { params: { search, page } })).data })
  const payroll = useQuery({ queryKey: ['payroll', period, page], enabled: can('payroll.view'), queryFn: async () => (await api.get<Paginated<Payroll>>('/admin/payroll', { params: { period, page } })).data })
  const employeeOptions = useQuery({ queryKey: ['payroll-employees', employeeSearch], enabled: payrollOpen && can('hr.view'), queryFn: async () => (await api.get<Paginated<Employee>>('/admin/employees', { params: { search: employeeSearch } })).data })
  const download = useMutation({ mutationFn: async () => {
    const result = await api.get('/admin/payroll/export', { params: { period }, responseType: 'blob' })
    const url = URL.createObjectURL(result.data)
    const link = document.createElement('a')
    link.href = url; link.download = `payroll-${period}.csv`; link.click()
    window.setTimeout(() => URL.revokeObjectURL(url), 1000)
  } })
  const save = useMutation({
    mutationFn: async () => {
      const { confidential_notes, ...publicData } = form
      const data = { ...publicData, start_date: form.start_date || null, ...(can('hr.confidential') ? { confidential_notes } : {}) }
      return editing ? api.put(`/admin/employees/${editing}`, data) : api.post('/admin/employees', data)
    },
    onSuccess: () => { setEmployeeOpen(false); client.invalidateQueries({ queryKey: ['employees'] }) },
  })
  const prepare = useMutation({
    mutationFn: async () => api.post('/admin/payroll', { employee_id: Number(pay.employee_id), period, basic_minor: Math.round(Number(pay.basic) * 100), allowances_minor: Math.round(Number(pay.allowances) * 100), deductions_minor: Math.round(Number(pay.deductions) * 100) }),
    onSuccess: () => { setPayrollOpen(false); client.invalidateQueries({ queryKey: ['payroll'] }) },
  })
  const review = useMutation({ mutationFn: async ({ id, status }: { id: number; status: string }) => api.post(`/admin/payroll/${id}/review`, { status }), onSuccess: () => client.invalidateQueries({ queryKey: ['payroll'] }) })
  const edit = (employee?: Employee) => {
    save.reset(); setEditing(employee?.id ?? null)
    setForm(employee ? { staff_number: employee.staff_number, name: employee.name, email: employee.email ?? '', phone: employee.phone ?? '', department: employee.department, job_title: employee.job_title, start_date: employee.start_date?.slice(0, 10) ?? '', status: employee.status, confidential_notes: employee.confidential_notes ?? '' } : blankEmployee)
    setEmployeeOpen(true)
  }
  return <>
    <PageHeader title="Human Resources" description="Maintain staff records and prepare monthly payroll for management approval." />
    <div className="mb-4 flex gap-2">{can('hr.view') && <Button variant={tab === 'staff' ? 'primary' : 'secondary'} onClick={() => { setTab('staff'); setPage(1) }}>Staff directory</Button>}{can('payroll.view') && <Button variant={tab === 'payroll' ? 'primary' : 'secondary'} onClick={() => { setTab('payroll'); setPage(1) }}>Payroll</Button>}</div>
    {tab === 'staff' ? <>
      <div className="mb-4 flex flex-wrap justify-between gap-3"><Input aria-label="Search staff" className="max-w-sm" placeholder="Search staff name, number or department" value={search} onChange={e => { setSearch(e.target.value); setPage(1) }} />{can('hr.edit') && <Button onClick={() => edit()}>Add employee</Button>}</div>
      <Card><DataTable rows={staff.data?.data} rowKey={r => r.id} isLoading={staff.isLoading} error={staff.isError ? errorMessage(staff.error) : null} onRetry={() => staff.refetch()} page={page} lastPage={staff.data?.last_page} onPage={setPage} columns={[
        { key: 'staff', header: 'Employee', cell: r => <><p className="font-medium">{r.name}</p><p>{r.staff_number}</p></> },
        { key: 'job', header: 'Position', cell: r => <>{r.job_title}<br />{r.department}</> },
        { key: 'contact', header: 'Contact', cell: r => <>{r.email}<br />{r.phone}</> },
        { key: 'status', header: 'Status', cell: r => r.status.replace('_', ' ') },
        { key: 'edit', header: 'Details', cell: r => can('hr.edit') ? <Button size="sm" variant="secondary" onClick={() => edit(r)}>Edit</Button> : can('hr.confidential') ? <p className="whitespace-pre-wrap">{r.confidential_notes || 'No confidential notes'}</p> : '—' },
      ]} /></Card>
    </> : <>
      <div className="mb-4 flex flex-wrap items-end justify-between gap-3"><FormField id="payroll-period" label="Payroll month"><Input id="payroll-period" type="month" required value={period} onChange={e => { if (e.target.value) { setPeriod(e.target.value); setPage(1) } }} /></FormField>{can('payroll.prepare') && <Button onClick={() => { prepare.reset(); setPay({ employee_id: '', basic: '', allowances: '0', deductions: '0' }); setPayrollOpen(true) }}>Prepare payroll</Button>}</div>
      {can('payroll.export') && <Button variant="secondary" className="mb-3" loading={download.isPending} onClick={() => download.mutate()}>Export month as CSV</Button>}
      {download.isError && <Alert variant="error">Could not export payroll. Please try again.</Alert>}
      <Alert>Amounts are in Nigerian naira. Approved entries are payroll records; bank payments must be processed separately. Rejected entries can be corrected by preparing payroll again for the same employee and month.</Alert>
      {review.isError && <Alert variant="error">{errorMessage(review.error)}</Alert>}
      <Card className="mt-4"><DataTable rows={payroll.data?.data} rowKey={r => r.id} isLoading={payroll.isLoading} error={payroll.isError ? errorMessage(payroll.error) : null} onRetry={() => payroll.refetch()} page={page} lastPage={payroll.data?.last_page} onPage={setPage} columns={[
        { key: 'employee', header: 'Employee', cell: r => <>{r.employee.name}<br />{r.employee.staff_number}</> },
        { key: 'basic', header: 'Basic', cell: r => money(r.basic_minor) },
        { key: 'allowances', header: 'Allowances', cell: r => money(r.allowances_minor) },
        { key: 'deductions', header: 'Deductions', cell: r => money(r.deductions_minor) },
        { key: 'net', header: 'Net pay', cell: r => money(r.net_minor) },
        { key: 'status', header: 'Status', cell: r => <Badge tone={r.status === 'approved' ? 'success' : 'neutral'}>{r.status}</Badge> },
        { key: 'review', header: 'Review', cell: r => can('payroll.approve') && r.status === 'pending' && r.prepared_by !== user?.id ? <div className="flex gap-2"><Button size="sm" disabled={review.isPending} onClick={() => review.mutate({ id: r.id, status: 'approved' })}>Approve</Button><Button size="sm" variant="danger" disabled={review.isPending} onClick={() => review.mutate({ id: r.id, status: 'rejected' })}>Reject</Button></div> : '—' },
      ]} /></Card>
    </>}
    <Modal open={employeeOpen} onOpenChange={setEmployeeOpen} title={editing ? 'Edit employee' : 'Add employee'}>
      <form className="space-y-4" onSubmit={e => { e.preventDefault(); save.mutate() }}>
        {save.isError && <Alert variant="error">{errorMessage(save.error)}</Alert>}
        {(['staff_number', 'name', 'email', 'phone', 'department', 'job_title', 'start_date'] as const).map(key => <FormField key={key} id={`employee-${key}`} label={{ staff_number: 'Staff number', name: 'Full name', email: 'Email', phone: 'Phone', department: 'Department', job_title: 'Job title', start_date: 'Start date' }[key]}><Input id={`employee-${key}`} maxLength={key === 'phone' ? 32 : key === 'staff_number' ? 40 : key === 'email' ? 255 : 120} required={['staff_number', 'name', 'department', 'job_title'].includes(key)} type={key === 'start_date' ? 'date' : key === 'email' ? 'email' : 'text'} value={form[key]} onChange={e => setForm({ ...form, [key]: e.target.value })} /></FormField>)}
        <FormField id="employee-status" label="Status"><Select id="employee-status" value={form.status} onChange={e => setForm({ ...form, status: e.target.value })}><option value="active">Active</option><option value="on_leave">On leave</option><option value="inactive">Inactive</option></Select></FormField>
        {can('hr.confidential') && <FormField id="employee-notes" label="Confidential notes"><Textarea id="employee-notes" maxLength={5000} value={form.confidential_notes} onChange={e => setForm({ ...form, confidential_notes: e.target.value })} /></FormField>}
        <Button type="submit" loading={save.isPending}>Save employee</Button>
      </form>
    </Modal>
    <Modal open={payrollOpen} onOpenChange={setPayrollOpen} title={`Prepare payroll — ${period}`}>
      <form className="space-y-4" onSubmit={e => { e.preventDefault(); prepare.mutate() }}>
        {prepare.isError && <Alert variant="error">{errorMessage(prepare.error)}</Alert>}
        <Input aria-label="Search employee for payroll" placeholder="Search employee name or staff number" value={employeeSearch} onChange={e => { setEmployeeSearch(e.target.value); setPay({ ...pay, employee_id: '' }) }} />
        {employeeOptions.isError && <Alert variant="error">{errorMessage(employeeOptions.error)}</Alert>}
        <FormField id="payroll-employee" label="Employee"><Select id="payroll-employee" required value={pay.employee_id} onChange={e => setPay({ ...pay, employee_id: e.target.value })}><option value="">{employeeOptions.isLoading ? 'Loading employees…' : 'Select employee'}</option>{employeeOptions.data?.data.filter(e => e.status === 'active').map(e => <option key={e.id} value={e.id}>{e.staff_number} — {e.name}</option>)}</Select></FormField>
        {(['basic', 'allowances', 'deductions'] as const).map(key => <FormField key={key} id={`pay-${key}`} label={`${key === 'basic' ? 'Basic pay' : key === 'allowances' ? 'Allowances' : 'Deductions'} (NGN)`}><Input id={`pay-${key}`} type="number" min="0" max="1000000000" step="0.01" required value={pay[key]} onChange={e => setPay({ ...pay, [key]: e.target.value })} /></FormField>)}
        <Button type="submit" loading={prepare.isPending}>Submit for approval</Button>
      </form>
    </Modal>
  </>
}
