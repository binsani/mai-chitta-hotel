import { Link, useLocation } from 'react-router-dom'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { useState } from 'react'
import { Construction, ShieldAlert, SearchX } from 'lucide-react'
import { useAuth, type User } from '@/auth/AuthProvider'
import { api, errorMessage } from '@/lib/api'
import { allNavItems } from '@/lib/navigation'
import { formatDateTime, zodResolver } from '@/lib/utils'
import { Alert, Badge, Button, Card, CardHeader, EmptyState, ErrorState, FormField, Input, PageHeader, Spinner } from '@/components/ui'

type DashboardData = {
  hotel: Record<string, string | null>
  server_time: string
  environment: string
  versions: { laravel: string; php: string }
  last_login_at: string | null
  counts?: { users: number; active_users: number; roles: number }
  cms_counts?: {
    published_pages: number
    draft_pages: number
    media_assets: number
    published_rooms: number
    published_offers: number
    new_inquiries: number
  }
  reservation_counts?: {
    room_types: number
    physical_rooms: number
    confirmed_reservations: number
    checked_in_reservations: number
    today_arrivals: number
    today_departures: number
  }
  operations_counts?: {
    housekeeping_open: number
    rooms_dirty: number
    rooms_awaiting_inspection: number
    maintenance_open: number
    open_folios: number
    pending_folio_adjustments: number
  }
  recent_failed_logins?: number
  integrations?: { mail: { driver: string; configured: boolean; from_address: string | null }; queue: { driver: string }; database: { driver: string }; app: { debug: boolean; environment: string } }
}

function Stat({ label, value, hint }: { label: string; value: React.ReactNode; hint?: string }) {
  return (
    <Card className="p-5">
      <p className="text-sm text-slate-500">{label}</p>
      <p className="mt-1 text-2xl font-semibold text-slate-900">{value}</p>
      {hint && <p className="mt-1 text-xs text-slate-400">{hint}</p>}
    </Card>
  )
}

export function DashboardPage() {
  const { user, can } = useAuth()
  const q = useQuery({ queryKey: ['dashboard'], queryFn: async () => (await api.get<{ data: DashboardData }>('/dashboard')).data.data })
  const modules = allNavItems.filter((i) => i.path !== '/admin' && can(...i.permissions))

  return (
    <>
      <PageHeader title={`Welcome, ${user?.name.split(' ')[0] ?? ''}`} description="Overview of your account, website content, and system status." />
      {q.isLoading ? <Spinner /> : q.isError ? <ErrorState message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : q.data && (
        <div className="space-y-6">
          {q.data.integrations?.app.debug && q.data.environment === 'production' && (
            <Alert variant="error" title="Debug mode is enabled in production">Set APP_DEBUG=false in the server .env file.</Alert>
          )}
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
            <Stat label="Your roles" value={user?.roles?.length ?? 0} hint={user?.roles?.map((r) => r.name).join(', ') || 'No role assigned'} />
            <Stat label="Accessible modules" value={modules.length} hint={`${modules.filter((m) => m.ready).length} available now`} />
            {q.data.reservation_counts && <Stat label="Reservations & Rooms" value={`${q.data.reservation_counts.confirmed_reservations} confirmed`} hint={`${q.data.reservation_counts.checked_in_reservations} in-house · ${q.data.reservation_counts.physical_rooms} active rooms`} />}
            {q.data.operations_counts && <Stat label="Operations & Folios" value={`${q.data.operations_counts.housekeeping_open} HK tasks`} hint={`${q.data.operations_counts.rooms_dirty} dirty · ${q.data.operations_counts.maintenance_open} work orders · ${q.data.operations_counts.open_folios} open folios`} />}
            {q.data.cms_counts && <Stat label="Published pages" value={q.data.cms_counts.published_pages} hint={`${q.data.cms_counts.draft_pages} drafts · ${q.data.cms_counts.media_assets} media files`} />}
            {q.data.counts && <Stat label="Staff accounts" value={q.data.counts.users} hint={`${q.data.counts.active_users} active · ${q.data.counts.roles} roles`} />}
            {q.data.recent_failed_logins !== undefined && <Stat label="Failed sign-ins (24h)" value={q.data.recent_failed_logins} hint="From the audit log" />}
          </div>

          <div className="grid gap-6 lg:grid-cols-3">
            <Card className="lg:col-span-2">
              <CardHeader title="Your modules" description="Modules you have permission to access." />
              {modules.length === 0 ? <EmptyState title="No modules assigned" description="Ask an administrator to assign you a role." /> : (
                <ul className="grid gap-px bg-slate-100 sm:grid-cols-2">
                  {modules.map((m) => (
                    <li key={m.path} className="bg-white">
                      <Link to={m.path} className="flex items-center gap-3 px-5 py-3 hover:bg-slate-50">
                        <m.icon className="h-4 w-4 text-brand-700" aria-hidden />
                        <span className="flex-1 text-sm text-slate-800">{m.label}</span>
                        {m.ready ? <Badge tone="success">Available</Badge> : <Badge>Later phase</Badge>}
                      </Link>
                    </li>
                  ))}
                </ul>
              )}
            </Card>
            <Card>
              <CardHeader title="System" />
              <dl className="divide-y divide-slate-100 text-sm">
                {([
                  ['Hotel', q.data.hotel.name],
                  ['Location', [q.data.hotel.city, q.data.hotel.state, q.data.hotel.country].filter(Boolean).join(', ')],
                  ['Time zone', q.data.hotel.timezone],
                  ['Currency', q.data.hotel.currency],
                  ['Server time', formatDateTime(q.data.server_time)],
                  ['Your last sign-in', formatDateTime(q.data.last_login_at)],
                  ['Environment', q.data.environment],
                  ...(q.data.integrations ? [
                    ['Email delivery', q.data.integrations.mail.configured ? <Badge tone="success" key="m">Configured ({q.data.integrations.mail.driver})</Badge> : <Badge tone="warning" key="m">Not configured ({q.data.integrations.mail.driver})</Badge>],
                    ['Queue driver', q.data.integrations.queue.driver],
                    ['Database', q.data.integrations.database.driver],
                  ] : []),
                ] as [string, React.ReactNode][]).map(([k, v]) => (
                  <div key={k} className="flex justify-between gap-4 px-5 py-2.5"><dt className="text-slate-500">{k}</dt><dd className="text-right font-medium text-slate-800">{v || '—'}</dd></div>
                ))}
              </dl>
            </Card>
          </div>
        </div>
      )}
    </>
  )
}

