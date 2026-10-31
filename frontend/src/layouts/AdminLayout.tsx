import { useState } from 'react'
import { Link, NavLink, Navigate, Outlet, useLocation, useNavigate } from 'react-router-dom'
import * as DropdownMenu from '@radix-ui/react-dropdown-menu'
import { Bell, ChevronDown, ChevronRight, ExternalLink, LogOut, Menu, UserCircle2, X } from 'lucide-react'
import { useAuth } from '@/auth/AuthProvider'
import { allNavItems, navigation } from '@/lib/navigation'
import { Spinner } from '@/components/ui'
import { cn } from '@/lib/utils'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import { api, errorMessage } from '@/lib/api'
import { formatDateTime } from '@/lib/utils'

type OperationalAlert = { id: number; title: string; message: string; severity: string; is_read: boolean; created_at: string }

function OperationalAlerts() {
  const { user } = useAuth()
  const client = useQueryClient()
  const [open, setOpen] = useState(false)
  const key = ['operational-alerts', user?.id]
  const alerts = useQuery({ queryKey: key, enabled: !!user, refetchInterval: 60_000, queryFn: async ({ signal }) => (await api.get<{ data: OperationalAlert[]; unread_count: number }>('/admin/operational-notifications', { signal })).data })
  const acknowledge = useMutation({ mutationFn: async (id: number) => api.post(`/admin/operational-notifications/${id}/read`), onSuccess: () => client.invalidateQueries({ queryKey: key }) })
  const unread = alerts.data?.unread_count ?? 0
  return <DropdownMenu.Root open={open} onOpenChange={setOpen}>
    <DropdownMenu.Trigger className="relative rounded-full p-2 text-slate-500 hover:bg-slate-100" aria-label={`Notifications${unread ? `, ${unread} unread` : ''}`}>
      <Bell className="h-5 w-5" />{unread > 0 && <span className="absolute -right-1 -top-1 rounded-full bg-red-600 px-1 text-[10px] text-white">{unread > 99 ? '99+' : unread}</span>}
    </DropdownMenu.Trigger>
    <DropdownMenu.Portal><DropdownMenu.Content align="end" sideOffset={6} className="z-50 max-h-[70vh] w-[min(24rem,90vw)] overflow-y-auto rounded-md border border-slate-200 bg-white p-4 text-sm shadow-lg">
      <h2 className="font-semibold text-slate-800">Operational notifications</h2>
      <p className="mb-3 text-xs text-slate-500">Team alerts are shared with staff who have the relevant permission.</p>
      {alerts.isLoading && <p>Loading notifications…</p>}
      {alerts.isError && <div role="alert">{errorMessage(alerts.error)} <button className="underline" onClick={() => alerts.refetch()}>Retry</button></div>}
      {acknowledge.isError && <p role="alert" className="text-red-700">{errorMessage(acknowledge.error)}</p>}
      {alerts.data?.data.length === 0 && <p className="text-slate-500">No operational notifications yet.</p>}
      <ul className="divide-y divide-slate-100">{alerts.data?.data.map(alert => <li key={alert.id} className={`py-3 ${alert.is_read ? 'text-slate-500' : 'text-slate-900'}`}>
        <p className="font-medium">{alert.title}</p><p className="mt-1 whitespace-pre-wrap break-words">{alert.message}</p>
        <p className={`mt-1 text-xs ${alert.severity === 'critical' ? 'text-red-700' : 'text-slate-500'}`}>{alert.severity} · {formatDateTime(alert.created_at)}</p>
        {!alert.is_read && <button className="mt-2 text-xs font-medium text-brand-700 underline disabled:opacity-50" disabled={acknowledge.isPending} onClick={() => acknowledge.mutate(alert.id)}>Acknowledge</button>}
      </li>)}</ul>
    </DropdownMenu.Content></DropdownMenu.Portal>
  </DropdownMenu.Root>
}

export function RequireAuth() {
  const { user, isLoading } = useAuth()
  const location = useLocation()
  if (isLoading) return <div className="h-full"><Spinner label="Checking your session…" /></div>
  if (!user) return <Navigate to="/admin/login" replace state={{ from: location.pathname }} />
  return <Outlet />
}

function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
  const { can } = useAuth()
  return (
    <nav aria-label="Main navigation" className="flex-1 overflow-y-auto px-3 py-4">
      {navigation.map((group) => {
        const items = group.items.filter((i) => can(...i.permissions))
        if (!items.length) return null
        return (
          <div key={group.label} className="mb-5">
            <p className="px-3 pb-1.5 text-[11px] font-semibold uppercase tracking-wider text-brand-200/70">{group.label}</p>
            <ul className="space-y-0.5">
              {items.map((item) => (
                <li key={item.path}>
                  <NavLink
                    to={item.path}
                    end={item.path === '/admin'}
                    onClick={onNavigate}
                    className={({ isActive }) => cn(
                      'flex items-center gap-3 rounded-md px-3 py-2 text-sm transition-colors',
                      isActive ? 'bg-brand-500/25 font-medium text-white' : 'text-brand-50/80 hover:bg-white/10 hover:text-white',
                    )}
                  >
                    <item.icon className="h-4 w-4 shrink-0" aria-hidden />
                    <span className="flex-1 truncate">{item.label}</span>
                    {!item.ready && <span className="rounded bg-white/10 px-1.5 text-[10px] text-brand-100/70">Soon</span>}
                  </NavLink>
                </li>
              ))}
            </ul>
          </div>
        )
      })}
    </nav>
  )
}