export function ComingSoonPage({ title, group }: { title: string; group: string }) {
  return (
    <>
      <PageHeader title={title} description={group} />
      <Card><EmptyState icon={Construction} title="Coming in a later phase" description={`The ${title} module is planned for a future phase of the Mai Chitta Hotel system. Your access to it has already been configured.`} /></Card>
    </>
  )
}

export function AccessDeniedPage() {
  return (
    <Card><EmptyState icon={ShieldAlert} title="Access denied" description="Your role does not include permission to view this page. Contact a system administrator if you believe this is a mistake." action={<Button asChild variant="secondary"><Link to="/admin">Back to dashboard</Link></Button>} /></Card>
  )
}

export function NotFoundPage() {
  const { pathname } = useLocation()
  return <Card><EmptyState icon={SearchX} title="Page not found" description={`No page exists at ${pathname}.`} action={<Button asChild variant="secondary"><Link to="/admin">Back to dashboard</Link></Button>} /></Card>
}

const profileSchema = z.object({ name: z.string().trim().min(1, 'Required').max(120), phone: z.string().max(32).nullable() })
const passwordSchema = z.object({
  current_password: z.string().min(1, 'Required'),
  password: z.string().min(8, 'At least 8 characters'),
  password_confirmation: z.string(),
}).refine((d) => d.password === d.password_confirmation, { message: 'Passwords do not match', path: ['password_confirmation'] })

export function ProfilePage() {
  const { user } = useAuth()
  const qc = useQueryClient()
  const [msg, setMsg] = useState<{ t: 'success' | 'error'; m: string } | null>(null)
  const [pwMsg, setPwMsg] = useState<{ t: 'success' | 'error'; m: string } | null>(null)
  const pf = useForm<z.infer<typeof profileSchema>>({ resolver: zodResolver(profileSchema), defaultValues: { name: user?.name ?? '', phone: user?.phone ?? '' } })
  const pw = useForm<z.infer<typeof passwordSchema>>({ resolver: zodResolver(passwordSchema), defaultValues: { current_password: '', password: '', password_confirmation: '' } })

  const saveProfile = useMutation({
    mutationFn: async (v: z.infer<typeof profileSchema>) => (await api.put<{ data: User }>('/auth/profile', v)).data.data,
    onSuccess: (u) => { qc.setQueryData(['auth', 'user'], u); setMsg({ t: 'success', m: 'Profile updated.' }) },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })
  const savePw = useMutation({
    mutationFn: async (v: z.infer<typeof passwordSchema>) => (await api.put<{ message: string }>('/auth/password', v)).data.message,
    onSuccess: (m) => { pw.reset(); setPwMsg({ t: 'success', m }) },
    onError: (e) => setPwMsg({ t: 'error', m: errorMessage(e) }),
  })

  return (
    <>
      <PageHeader title="My profile" description={user?.email} />
      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardHeader title="Personal details" />
          <form className="space-y-4 p-5" onSubmit={pf.handleSubmit((v) => saveProfile.mutate(v))} noValidate>
            {msg && <Alert variant={msg.t}>{msg.m}</Alert>}
            <FormField id="name" label="Full name" error={pf.formState.errors.name?.message}><Input id="name" {...pf.register('name')} /></FormField>
            <FormField id="phone" label="Phone" error={pf.formState.errors.phone?.message}><Input id="phone" type="tel" {...pf.register('phone')} /></FormField>
            <div className="text-sm text-slate-500">Roles: {user?.roles?.map((r) => <Badge key={r.id} tone="brand">{r.name}</Badge>)}</div>
            <Button type="submit" loading={saveProfile.isPending}>Save</Button>
          </form>
        </Card>
        <Card>
          <CardHeader title="Change password" description="Changing your password signs you out of all other devices." />
          <form className="space-y-4 p-5" onSubmit={pw.handleSubmit((v) => savePw.mutate(v))} noValidate>
            {pwMsg && <Alert variant={pwMsg.t}>{pwMsg.m}</Alert>}
            <FormField id="current_password" label="Current password" error={pw.formState.errors.current_password?.message}><Input id="current_password" type="password" autoComplete="current-password" {...pw.register('current_password')} /></FormField>
            <FormField id="new_password" label="New password" error={pw.formState.errors.password?.message}><Input id="new_password" type="password" autoComplete="new-password" {...pw.register('password')} /></FormField>
            <FormField id="password_confirmation" label="Confirm new password" error={pw.formState.errors.password_confirmation?.message}><Input id="password_confirmation" type="password" autoComplete="new-password" {...pw.register('password_confirmation')} /></FormField>
            <Button type="submit" loading={savePw.isPending}>Update password</Button>
          </form>
        </Card>
      </div>
    </>
  )
}