function Breadcrumbs() {
  const { pathname } = useLocation()
  const item = allNavItems.find((i) => i.path === pathname)
  const extra: Record<string, string> = { '/admin/profile': 'My Profile' }
  const crumbs = item && item.path !== '/admin' ? [item.group, item.label] : extra[pathname] ? ['Account', extra[pathname]] : []
  return (
    <nav aria-label="Breadcrumb" className="hidden text-sm text-slate-500 sm:block">
      <ol className="flex items-center gap-1.5">
        <li><Link to="/admin" className="hover:text-slate-800">Dashboard</Link></li>
        {crumbs.map((c, i) => (
          <li key={c} className="flex items-center gap-1.5">
            <ChevronRight className="h-3.5 w-3.5" aria-hidden />
            <span className={i === crumbs.length - 1 ? 'font-medium text-slate-800' : ''} aria-current={i === crumbs.length - 1 ? 'page' : undefined}>{c}</span>
          </li>
        ))}
      </ol>
    </nav>
  )
}

export function AdminLayout() {
  const { user, logout } = useAuth()
  const navigate = useNavigate()
  const [mobileOpen, setMobileOpen] = useState(false)

  const brand = (
    <div className="flex h-16 items-center gap-3 border-b border-white/10 px-5">
      <img src="/logo.webp" alt="M&C Hotel Logo" className="h-9 w-9 rounded-md object-cover shadow-sm ring-1 ring-white/20" />
      <div className="leading-tight">
        <p className="font-semibold text-white">Mai Chitta Hotel</p>
        <p className="text-[11px] text-brand-200/80">Management System</p>
      </div>
    </div>
  )

  return (
    <div className="flex h-full">
      <a href="#main" className="sr-only focus:not-sr-only focus:absolute focus:z-50 focus:bg-white focus:p-2">Skip to content</a>
      {/* Desktop sidebar */}
      <aside className="hidden w-64 shrink-0 flex-col bg-brand-900 lg:flex">{brand}<Sidebar /></aside>
      {/* Mobile drawer */}
      {mobileOpen && (
        <div className="fixed inset-0 z-40 lg:hidden">
          <div className="absolute inset-0 bg-slate-900/50" onClick={() => setMobileOpen(false)} aria-hidden />
          <aside className="relative flex h-full w-72 flex-col bg-brand-900">
            <button className="absolute right-3 top-4 text-white" onClick={() => setMobileOpen(false)} aria-label="Close menu"><X className="h-5 w-5" /></button>
            {brand}<Sidebar onNavigate={() => setMobileOpen(false)} />
          </aside>
        </div>
      )}

      <div className="flex min-w-0 flex-1 flex-col">
        <header className="flex h-16 shrink-0 items-center gap-3 border-b border-slate-200 bg-white px-4 sm:px-6">
          <button className="rounded p-1.5 text-slate-600 hover:bg-slate-100 lg:hidden" onClick={() => setMobileOpen(true)} aria-label="Open menu"><Menu className="h-5 w-5" /></button>
          <Breadcrumbs />
          <div className="ml-auto flex items-center gap-2">
            <Link to="/" className="inline-flex items-center gap-1.5 rounded-md border border-slate-200 px-2.5 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50">
              <ExternalLink className="h-3.5 w-3.5" aria-hidden /> View Website
            </Link>
            <OperationalAlerts />

            <DropdownMenu.Root>
              <DropdownMenu.Trigger className="flex items-center gap-2 rounded-md px-2 py-1.5 text-sm hover:bg-slate-100">
                <span className="flex h-8 w-8 items-center justify-center rounded-full bg-brand-100 font-semibold text-brand-800">{user?.name.charAt(0).toUpperCase()}</span>
                <span className="hidden text-left leading-tight md:block">
                  <span className="block font-medium text-slate-800">{user?.name}</span>
                  <span className="block text-xs text-slate-500">{user?.roles?.map((r) => r.name).join(', ') || 'No role assigned'}</span>
                </span>
                <ChevronDown className="h-4 w-4 text-slate-400" />
              </DropdownMenu.Trigger>
              <DropdownMenu.Portal>
                <DropdownMenu.Content align="end" sideOffset={6} className="z-50 w-56 rounded-md border border-slate-200 bg-white p-1 text-sm shadow-lg">
                  <DropdownMenu.Label className="px-3 py-2 text-xs text-slate-500">{user?.email}</DropdownMenu.Label>
                  <DropdownMenu.Item onSelect={() => navigate('/admin/profile')} className="flex cursor-pointer items-center gap-2 rounded px-3 py-2 outline-none data-[highlighted]:bg-slate-100">
                    <UserCircle2 className="h-4 w-4" /> My profile
                  </DropdownMenu.Item>
                  <DropdownMenu.Separator className="my-1 h-px bg-slate-200" />
                  <DropdownMenu.Item onSelect={async () => { await logout(); navigate('/admin/login') }} className="flex cursor-pointer items-center gap-2 rounded px-3 py-2 text-red-700 outline-none data-[highlighted]:bg-red-50">
                    <LogOut className="h-4 w-4" /> Sign out
                  </DropdownMenu.Item>
                </DropdownMenu.Content>
              </DropdownMenu.Portal>
            </DropdownMenu.Root>
          </div>
        </header>
        <main id="main" className="flex-1 overflow-y-auto p-4 sm:p-6 lg:p-8"><Outlet /></main>
      </div>
    </div>
  )
}
