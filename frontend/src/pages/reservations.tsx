import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  BedDouble, Building2, CalendarCheck, CalendarRange, CheckCircle2,
  CreditCard, Plus, Printer, RefreshCw, Search, SlidersHorizontal, Tag, Trash2, UserSquare2,
} from 'lucide-react'
import { useAuth } from '@/auth/AuthProvider'
import { api, errorMessage, type Paginated } from '@/lib/api'
import {
  formatCurrency,
  type PaymentRefundRecord, type PaymentTransactionRecord, type PhysicalRoomRecord,
  type RatePlanRecord, type ReservationRecord, type RoomTypeRecord,
} from '@/lib/cms'
import { formatDateTime } from '@/lib/utils'
import {
  Alert, Badge, Button, Card, CardHeader, DataTable, EmptyState, ErrorState,
  FormField, Input, Modal, PageHeader, Select, Spinner, Textarea,
} from '@/components/ui'

function addDaysIso(days: number): string {
  const d = new Date()
  d.setDate(d.getDate() + days)
  return d.toISOString().slice(0, 10)
}

function statusTone(status: string): 'neutral' | 'brand' | 'success' | 'warning' | 'danger' {
  switch (status) {
    case 'confirmed':
    case 'checked_in':
    case 'paid':
    case 'completed':
    case 'processed':
    case 'active':
    case 'clean':
      return 'success'
    case 'pending_payment':
    case 'partially_paid':
    case 'partially_refunded':
    case 'pending':
    case 'dirty':
    case 'inspecting':
      return 'warning'
    case 'cancelled':
    case 'no_show':
    case 'expired':
    case 'payment_failed':
    case 'rejected':
    case 'out_of_service':
    case 'maintenance':
      return 'danger'
    default:
      return 'neutral'
  }
}

/* ========================================================================
 * 1. ROOM TYPES ADMIN PAGE (/admin/room-types)
 * ======================================================================== */
export function RoomTypesAdminPage() {
  const { can } = useAuth()
  const qc = useQueryClient()
  const [editing, setEditing] = useState<Partial<RoomTypeRecord> | null>(null)
  const [amenitiesInput, setAmenitiesInput] = useState('')
  const [msg, setMsg] = useState<{ t: 'success' | 'error'; m: string } | null>(null)

  const q = useQuery({
    queryKey: ['admin', 'room-types'],
    queryFn: async () => (await api.get<{ data: RoomTypeRecord[] }>('/admin/room-types')).data.data,
  })

  const saveMut = useMutation({
    mutationFn: async (payload: Partial<RoomTypeRecord>) => {
      const body = {
        ...payload,
        amenities: amenitiesInput.split(',').map((s) => s.trim()).filter(Boolean),
      }
      if (payload.id) {
        return (await api.put<{ message: string; data: RoomTypeRecord }>(`/admin/room-types/${payload.id}`, body)).data
      }
      return (await api.post<{ message: string; data: RoomTypeRecord }>('/admin/room-types', body)).data
    },
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['admin', 'room-types'] })
      setMsg({ t: 'success', m: res.message })
      setEditing(null)
    },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  const openEditor = (rt?: RoomTypeRecord) => {
    setMsg(null)
    if (rt) {
      setEditing({ ...rt })
      setAmenitiesInput((rt.amenities ?? []).join(', '))
    } else {
      setEditing({
        name: '',
        slug: '',
        code: '',
        subtitle: '',
        description: '',
        public_description: '',
        max_adults: 2,
        max_children: 1,
        max_occupancy: 3,
        base_occupancy: 2,
        bed_configuration: '1 King Bed',
        size_label: '',
        starting_rate: null,
        currency_code: 'NGN',
        is_active: true,
        is_public: true,
        is_featured: false,
        sort_order: 0,
        housekeeping_notes: '',
        operational_notes: '',
      })
      setAmenitiesInput('')
    }
  }

  return (
    <>
      <PageHeader
        title="Room Types & Categories"
        description="Configure accommodation categories, occupancy limits, amenities, and public showcase details."
        actions={can('rooms.create') && <Button onClick={() => openEditor()}><Plus className="h-4 w-4" /> New Room Type</Button>}
      />
      {msg && <div className="mb-4"><Alert variant={msg.t}>{msg.m}</Alert></div>}
      {q.isLoading ? <Spinner /> : q.isError ? <ErrorState message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : (
        <Card>
          <DataTable
            rows={q.data ?? []}
            rowKey={(r) => r.id}
            empty={<EmptyState icon={BedDouble} title="No room types configured yet" description="Create your first room category (e.g., Standard Room, Deluxe Suite) so physical rooms and rate plans can be attached." />}
            columns={[
              {
                key: 'name',
                header: 'Room Type',
                cell: (r) => (
                  <div>
                    <div className="font-medium text-slate-900">{r.name} {r.code && <span className="text-xs text-slate-400">({r.code})</span>}</div>
                    <div className="text-xs text-slate-500">/{r.slug} · {r.bed_configuration || 'Beds not specified'}</div>
                  </div>
                ),
              },
              {
                key: 'occupancy',
                header: 'Occupancy',
                cell: (r) => <span className="text-xs text-slate-700">Max {r.max_occupancy} ({r.max_adults}A / {r.max_children}C) · Base {r.base_occupancy}</span>,
              },
              {
                key: 'rooms',
                header: 'Physical Rooms',
                cell: (r) => (
                  <div className="flex flex-wrap gap-1.5 text-xs">
                    <Badge tone="success">{r.active_rooms_count ?? 0} active</Badge>
                    {(r.out_of_service_rooms_count ?? 0) > 0 && <Badge tone="danger">{r.out_of_service_rooms_count} OOS</Badge>}
                  </div>
                ),
              },
              {
                key: 'rate',
                header: 'Indicative Rate',
                cell: (r) => <span className="font-medium">{formatCurrency(r.starting_rate, r.currency_code)}</span>,
              },
              {
                key: 'status',
                header: 'Status',
                cell: (r) => (
                  <div className="flex gap-1.5">
                    <Badge tone={r.is_active ? 'success' : 'neutral'}>{r.is_active ? 'Active' : 'Inactive'}</Badge>
                    {r.is_public && <Badge tone="brand">Public</Badge>}
                  </div>
                ),
              },
              {
                key: 'actions',
                header: 'Actions',
                cell: (r) => can('rooms.edit') ? (
                  <Button size="sm" variant="secondary" onClick={() => openEditor(r)}>Edit</Button>
                ) : null,
              },
            ]}
          />
        </Card>
      )}

      <Modal open={!!editing} onOpenChange={(o) => !o && setEditing(null)} title={editing?.id ? `Edit ${editing.name}` : 'New Room Type'} wide>
        {editing && (
          <form
            className="space-y-4"
            onSubmit={(e) => { e.preventDefault(); saveMut.mutate(editing) }}
          >
            <div className="grid gap-4 sm:grid-cols-3">
              <FormField id="rt-name" label="Name *">
                <Input
                  id="rt-name"
                  value={editing.name ?? ''}
                  onChange={(e) => {
                    const name = e.target.value
                    const slug = editing.id ? editing.slug : name.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '')
                    setEditing({ ...editing, name, slug })
                  }}
                  required
                />
              </FormField>
              <FormField id="rt-slug" label="URL Slug *">
                <Input id="rt-slug" value={editing.slug ?? ''} onChange={(e) => setEditing({ ...editing, slug: e.target.value })} required />
              </FormField>
              <FormField id="rt-code" label="Short Code">
                <Input id="rt-code" placeholder="DLX" value={editing.code ?? ''} onChange={(e) => setEditing({ ...editing, code: e.target.value })} />
              </FormField>
            </div>

            <div className="grid gap-4 sm:grid-cols-4">
              <FormField id="rt-base-occ" label="Base Occupancy *">
                <Input id="rt-base-occ" type="number" min={1} max={20} value={editing.base_occupancy ?? 2} onChange={(e) => setEditing({ ...editing, base_occupancy: Number(e.target.value) })} required />
              </FormField>
              <FormField id="rt-max-adults" label="Max Adults *">
                <Input id="rt-max-adults" type="number" min={1} max={20} value={editing.max_adults ?? 2} onChange={(e) => setEditing({ ...editing, max_adults: Number(e.target.value) })} required />
              </FormField>
              <FormField id="rt-max-children" label="Max Children *">
                <Input id="rt-max-children" type="number" min={0} max={20} value={editing.max_children ?? 0} onChange={(e) => setEditing({ ...editing, max_children: Number(e.target.value) })} required />
              </FormField>
              <FormField id="rt-max-occ" label="Max Total Guests *">
                <Input id="rt-max-occ" type="number" min={1} max={30} value={editing.max_occupancy ?? 2} onChange={(e) => setEditing({ ...editing, max_occupancy: Number(e.target.value) })} required />
              </FormField>
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
              <FormField id="rt-beds" label="Bed Configuration">
                <Input id="rt-beds" placeholder="1 King Bed" value={editing.bed_configuration ?? ''} onChange={(e) => setEditing({ ...editing, bed_configuration: e.target.value })} />
              </FormField>
              <FormField id="rt-size" label="Room Size Label">
                <Input id="rt-size" placeholder="38 m²" value={editing.size_label ?? ''} onChange={(e) => setEditing({ ...editing, size_label: e.target.value })} />
              </FormField>
              <FormField id="rt-rate" label="Indicative Starting Rate (NGN)">
                <Input
                  id="rt-rate"
                  type="number"
                  min={0}
                  step="0.01"
                  placeholder="Optional display rate"
                  value={editing.starting_rate ?? ''}
                  onChange={(e) => setEditing({ ...editing, starting_rate: e.target.value === '' ? null : Number(e.target.value) })}
                />
              </FormField>
            </div>

            <FormField id="rt-amenities" label="Amenities (comma-separated)">
              <Input id="rt-amenities" placeholder="Air Conditioning, Work Desk, En-suite Bathroom, Wi-Fi" value={amenitiesInput} onChange={(e) => setAmenitiesInput(e.target.value)} />
            </FormField>

            <FormField id="rt-desc" label="Summary Description">
              <Textarea id="rt-desc" rows={2} value={editing.description ?? ''} onChange={(e) => setEditing({ ...editing, description: e.target.value })} />
            </FormField>

            <div className="grid gap-4 sm:grid-cols-2">
              <FormField id="rt-hk-notes" label="Internal Housekeeping Notes (staff only)">
                <Textarea id="rt-hk-notes" rows={2} value={editing.housekeeping_notes ?? ''} onChange={(e) => setEditing({ ...editing, housekeeping_notes: e.target.value })} />
              </FormField>
              <FormField id="rt-ops-notes" label="Internal Operational Notes (staff only)">
                <Textarea id="rt-ops-notes" rows={2} value={editing.operational_notes ?? ''} onChange={(e) => setEditing({ ...editing, operational_notes: e.target.value })} />
              </FormField>
            </div>

            <div className="flex flex-wrap gap-6 pt-2 text-sm">
              <label className="inline-flex items-center gap-2">
                <input type="checkbox" checked={!!editing.is_active} onChange={(e) => setEditing({ ...editing, is_active: e.target.checked })} />
                Active for bookings
              </label>
              <label className="inline-flex items-center gap-2">
                <input type="checkbox" checked={!!editing.is_public} onChange={(e) => setEditing({ ...editing, is_public: e.target.checked })} />
                Visible on public website
              </label>
              <label className="inline-flex items-center gap-2">
                <input type="checkbox" checked={!!editing.is_featured} onChange={(e) => setEditing({ ...editing, is_featured: e.target.checked })} />
                Featured on homepage
              </label>
            </div>

            <div className="flex justify-end gap-2 border-t border-slate-100 pt-4">
              <Button type="button" variant="secondary" onClick={() => setEditing(null)}>Cancel</Button>
              <Button type="submit" loading={saveMut.isPending}>Save Room Type</Button>
            </div>
          </form>
        )}
      </Modal>
    </>
  )
}

/* ========================================================================
 * 2. PHYSICAL ROOMS ADMIN PAGE (/admin/rooms)
 * ======================================================================== */
export function RoomsAdminPage() {
  const { can } = useAuth()
  const qc = useQueryClient()
  const [editing, setEditing] = useState<Partial<PhysicalRoomRecord> | null>(null)
  const [filterType, setFilterType] = useState('')
  const [filterStatus, setFilterStatus] = useState('')
  const [msg, setMsg] = useState<{ t: 'success' | 'error'; m: string } | null>(null)

  const typesQ = useQuery({
    queryKey: ['admin', 'room-types'],
    queryFn: async () => (await api.get<{ data: RoomTypeRecord[] }>('/admin/room-types')).data.data,
  })

  const roomsQ = useQuery({
    queryKey: ['admin', 'rooms', filterType, filterStatus],
    queryFn: async () => (await api.get<{ data: PhysicalRoomRecord[] }>('/admin/rooms', {
      params: { room_type_id: filterType || undefined, operational_status: filterStatus || undefined },
    })).data.data,
  })

  const saveMut = useMutation({
    mutationFn: async (payload: Partial<PhysicalRoomRecord>) => {
      if (payload.id) {
        return (await api.put<{ message: string; data: PhysicalRoomRecord }>(`/admin/rooms/${payload.id}`, payload)).data
      }
      return (await api.post<{ message: string; data: PhysicalRoomRecord }>('/admin/rooms', payload)).data
    },
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['admin', 'rooms'] })
      qc.invalidateQueries({ queryKey: ['admin', 'room-types'] })
      setMsg({ t: 'success', m: res.message })
      setEditing(null)
    },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  return (
    <>
      <PageHeader
        title="Physical Rooms Inventory"
        description="Manage individual room numbers, floors, housekeeping states, and out-of-service windows."
        actions={can('rooms.create') && (
          <Button
            onClick={() => {
              setMsg(null)
              setEditing({
                room_number: '',
                room_type_id: typesQ.data?.[0]?.id ?? 0,
                floor: '',
                building: '',
                operational_status: 'active',
                housekeeping_status: 'clean',
                out_of_service_from: null,
                out_of_service_until: null,
                out_of_service_reason: '',
                internal_notes: '',
              })
            }}
          >
            <Plus className="h-4 w-4" /> Add Physical Room
          </Button>
        )}
      />

      {msg && <div className="mb-4"><Alert variant={msg.t}>{msg.m}</Alert></div>}

      <Card className="mb-4 p-4">
        <div className="grid gap-3 sm:grid-cols-3">
          <Select value={filterType} onChange={(e) => setFilterType(e.target.value)} aria-label="Filter by Room Type">
            <option value="">All Room Types</option>
            {(typesQ.data ?? []).map((rt) => <option key={rt.id} value={rt.id}>{rt.name}</option>)}
          </Select>
          <Select value={filterStatus} onChange={(e) => setFilterStatus(e.target.value)} aria-label="Filter by Operational Status">
            <option value="">All Operational Statuses</option>
            <option value="active">Active (Sellable)</option>
            <option value="out_of_service">Out of Service</option>
            <option value="inactive">Inactive</option>
          </Select>
        </div>
      </Card>

      {roomsQ.isLoading ? <Spinner /> : roomsQ.isError ? <ErrorState message={errorMessage(roomsQ.error)} onRetry={() => roomsQ.refetch()} /> : (
        <Card>
          <DataTable
            rows={roomsQ.data ?? []}
            rowKey={(r) => r.id}
            empty={<EmptyState icon={Building2} title="No physical rooms configured" description="Add physical rooms and assign them to room types to enable availability and room assignments." />}
            columns={[
              { key: 'num', header: 'Room #', cell: (r) => <span className="font-bold text-slate-900">{r.room_number}</span> },
              { key: 'type', header: 'Room Type', cell: (r) => r.room_type?.name ?? `Type #${r.room_type_id}` },
              { key: 'floor', header: 'Floor / Building', cell: (r) => [r.floor, r.building].filter(Boolean).join(' · ') || '—' },
              {
                key: 'op',
                header: 'Operational Status',
                cell: (r) => (
                  <div>
                    <Badge tone={statusTone(r.operational_status)}>{r.operational_status.replace(/_/g, ' ')}</Badge>
                    {r.operational_status === 'out_of_service' && (
                      <div className="mt-1 text-xs text-rose-700">
                        {r.out_of_service_from || 'Now'} → {r.out_of_service_until || 'Indefinite'}
                        {r.out_of_service_reason && ` (${r.out_of_service_reason})`}
                      </div>
                    )}
                  </div>
                ),
              },
              {
                key: 'hk',
                header: 'Housekeeping',
                cell: (r) => <Badge tone={statusTone(r.housekeeping_status)}>{r.housekeeping_status}</Badge>,
              },
              {
                key: 'notes',
                header: 'Internal Notes',
                cell: (r) => <span className="text-xs text-slate-500">{r.internal_notes || '—'}</span>,
              },
              {
                key: 'actions',
                header: 'Actions',
                cell: (r) => (can('rooms.edit') || can('housekeeping.update')) ? (
                  <Button size="sm" variant="secondary" onClick={() => { setMsg(null); setEditing({ ...r }) }}>Edit</Button>
                ) : null,
              },
            ]}
          />
        </Card>
      )}

      <Modal open={!!editing} onOpenChange={(o) => !o && setEditing(null)} title={editing?.id ? `Edit Room ${editing.room_number}` : 'Add Physical Room'}>
        {editing && (
          <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); saveMut.mutate(editing) }}>
            <div className="grid gap-4 sm:grid-cols-2">
              <FormField id="rm-num" label="Room Number *">
                <Input id="rm-num" value={editing.room_number ?? ''} onChange={(e) => setEditing({ ...editing, room_number: e.target.value })} required />
              </FormField>
              <FormField id="rm-type" label="Room Type *">
                <Select id="rm-type" value={editing.room_type_id ?? ''} onChange={(e) => setEditing({ ...editing, room_type_id: Number(e.target.value) })} required>
                  <option value="">Select Room Type</option>
                  {(typesQ.data ?? []).map((rt) => <option key={rt.id} value={rt.id}>{rt.name}</option>)}
                </Select>
              </FormField>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <FormField id="rm-floor" label="Floor">
                <Input id="rm-floor" placeholder="Ground / 1st Floor" value={editing.floor ?? ''} onChange={(e) => setEditing({ ...editing, floor: e.target.value })} />
              </FormField>
              <FormField id="rm-wing" label="Building / Wing">
                <Input id="rm-wing" placeholder="Main Wing" value={editing.building ?? ''} onChange={(e) => setEditing({ ...editing, building: e.target.value })} />
              </FormField>
            </div>
            <div className="grid gap-4 sm:grid-cols-2">
              <FormField id="rm-op-status" label="Operational Status *">
                <Select
                  id="rm-op-status"
                  value={editing.operational_status ?? 'active'}
                  onChange={(e) => setEditing({ ...editing, operational_status: e.target.value as PhysicalRoomRecord['operational_status'] })}
                >
                  <option value="active">Active (Sellable)</option>
                  <option value="out_of_service">Out of Service</option>
                  <option value="inactive">Inactive</option>
                </Select>
              </FormField>
              <FormField id="rm-hk-status" label="Housekeeping Status *">
                <Select
                  id="rm-hk-status"
                  value={editing.housekeeping_status ?? 'clean'}
                  onChange={(e) => setEditing({ ...editing, housekeeping_status: e.target.value as PhysicalRoomRecord['housekeeping_status'] })}
                >
                  <option value="clean">Clean</option>
                  <option value="dirty">Dirty</option>
                  <option value="inspecting">Inspecting</option>
                  <option value="maintenance">Maintenance</option>
                </Select>
              </FormField>
            </div>

            {editing.operational_status === 'out_of_service' && (
              <div className="space-y-3 rounded-lg border border-rose-200 bg-rose-50/50 p-3">
                <div className="grid gap-3 sm:grid-cols-2">
                  <FormField id="oos-from" label="Out of Service From">
                    <Input id="oos-from" type="date" value={editing.out_of_service_from ?? ''} onChange={(e) => setEditing({ ...editing, out_of_service_from: e.target.value || null })} />
                  </FormField>
                  <FormField id="oos-until" label="Out of Service Until">
                    <Input id="oos-until" type="date" value={editing.out_of_service_until ?? ''} onChange={(e) => setEditing({ ...editing, out_of_service_until: e.target.value || null })} />
                  </FormField>
                </div>
                <FormField id="oos-reason" label="Out of Service Reason">
                  <Input id="oos-reason" value={editing.out_of_service_reason ?? ''} onChange={(e) => setEditing({ ...editing, out_of_service_reason: e.target.value })} placeholder="Plumbing / AC repair…" />
                </FormField>
              </div>
            )}

            <FormField id="rm-notes" label="Internal Operational Notes (never shown publicly)">
              <Textarea id="rm-notes" rows={2} value={editing.internal_notes ?? ''} onChange={(e) => setEditing({ ...editing, internal_notes: e.target.value })} />
            </FormField>

            <div className="flex justify-end gap-2 border-t border-slate-100 pt-4">
              <Button type="button" variant="secondary" onClick={() => setEditing(null)}>Cancel</Button>
              <Button type="submit" loading={saveMut.isPending}>Save Room</Button>
            </div>
          </form>
        )}
      </Modal>
    </>
  )
}

/* ========================================================================
 * 3. RATE PLANS & PRICING OVERRIDES ADMIN PAGE (/admin/rate-plans)
 * ======================================================================== */
export function RatePlansAdminPage() {
  const { can } = useAuth()
  const qc = useQueryClient()
  const [editing, setEditing] = useState<Partial<RatePlanRecord> | null>(null)
  const [roomRates, setRoomRates] = useState<Record<number, { enabled: boolean; base_rate: number; extra_adult_rate: number; extra_child_rate: number }>>({})
  const [overrideModalPlan, setOverrideModalPlan] = useState<RatePlanRecord | null>(null)
  const [ovRoomTypeId, setOvRoomTypeId] = useState<number>(0)
  const [ovType, setOvType] = useState<'exact_date' | 'date_range'>('date_range')
  const [ovLabel, setOvLabel] = useState('')
  const [ovStart, setOvStart] = useState(() => addDaysIso(1))
  const [ovEnd, setOvEnd] = useState(() => addDaysIso(2))
  const [ovRate, setOvRate] = useState('')
  const [ovMinStay, setOvMinStay] = useState('')
  const [ovClosed, setOvClosed] = useState(false)
  const [msg, setMsg] = useState<{ t: 'success' | 'error'; m: string } | null>(null)

  const plansQ = useQuery({
    queryKey: ['admin', 'rate-plans'],
    queryFn: async () => (await api.get<{ data: RatePlanRecord[] }>('/admin/rate-plans')).data.data,
  })
  const typesQ = useQuery({
    queryKey: ['admin', 'room-types'],
    queryFn: async () => (await api.get<{ data: RoomTypeRecord[] }>('/admin/room-types')).data.data,
  })

  const savePlanMut = useMutation({
    mutationFn: async (plan: Partial<RatePlanRecord>) => {
      const room_type_rates = Object.entries(roomRates)
        .filter(([, cfg]) => cfg.enabled)
        .map(([rtId, cfg]) => ({
          room_type_id: Number(rtId),
          base_rate: cfg.base_rate,
          extra_adult_rate: cfg.extra_adult_rate,
          extra_child_rate: cfg.extra_child_rate,
          is_active: true,
        }))
      const body = {
        ...plan,
        meal_basis: plan.meal_Basis ?? 'room_only',
        room_type_rates,
      }
      if (plan.id) {
        return (await api.put<{ message: string; data: RatePlanRecord }>(`/admin/rate-plans/${plan.id}`, body)).data
      }
      return (await api.post<{ message: string; data: RatePlanRecord }>('/admin/rate-plans', body)).data
    },
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['admin', 'rate-plans'] })
      setMsg({ t: 'success', m: res.message })
      setEditing(null)
    },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  const addOverrideMut = useMutation({
    mutationFn: async () => {
      if (!overrideModalPlan) return
      return (await api.post(`/admin/rate-plans/${overrideModalPlan.id}/overrides`, {
        room_type_id: ovRoomTypeId,
        label: ovLabel || undefined,
        override_type: ovType,
        start_date: ovStart,
        end_date: ovType === 'exact_date' ? ovStart : ovEnd,
        nightly_rate: ovRate !== '' ? Number(ovRate) : null,
        min_stay_nights: ovMinStay !== '' ? Number(ovMinStay) : null,
        closed_to_sell: ovClosed,
      })).data
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'rate-plans'] })
      setMsg({ t: 'success', m: 'Date-specific rate override added.' })
      setOvLabel('')
      setOvRate('')
    },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  const delOverrideMut = useMutation({
    mutationFn: async ({ planId, ovId }: { planId: number; ovId: number }) =>
      (await api.delete(`/admin/rate-plans/${planId}/overrides/${ovId}`)).data,
    onSuccess: () => qc.invalidateQueries({ queryKey: ['admin', 'rate-plans'] }),
  })

  const openPlanEditor = (rp?: RatePlanRecord) => {
    setMsg(null)
    const types = typesQ.data ?? []
    const map: Record<number, { enabled: boolean; base_rate: number; extra_adult_rate: number; extra_child_rate: number }> = {}
    for (const rt of types) {
      const existing = rp?.room_type_rates?.find((p) => p.room_type_id === rt.id)
      map[rt.id] = {
        enabled: existing ? existing.is_active : true,
        base_rate: existing ? existing.base_rate : (rp?.default_base_rate ?? rt.starting_rate ?? 0),
        extra_adult_rate: existing ? existing.extra_adult_rate : (rp?.extra_adult_rate ?? 0),
        extra_child_rate: existing ? existing.extra_child_rate : (rp?.extra_child_rate ?? 0),
      }
    }
    setRoomRates(map)
    if (rp) {
      setEditing({ ...rp })
    } else {
      setEditing({
        name: '',
        code: '',
        description: '',
        meal_Basis: 'room_only',
        cancellation_policy_type: 'flexible',
        free_cancellation_hours: 24,
        cancellation_penalty_type: 'first_night',
        cancellation_penalty_value: 0,
        cancellation_terms: '',
        currency_code: 'NGN',
        default_base_rate: 0,
        extra_adult_rate: 0,
        extra_child_rate: 0,
        min_stay_nights: 1,
        max_stay_nights: 30,
        is_active: true,
        is_public: true,
        is_refundable: true,
      })
    }
  }

  return (
    <>
      <PageHeader
        title="Rate Plans & Pricing Rules"
        description="Configure base rates, room-type eligibility, meal inclusions, cancellation terms, and seasonal/date-specific overrides."
        actions={can('rooms.rates') && <Button onClick={() => openPlanEditor()}><Plus className="h-4 w-4" /> New Rate Plan</Button>}
      />
      {msg && <div className="mb-4"><Alert variant={msg.t}>{msg.m}</Alert></div>}

      <Alert variant="info" title="Deterministic Pricing Precedence">
        For each stay night, the availability engine resolves rates in strict order: <strong>(1) Exact-Date Override</strong> → <strong>(2) Seasonal Date-Range Override</strong> → <strong>(3) Weekday Rate</strong> → <strong>(4) Room-Type Rate Plan Base Rate</strong>.
      </Alert>

      <div className="mt-4">
        {plansQ.isLoading ? <Spinner /> : plansQ.isError ? <ErrorState message={errorMessage(plansQ.error)} onRetry={() => plansQ.refetch()} /> : (
          <Card>
            <DataTable
              rows={plansQ.data ?? []}
              rowKey={(r) => r.id}
              empty={<EmptyState icon={Tag} title="No rate plans configured" description="Create a rate plan and link it to your room types to enable quotes and reservations." />}
              columns={[
                {
                  key: 'plan',
                  header: 'Rate Plan',
                  cell: (r) => (
                    <div>
                      <div className="font-semibold text-slate-900">{r.name} <Badge tone="brand">{r.code}</Badge></div>
                      <div className="text-xs text-slate-500">{r.meal_Basis.replace(/_/g, ' ')} · Min stay {r.min_stay_nights}n</div>
                    </div>
                  ),
                },
                {
                  key: 'base',
                  header: 'Default Base Rate',
                  cell: (r) => <span className="font-medium">{formatCurrency(r.default_base_rate, r.currency_code)}</span>,
                },
                {
                  key: 'rooms',
                  header: 'Eligible Room Types',
                  cell: (r) => (
                    <div className="flex flex-wrap gap-1">
                      {(r.room_type_rates ?? []).map((p) => (
                        <Badge key={p.room_type_id}>
                          {p.room_type?.name ?? `#${p.room_type_id}`}: {formatCurrency(p.base_rate, r.currency_code)}
                        </Badge>
                      ))}
                    </div>
                  ),
                },
                {
                  key: 'cancellation',
                  header: 'Cancellation Policy',
                  cell: (r) => (
                    <span className="text-xs text-slate-600">
                      {r.cancellation_policy_type} ({r.is_refundable ? 'Refundable' : 'Non-refundable'})
                    </span>
                  ),
                },
                {
                  key: 'overrides',
                  header: 'Overrides',
                  cell: (r) => <Badge>{(r.overrides ?? []).length} override(s)</Badge>,
                },
                {
                  key: 'actions',
                  header: 'Actions',
                  cell: (r) => can('rooms.rates') ? (
                    <div className="flex gap-2">
                      <Button size="sm" variant="secondary" onClick={() => openPlanEditor(r)}>Edit</Button>
                      <Button
                        size="sm"
                        variant="secondary"
                        onClick={() => {
                          setOverrideModalPlan(r)
                          setOvRoomTypeId(typesQ.data?.[0]?.id ?? 0)
                        }}
                      >
                        Date Overrides
                      </Button>
                    </div>
                  ) : null,
                },
              ]}
            />
          </Card>
        )}
      </div>

      {/* Rate Plan Editor Modal */}
      <Modal open={!!editing} onOpenChange={(o) => !o && setEditing(null)} title={editing?.id ? `Edit ${editing.name}` : 'Create Rate Plan'} wide>
        {editing && (
          <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); savePlanMut.mutate(editing) }}>
            <div className="grid gap-4 sm:grid-cols-3">
              <FormField id="rp-name" label="Plan Name *">
                <Input id="rp-name" value={editing.name ?? ''} onChange={(e) => setEditing({ ...editing, name: e.target.value })} required placeholder="Flexible Standard Rate" />
              </FormField>
              <FormField id="rp-code" label="Plan Code *">
                <Input id="rp-code" value={editing.code ?? ''} onChange={(e) => setEditing({ ...editing, code: e.target.value.toUpperCase() })} required placeholder="BAR" />
              </FormField>
              <FormField id="rp-meal" label="Meal Inclusion">
                <Select id="rp-meal" value={editing.meal_Basis ?? 'room_only'} onChange={(e) => setEditing({ ...editing, meal_Basis: e.target.value as RatePlanRecord['meal_Basis'] })}>
                  <option value="room_only">Room Only</option>
                  <option value="breakfast_included">Breakfast Included</option>
                  <option value="half_board">Half Board</option>
                  <option value="full_board">Full Board</option>
                </Select>
              </FormField>
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
              <FormField id="rp-base" label="Default Base Rate (NGN) *">
                <Input id="rp-base" type="number" min={0} step="0.01" value={editing.default_base_rate ?? 0} onChange={(e) => setEditing({ ...editing, default_base_rate: Number(e.target.value) })} required />
              </FormField>
              <FormField id="rp-ex-adult" label="Extra Adult Charge (NGN)">
                <Input id="rp-ex-adult" type="number" min={0} step="0.01" value={editing.extra_adult_rate ?? 0} onChange={(e) => setEditing({ ...editing, extra_adult_rate: Number(e.target.value) })} />
              </FormField>
              <FormField id="rp-ex-child" label="Extra Child Charge (NGN)">
                <Input id="rp-ex-child" type="number" min={0} step="0.01" value={editing.extra_child_rate ?? 0} onChange={(e) => setEditing({ ...editing, extra_child_rate: Number(e.target.value) })} />
              </FormField>
            </div>

            <div className="grid gap-4 sm:grid-cols-4">
              <FormField id="rp-min-stay" label="Min Stay Nights">
                <Input id="rp-min-stay" type="number" min={1} max={365} value={editing.min_stay_nights ?? 1} onChange={(e) => setEditing({ ...editing, min_stay_nights: Number(e.target.value) })} />
              </FormField>
              <FormField id="rp-max-stay" label="Max Stay Nights">
                <Input id="rp-max-stay" type="number" min={1} max={365} value={editing.max_stay_nights ?? ''} onChange={(e) => setEditing({ ...editing, max_stay_nights: e.target.value ? Number(e.target.value) : null })} />
              </FormField>
              <FormField id="rp-from" label="Effective From">
                <Input id="rp-from" type="date" value={editing.effective_from ?? ''} onChange={(e) => setEditing({ ...editing, effective_from: e.target.value || null })} />
              </FormField>
              <FormField id="rp-until" label="Effective Until">
                <Input id="rp-until" type="date" value={editing.effective_until ?? ''} onChange={(e) => setEditing({ ...editing, effective_until: e.target.value || null })} />
              </FormField>
            </div>

            <div className="rounded-lg border border-slate-200 bg-slate-50 p-4">
              <h4 className="text-xs font-bold uppercase tracking-wider text-slate-700">Room-Type Specific Rates & Eligibility</h4>
              <div className="mt-3 space-y-2">
                {(typesQ.data ?? []).map((rt) => {
                  const cfg = roomRates[rt.id] ?? { enabled: false, base_rate: editing.default_base_rate ?? 0, extra_adult_rate: 0, extra_child_rate: 0 }
                  return (
                    <div key={rt.id} className="grid items-center gap-3 rounded bg-white p-3 text-sm sm:grid-cols-4">
                      <label className="inline-flex items-center gap-2 font-medium">
                        <input
                          type="checkbox"
                          checked={cfg.enabled}
                          onChange={(e) => setRoomRates({ ...roomRates, [rt.id]: { ...cfg, enabled: e.target.checked } })}
                        />
                        {rt.name}
                      </label>
                      <div>
                        <span className="text-xs text-slate-500">Nightly Rate (NGN)</span>
                        <Input
                          type="number"
                          min={0}
                          value={cfg.base_rate}
                          onChange={(e) => setRoomRates({ ...roomRates, [rt.id]: { ...cfg, base_rate: Number(e.target.value) } })}
                        />
                      </div>
                      <div>
                        <span className="text-xs text-slate-500">Extra Adult (NGN)</span>
                        <Input
                          type="number"
                          min={0}
                          value={cfg.extra_adult_rate}
                          onChange={(e) => setRoomRates({ ...roomRates, [rt.id]: { ...cfg, extra_adult_rate: Number(e.target.value) } })}
                        />
                      </div>
                      <div>
                        <span className="text-xs text-slate-500">Extra Child (NGN)</span>
                        <Input
                          type="number"
                          min={0}
                          value={cfg.extra_child_rate}
                          onChange={(e) => setRoomRates({ ...roomRates, [rt.id]: { ...cfg, extra_child_rate: Number(e.target.value) } })}
                        />
                      </div>
                    </div>
                  )
                })}
              </div>
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
              <FormField id="rp-canc-type" label="Cancellation Policy Type">
                <Select
                  id="rp-canc-type"
                  value={editing.cancellation_policy_type ?? 'unconfigured'}
                  onChange={(e) => setEditing({ ...editing, cancellation_policy_type: e.target.value as RatePlanRecord['cancellation_policy_type'] })}
                >
                  <option value="unconfigured">Setup Required / Unconfigured</option>
                  <option value="flexible">Flexible</option>
                  <option value="moderate">Moderate</option>
                  <option value="non_refundable">Non-Refundable</option>
                  <option value="custom">Custom</option>
                </Select>
              </FormField>
              <FormField id="rp-free-hrs" label="Free Cancellation Cutoff (Hours)">
                <Input id="rp-free-hrs" type="number" min={0} max={720} value={editing.free_cancellation_hours ?? ''} onChange={(e) => setEditing({ ...editing, free_cancellation_hours: e.target.value ? Number(e.target.value) : null })} />
              </FormField>
              <FormField id="rp-pen-type" label="Late Cancellation Penalty">
                <Select
                  id="rp-pen-type"
                  value={editing.cancellation_penalty_type ?? 'none'}
                  onChange={(e) => setEditing({ ...editing, cancellation_penalty_type: e.target.value as RatePlanRecord['cancellation_penalty_type'] })}
                >
                  <option value="none">No Automatic Fee</option>
                  <option value="first_night">First Night Charge</option>
                  <option value="percentage">Percentage of Total Stay</option>
                  <option value="full_stay">Full Stay Charge</option>
                </Select>
              </FormField>
            </div>

            <FormField id="rp-terms" label="Cancellation Terms Text (shown on quotes & confirmations)">
              <Textarea id="rp-terms" rows={2} value={editing.cancellation_terms ?? ''} onChange={(e) => setEditing({ ...editing, cancellation_terms: e.target.value })} />
            </FormField>

            <div className="flex flex-wrap gap-6 text-sm">
              <label className="inline-flex items-center gap-2">
                <input type="checkbox" checked={!!editing.is_active} onChange={(e) => setEditing({ ...editing, is_active: e.target.checked })} />
                Active
              </label>
              <label className="inline-flex items-center gap-2">
                <input type="checkbox" checked={!!editing.is_public} onChange={(e) => setEditing({ ...editing, is_public: e.target.checked })} />
                Publicly bookable online
              </label>
              <label className="inline-flex items-center gap-2">
                <input type="checkbox" checked={!!editing.is_refundable} onChange={(e) => setEditing({ ...editing, is_refundable: e.target.checked })} />
                Refundable rate plan
              </label>
            </div>

            <div className="flex justify-end gap-2 border-t border-slate-100 pt-4">
              <Button type="button" variant="secondary" onClick={() => setEditing(null)}>Cancel</Button>
              <Button type="submit" loading={savePlanMut.isPending}>Save Rate Plan</Button>
            </div>
          </form>
        )}
      </Modal>

      {/* Date-Specific Overrides Modal */}
      <Modal open={!!overrideModalPlan} onOpenChange={(o) => !o && setOverrideModalPlan(null)} title={`Date Overrides — ${overrideModalPlan?.name}`} wide>
        {overrideModalPlan && (
          <div className="space-y-6">
            <form
              className="grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-4 sm:grid-cols-3"
              onSubmit={(e) => { e.preventDefault(); addOverrideMut.mutate() }}
            >
              <FormField id="ov-rt" label="Room Type *">
                <Select id="ov-rt" value={ovRoomTypeId} onChange={(e) => setOvRoomTypeId(Number(e.target.value))} required>
                  {(typesQ.data ?? []).map((rt) => <option key={rt.id} value={rt.id}>{rt.name}</option>)}
                </Select>
              </FormField>
              <FormField id="ov-type" label="Override Precedence Type">
                <Select id="ov-type" value={ovType} onChange={(e) => setOvType(e.target.value as 'exact_date' | 'date_range')}>
                  <option value="exact_date">Exact Single Date (Highest Priority)</option>
                  <option value="date_range">Seasonal Date Range</option>
                </Select>
              </FormField>
              <FormField id="ov-label" label="Label / Event">
                <Input id="ov-label" placeholder="Festive / Convocation Rate" value={ovLabel} onChange={(e) => setOvLabel(e.target.value)} />
              </FormField>
              <FormField id="ov-start" label="Start Date *">
                <Input id="ov-start" type="date" value={ovStart} onChange={(e) => setOvStart(e.target.value)} required />
              </FormField>
              {ovType === 'date_range' && (
                <FormField id="ov-end" label="End Date (inclusive) *">
                  <Input id="ov-end" type="date" min={ovStart} value={ovEnd} onChange={(e) => setOvEnd(e.target.value)} required />
                </FormField>
              )}
              <FormField id="ov-rate" label="Override Nightly Rate (NGN)">
                <Input id="ov-rate" type="number" min={0} step="0.01" placeholder="e.g. 55000" value={ovRate} onChange={(e) => setOvRate(e.target.value)} />
              </FormField>
              <FormField id="ov-min" label="Min Stay Nights">
                <Input id="ov-min" type="number" min={1} max={60} value={ovMinStay} onChange={(e) => setOvMinStay(e.target.value)} />
              </FormField>
              <div className="flex items-center pt-5 text-sm">
                <label className="inline-flex items-center gap-2">
                  <input type="checkbox" checked={ovClosed} onChange={(e) => setOvClosed(e.target.checked)} />
                  Close to Sell on date(s)
                </label>
              </div>
              <div className="flex items-end">
                <Button type="submit" size="sm" loading={addOverrideMut.isPending} className="w-full">Add Override</Button>
              </div>
            </form>

            <div className="divide-y divide-slate-200 rounded-lg border border-slate-200">
              {(plansQ.data?.find((p) => p.id === overrideModalPlan.id)?.overrides ?? []).map((ov) => (
                <div key={ov.id} className="flex items-center justify-between gap-3 p-3 text-sm">
                  <div>
                    <span className="font-medium text-slate-900">{ov.room_type?.name ?? `RoomType #${ov.room_type_id}`}</span>
                    {' · '}
                    <Badge tone="brand">{ov.override_type}</Badge>
                    {' · '}
                    <span>{ov.start_date} to {ov.end_date}</span>
                    {ov.label && <span className="text-slate-500"> ({ov.label})</span>}
                    <div className="mt-0.5 text-xs text-slate-600">
                      {ov.nightly_rate !== null ? `Rate: ${formatCurrency(ov.nightly_rate, overrideModalPlan.currency_code)}` : 'Standard rate'}
                      {ov.min_stay_nights && ` · Min Stay: ${ov.min_stay_nights}n`}
                      {ov.closed_to_sell && ' · CLOSED TO SELL'}
                    </div>
                  </div>
                  <Button
                    size="sm"
                    variant="danger"
                    onClick={() => delOverrideMut.mutate({ planId: overrideModalPlan.id, ovId: ov.id })}
                  >
                    <Trash2 className="h-3.5 w-3.5" />
                  </Button>
                </div>
              ))}
            </div>
          </div>
        )}
      </Modal>
    </>
  )
}

/* ========================================================================
 * 4. AVAILABILITY TIMELINE / MATRIX PAGE (/admin/availability)
 * ======================================================================== */
export function AvailabilityAdminPage() {
  const [startDate, setStartDate] = useState(() => addDaysIso(0))
  const [endDate, setEndDate] = useState(() => addDaysIso(14))

  const q = useQuery({
    queryKey: ['admin', 'availability', startDate, endDate],
    queryFn: async () => (await api.get<{
      data: {
        start_date: string
        end_date: string
        dates: string[]
        matrix: Array<{
          room_type: { id: number; name: string; slug: string; code: string | null; is_active: boolean }
          total_physical_rooms: number
          minimum_available: number
          nights: Record<string, { total_sellable: number; reserved_units: number; held_units: number; available_units: number }>
        }>
      }
    }>('/admin/availability', { params: { start_date: startDate, end_date: endDate } })).data.data,
  })

  return (
    <>
      <PageHeader
        title="Room Availability & Inventory Matrix"
        description="Real-time server-side inventory per night (accounting for physical rooms, out-of-service dates, confirmed reservations, and active holds)."
      />
      <Card className="mb-6 p-4">
        <div className="flex flex-wrap items-end gap-4">
          <FormField id="av-start" label="From Date (Inclusive)">
            <Input id="av-start" type="date" value={startDate} onChange={(e) => setStartDate(e.target.value)} />
          </FormField>
          <FormField id="av-end" label="To Date (Exclusive)">
            <Input id="av-end" type="date" min={startDate} value={endDate} onChange={(e) => setEndDate(e.target.value)} />
          </FormField>
          <Button variant="secondary" onClick={() => q.refetch()} loading={q.isFetching}>
            <RefreshCw className="h-4 w-4" /> Refresh Matrix
          </Button>
        </div>
      </Card>

      {q.isLoading ? <Spinner /> : q.isError ? <ErrorState message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : q.data && (
        <Card className="overflow-x-auto">
          {q.data.matrix.length === 0 ? (
            <EmptyState icon={CalendarRange} title="No room types configured" description="Configure room types and physical rooms first." />
          ) : (
            <table className="min-w-full divide-y divide-slate-200 text-xs">
              <thead className="bg-slate-50">
                <tr>
                  <th className="px-4 py-3 text-left font-semibold text-slate-700">Room Type</th>
                  <th className="px-3 py-3 text-center font-semibold text-slate-700">Total Rooms</th>
                  {q.data.dates.map((d) => (
                    <th key={d} className="px-2.5 py-3 text-center font-semibold text-slate-700">{d.slice(5)}</th>
                  ))}
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 bg-white">
                {q.data.matrix.map((row) => (
                  <tr key={row.room_type.id}>
                    <td className="whitespace-nowrap px-4 py-3 font-medium text-slate-900">
                      {row.room_type.name}
                    </td>
                    <td className="px-3 py-3 text-center text-slate-600">{row.total_physical_rooms}</td>
                    {q.data.dates.map((d) => {
                      const cell = row.nights[d]
                      const avail = cell?.available_units ?? 0
                      return (
                        <td
                          key={d}
                          className={`px-2 py-2.5 text-center font-semibold ${
                            avail === 0 ? 'bg-rose-50 text-rose-700' : avail <= 2 ? 'bg-amber-50 text-amber-800' : 'text-emerald-700'
                          }`}
                          title={`Sellable: ${cell?.total_sellable ?? 0} | Reserved: ${cell?.reserved_units ?? 0} | Held: ${cell?.held_units ?? 0}`}
                        >
                          <div>{avail}</div>
                          {cell && (cell.reserved_units > 0 || cell.held_units > 0) && (
                            <div className="text-[10px] font-normal text-slate-500">
                              {cell.reserved_units}R{cell.held_units > 0 ? `/${cell.held_units}H` : ''}
                            </div>
                          )}
                        </td>
                      )
                    })}
                  </tr>
                ))}
              </tbody>
            </table>
          )}
        </Card>
      )}
    </>
  )
}

/* ========================================================================
 * 5. RESERVATIONS & FRONT-DESK WORKSPACE (/admin/reservations & /admin/front-desk)
 * ======================================================================== */
export function ReservationsAdminPage({ frontDeskMode = false }: { frontDeskMode?: boolean }) {
  const { can } = useAuth()
  const qc = useQueryClient()
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [paymentFilter, setPaymentFilter] = useState('')
  const [selectedId, setSelectedId] = useState<number | null>(null)
  const [createOpen, setCreateOpen] = useState(false)
  const [msg, setMsg] = useState<{ t: 'success' | 'error'; m: string } | null>(null)

  // New reservation form state
  const [newRoomTypeId, setNewRoomTypeId] = useState<number>(0)
  const [newRatePlanId, setNewRatePlanId] = useState<number>(0)
  const [newCheckIn, setNewCheckIn] = useState(() => addDaysIso(0))
  const [newCheckOut, setNewCheckOut] = useState(() => addDaysIso(1))
  const [newAdults, setNewAdults] = useState(1)
  const [newChildren, setNewChildren] = useState(0)
  const [newRoomsCount, setNewRoomsCount] = useState(1)
  const [newSource, setNewSource] = useState('walk_in')
  const [newGuestName, setNewGuestName] = useState('')
  const [newGuestEmail, setNewGuestEmail] = useState('')
  const [newGuestPhone, setNewGuestPhone] = useState('')
  const [newSpecialRequests, setNewSpecialRequests] = useState('')

  // Detail modal action states
  const [assignRoomId, setAssignRoomId] = useState<string>('')
  const [cancelReason, setCancelReason] = useState('')
  const [noShowReason, setNoShowReason] = useState('')
  const [payAmount, setPayAmount] = useState('')
  const [payMethod, setPayMethod] = useState('cash')
  const [payRef, setPayRef] = useState('')
  const [refundAmount, setRefundAmount] = useState('')
  const [refundReason, setRefundReason] = useState('')

  const fdQuery = useQuery({
    queryKey: ['admin', 'front-desk'],
    enabled: frontDeskMode,
    queryFn: async () => (await api.get<{
      data: {
        date: string
        arrivals: ReservationRecord[]
        departures: ReservationRecord[]
        in_house: ReservationRecord[]
      }
    }>('/admin/front-desk')).data.data,
  })

  const listQuery = useQuery({
    queryKey: ['admin', 'reservations', page, search, statusFilter, paymentFilter],
    queryFn: async () => (await api.get<Paginated<ReservationRecord>>('/admin/reservations', {
      params: {
        page,
        search: search || undefined,
        status: statusFilter || undefined,
        payment_status: paymentFilter || undefined,
      },
    })).data,
  })

  const detailQuery = useQuery({
    queryKey: ['admin', 'reservation', selectedId],
    enabled: !!selectedId,
    queryFn: async () => (await api.get<{ data: ReservationRecord }>(`/admin/reservations/${selectedId}`)).data.data,
  })

  const typesQ = useQuery({
    queryKey: ['admin', 'room-types'],
    queryFn: async () => (await api.get<{ data: RoomTypeRecord[] }>('/admin/room-types')).data.data,
  })
  const plansQ = useQuery({
    queryKey: ['admin', 'rate-plans'],
    queryFn: async () => (await api.get<{ data: RatePlanRecord[] }>('/admin/rate-plans')).data.data,
  })

  const refreshAll = () => {
    qc.invalidateQueries({ queryKey: ['admin', 'reservations'] })
    qc.invalidateQueries({ queryKey: ['admin', 'front-desk'] })
    if (selectedId) qc.invalidateQueries({ queryKey: ['admin', 'reservation', selectedId] })
  }

  const createResMut = useMutation({
    mutationFn: async () => (await api.post<{ message: string; data: ReservationRecord }>('/admin/reservations', {
      room_type_id: newRoomTypeId,
      rate_plan_id: newRatePlanId,
      check_in_date: newCheckIn,
      check_out_date: newCheckOut,
      adults: newAdults,
      children: newChildren,
      rooms_count: newRoomsCount,
      source: newSource,
      guest_name: newGuestName,
      guest_email: newGuestEmail,
      guest_phone: newGuestPhone,
      special_requests: newSpecialRequests || undefined,
    })).data,
    onSuccess: (res) => {
      refreshAll()
      setCreateOpen(false)
      setSelectedId(res.data.id)
      setMsg({ t: 'success', m: res.message })
    },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  const assignRoomMut = useMutation({
    mutationFn: async () => (await api.post(`/admin/reservations/${selectedId}/assign-room`, {
      assigned_room_id: assignRoomId ? Number(assignRoomId) : null,
    })).data,
    onSuccess: () => { refreshAll(); setMsg({ t: 'success', m: 'Room assignment updated.' }) },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  const checkInMut = useMutation({
    mutationFn: async (roomId?: number | null) => (await api.post(`/admin/reservations/${selectedId}/check-in`, {
      assigned_room_id: roomId || undefined,
    })).data,
    onSuccess: () => { refreshAll(); setMsg({ t: 'success', m: 'Guest checked in.' }) },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  const checkOutMut = useMutation({
    mutationFn: async () => (await api.post(`/admin/reservations/${selectedId}/check-out`, {})).data,
    onSuccess: () => { refreshAll(); setMsg({ t: 'success', m: 'Guest checked out.' }) },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  const cancelMut = useMutation({
    mutationFn: async () => (await api.post(`/admin/reservations/${selectedId}/cancel`, { reason: cancelReason })).data,
    onSuccess: () => { refreshAll(); setCancelReason(''); setMsg({ t: 'success', m: 'Reservation cancelled and inventory released.' }) },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  const noShowMut = useMutation({
    mutationFn: async () => (await api.post(`/admin/reservations/${selectedId}/no-show`, { reason: noShowReason })).data,
    onSuccess: () => { refreshAll(); setNoShowReason(''); setMsg({ t: 'success', m: 'Reservation marked as no-show.' }) },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  const payMut = useMutation({
    mutationFn: async () => (await api.post(`/admin/reservations/${selectedId}/payments`, {
      amount: Number(payAmount),
      method: payMethod,
      provider_reference: payRef || undefined,
    })).data,
    onSuccess: () => { refreshAll(); setPayAmount(''); setPayRef(''); setMsg({ t: 'success', m: 'Manual payment recorded.' }) },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  const refundReqMut = useMutation({
    mutationFn: async () => (await api.post(`/admin/reservations/${selectedId}/refunds`, {
      amount: Number(refundAmount),
      reason: refundReason,
    })).data,
    onSuccess: () => { refreshAll(); setRefundAmount(''); setRefundReason(''); setMsg({ t: 'success', m: 'Refund request submitted for finance approval.' }) },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  const detail = detailQuery.data

  return (
    <>
      <PageHeader
        title={frontDeskMode ? 'Front Desk Operations' : 'Reservations Management'}
        description={frontDeskMode
          ? "Today's arrivals, in-house guests, departures, room assignments, and walk-in bookings."
          : 'Search, filter, create, amend, assign rooms, and manage all guest reservations.'}
        actions={can('reservations.create') && (
          <Button
            onClick={() => {
              setMsg(null)
              setNewRoomTypeId(typesQ.data?.[0]?.id ?? 0)
              setNewRatePlanId(plansQ.data?.[0]?.id ?? 0)
              setNewCheckIn(addDaysIso(0))
              setNewCheckOut(addDaysIso(2))
              setCreateOpen(true)
            }}
          >
            <Plus className="h-4 w-4" /> New Walk-In / Staff Reservation
          </Button>
        )}
      />

      {msg && <div className="mb-4"><Alert variant={msg.t}>{msg.m}</Alert></div>}

      {frontDeskMode && fdQuery.data && (
        <div className="mb-6 grid gap-4 lg:grid-cols-3">
          <Card className="p-5">
            <h3 className="text-sm font-bold text-slate-900">Today&apos;s Arrivals ({fdQuery.data.arrivals.length})</h3>
            <div className="mt-3 divide-y divide-slate-100 text-xs">
              {fdQuery.data.arrivals.length === 0 ? <p className="py-2 text-slate-400">No arrivals scheduled for today.</p> : (
                fdQuery.data.arrivals.map((r) => (
                  <div key={r.id} className="flex items-center justify-between py-2">
                    <div>
                      <p className="font-semibold text-slate-900">{r.guest_name}</p>
                      <p className="text-slate-500">{r.reference} · {r.room_type?.name}</p>
                    </div>
                    <Button size="sm" variant="secondary" onClick={() => setSelectedId(r.id)}>Manage</Button>
                  </div>
                ))
              )}
            </div>
          </Card>

          <Card className="p-5">
            <h3 className="text-sm font-bold text-slate-900">In-House Guests ({fdQuery.data.in_house.length})</h3>
            <div className="mt-3 divide-y divide-slate-100 text-xs">
              {fdQuery.data.in_house.length === 0 ? <p className="py-2 text-slate-400">No guests currently checked in.</p> : (
                fdQuery.data.in_house.map((r) => (
                  <div key={r.id} className="flex items-center justify-between py-2">
                    <div>
                      <p className="font-semibold text-slate-900">Room {r.assigned_room?.room_number ?? 'Unassigned'} — {r.guest_name}</p>
                      <p className="text-slate-500">Out: {r.check_out_date} · {r.reference}</p>
                    </div>
                    <Button size="sm" variant="secondary" onClick={() => setSelectedId(r.id)}>Manage</Button>
                  </div>
                ))
              )}
            </div>
          </Card>

          <Card className="p-5">
            <h3 className="text-sm font-bold text-slate-900">Today&apos;s Departures ({fdQuery.data.departures.length})</h3>
            <div className="mt-3 divide-y divide-slate-100 text-xs">
              {fdQuery.data.departures.length === 0 ? <p className="py-2 text-slate-400">No departures scheduled for today.</p> : (
                fdQuery.data.departures.map((r) => (
                  <div key={r.id} className="flex items-center justify-between py-2">
                    <div>
                      <p className="font-semibold text-slate-900">{r.guest_name}</p>
                      <p className="text-slate-500">Room {r.assigned_room?.room_number ?? '—'} · {r.status}</p>
                    </div>
                    <Button size="sm" variant="secondary" onClick={() => setSelectedId(r.id)}>Manage</Button>
                  </div>
                ))
              )}
            </div>
          </Card>
        </div>
      )}

      {/* Search & Filters */}
      <Card className="mb-4 p-4">
        <div className="grid gap-3 sm:grid-cols-4">
          <div className="relative sm:col-span-2">
            <Search className="pointer-events-none absolute left-3 top-2.5 h-4 w-4 text-slate-400" />
            <Input
              className="pl-9"
              placeholder="Search by reference (MCH-...), guest name, email, or phone…"
              value={search}
              onChange={(e) => { setSearch(e.target.value); setPage(1) }}
            />
          </div>
          <Select value={statusFilter} onChange={(e) => { setStatusFilter(e.target.value); setPage(1) }} aria-label="Filter by Status">
            <option value="">All Reservation Statuses</option>
            <option value="confirmed">Confirmed</option>
            <option value="pending_payment">Pending Payment</option>
            <option value="checked_in">Checked In</option>
            <option value="checked_out">Checked Out</option>
            <option value="cancelled">Cancelled</option>
            <option value="no_show">No-Show</option>
          </Select>
          <Select value={paymentFilter} onChange={(e) => { setPaymentFilter(e.target.value); setPage(1) }} aria-label="Filter by Payment Status">
            <option value="">All Payment Statuses</option>
            <option value="unpaid">Unpaid</option>
            <option value="partially_paid">Partially Paid</option>
            <option value="paid">Paid</option>
            <option value="partially_refunded">Partially Refunded</option>
            <option value="refunded">Refunded</option>
          </Select>
        </div>
      </Card>

      {listQuery.isLoading ? <Spinner /> : listQuery.isError ? <ErrorState message={errorMessage(listQuery.error)} onRetry={() => listQuery.refetch()} /> : listQuery.data && (
        <Card>
          <DataTable
            rows={listQuery.data.data}
            rowKey={(r) => r.id}
            page={listQuery.data.current_page}
            lastPage={listQuery.data.last_page}
            onPage={setPage}
            empty={<EmptyState icon={CalendarCheck} title="No reservations match your criteria" />}
            columns={[
              {
                key: 'ref',
                header: 'Reference & Source',
                cell: (r) => (
                  <div>
                    <div className="font-bold text-slate-900">{r.reference}</div>
                    <div className="text-xs uppercase text-slate-400">{r.source.replace('_', ' ')}</div>
                  </div>
                ),
              },
              {
                key: 'guest',
                header: 'Guest',
                cell: (r) => (
                  <div>
                    <div className="font-medium text-slate-900">{r.guest_name}</div>
                    <div className="text-xs text-slate-500">{r.guest_phone} · {r.guest_email}</div>
                  </div>
                ),
              },
              {
                key: 'dates',
                header: 'Stay Dates',
                cell: (r) => (
                  <div className="text-xs">
                    <div className="font-medium text-slate-800">{r.check_in_date} → {r.check_out_date}</div>
                    <div className="text-slate-500">{r.nights} night(s) · {r.rooms_count} room(s)</div>
                  </div>
                ),
              },
              {
                key: 'room',
                header: 'Room & Rate',
                cell: (r) => (
                  <div className="text-xs">
                    <div className="font-medium text-slate-900">{r.room_type?.name}</div>
                    <div className="text-slate-500">
                      {r.assigned_room ? `Assigned: #${r.assigned_room.room_number}` : 'Unassigned'} · {r.rate_plan?.code}
                    </div>
                  </div>
                ),
              },
              {
                key: 'status',
                header: 'Status',
                cell: (r) => (
                  <div className="flex flex-col gap-1">
                    <Badge tone={statusTone(r.status)}>{r.status.replace('_', ' ')}</Badge>
                    <Badge tone={statusTone(r.payment_status)}>{r.payment_status.replace('_', ' ')}</Badge>
                  </div>
                ),
              },
              {
                key: 'total',
                header: 'Total / Paid',
                cell: (r) => (
                  <div className="text-xs">
                    <div className="font-semibold text-slate-900">{formatCurrency(r.total_amount, r.currency_code)}</div>
                    <div className="text-slate-500">Paid: {formatCurrency(r.amount_paid, r.currency_code)}</div>
                  </div>
                ),
              },
              {
                key: 'actions',
                header: 'Actions',
                cell: (r) => (
                  <Button
                    size="sm"
                    variant="secondary"
                    onClick={() => {
                      setMsg(null)
                      setSelectedId(r.id)
                      setAssignRoomId(r.assigned_room_id ? String(r.assigned_room_id) : '')
                    }}
                  >
                    Open Workspace
                  </Button>
                ),
              },
            ]}
          />
        </Card>
      )}

      {/* Create Staff/Walk-in Reservation Modal */}
      <Modal open={createOpen} onOpenChange={setCreateOpen} title="Create Walk-In / Telephone Reservation" wide>
        <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); createResMut.mutate() }}>
          <div className="grid gap-4 sm:grid-cols-3">
            <FormField id="nr-rt" label="Room Type *">
              <Select id="nr-rt" value={newRoomTypeId} onChange={(e) => setNewRoomTypeId(Number(e.target.value))} required>
                <option value={0}>Select Room Type</option>
                {(typesQ.data ?? []).map((rt) => <option key={rt.id} value={rt.id}>{rt.name}</option>)}
              </Select>
            </FormField>
            <FormField id="nr-rp" label="Rate Plan *">
              <Select id="nr-rp" value={newRatePlanId} onChange={(e) => setNewRatePlanId(Number(e.target.value))} required>
                <option value={0}>Select Rate Plan</option>
                {(plansQ.data ?? []).map((rp) => <option key={rp.id} value={rp.id}>{rp.name} ({rp.code})</option>)}
              </Select>
            </FormField>
            <FormField id="nr-src" label="Booking Source">
              <Select id="nr-src" value={newSource} onChange={(e) => setNewSource(e.target.value)}>
                <option value="walk_in">Walk-In</option>
                <option value="phone">Telephone</option>
                <option value="staff">Staff / Reception</option>
                <option value="corporate">Corporate</option>
              </Select>
            </FormField>
          </div>

          <div className="grid gap-4 sm:grid-cols-5">
            <FormField id="nr-in" label="Check-In Date *">
              <Input id="nr-in" type="date" value={newCheckIn} onChange={(e) => setNewCheckIn(e.target.value)} required />
            </FormField>
            <FormField id="nr-out" label="Check-Out Date *">
              <Input id="nr-out" type="date" min={newCheckIn} value={newCheckOut} onChange={(e) => setNewCheckOut(e.target.value)} required />
            </FormField>
            <FormField id="nr-adults" label="Adults *">
              <Input id="nr-adults" type="number" min={1} max={20} value={newAdults} onChange={(e) => setNewAdults(Number(e.target.value))} required />
            </FormField>
            <FormField id="nr-children" label="Children">
              <Input id="nr-children" type="number" min={0} max={20} value={newChildren} onChange={(e) => setNewChildren(Number(e.target.value))} />
            </FormField>
            <FormField id="nr-rooms" label="Rooms *">
              <Input id="nr-rooms" type="number" min={1} max={10} value={newRoomsCount} onChange={(e) => setNewRoomsCount(Number(e.target.value))} required />
            </FormField>
          </div>

          <div className="grid gap-4 sm:grid-cols-3">
            <FormField id="nr-gname" label="Guest Full Name *">
              <Input id="nr-gname" value={newGuestName} onChange={(e) => setNewGuestName(e.target.value)} required />
            </FormField>
            <FormField id="nr-gemail" label="Guest Email *">
              <Input id="nr-gemail" type="email" value={newGuestEmail} onChange={(e) => setNewGuestEmail(e.target.value)} required />
            </FormField>
            <FormField id="nr-gphone" label="Guest Phone *">
              <Input id="nr-gphone" value={newGuestPhone} onChange={(e) => setNewGuestPhone(e.target.value)} required />
            </FormField>
          </div>

          <FormField id="nr-req" label="Special Requests">
            <Textarea id="nr-req" rows={2} value={newSpecialRequests} onChange={(e) => setNewSpecialRequests(e.target.value)} />
          </FormField>

          <div className="flex justify-end gap-2 border-t border-slate-100 pt-4">
            <Button type="button" variant="secondary" onClick={() => setCreateOpen(false)}>Cancel</Button>
            <Button type="submit" loading={createResMut.isPending}>Create Reservation</Button>
          </div>
        </form>
      </Modal>

      {/* Reservation Workspace Detail Modal */}
      <Modal open={!!selectedId} onOpenChange={(o) => !o && setSelectedId(null)} title={detail ? `Reservation ${detail.reference}` : 'Loading Reservation…'} wide>
        {detailQuery.isLoading || !detail ? <Spinner /> : (
          <div className="space-y-6 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg bg-slate-50 p-4">
              <div>
                <h3 className="text-lg font-bold text-slate-900">{detail.guest_name}</h3>
                <p className="text-xs text-slate-600">{detail.guest_email} · {detail.guest_phone}</p>
              </div>
              <div className="flex flex-wrap items-center gap-2">
                <Badge tone={statusTone(detail.status)}>Status: {detail.status.replace('_', ' ')}</Badge>
                <Badge tone={statusTone(detail.payment_status)}>Payment: {detail.payment_status.replace('_', ' ')}</Badge>
                <Button size="sm" variant="secondary" onClick={() => window.print()}>
                  <Printer className="h-3.5 w-3.5" /> Print Summary
                </Button>
              </div>
            </div>

            <dl className="grid gap-3 rounded-lg border border-slate-200 p-4 sm:grid-cols-4">
              <div><dt className="text-xs text-slate-500">Stay</dt><dd className="font-semibold">{detail.check_in_date} → {detail.check_out_date} ({detail.nights}n)</dd></div>
              <div><dt className="text-xs text-slate-500">Room Type & Rate</dt><dd className="font-semibold">{detail.room_type?.name} · {detail.rate_plan?.code}</dd></div>
              <div><dt className="text-xs text-slate-500">Total Quoted</dt><dd className="font-bold">{formatCurrency(detail.total_amount, detail.currency_code)}</dd></div>
              <div><dt className="text-xs text-slate-500">Balance Due</dt><dd className="font-bold text-brand-800">{formatCurrency(detail.balance_due_amount ?? 0, detail.currency_code)}</dd></div>
            </dl>

            {/* Physical Room Assignment & Front-Desk Check-in/Check-out */}
            <div className="grid gap-4 lg:grid-cols-2">
              {can('reservations.assign') && ['confirmed', 'pending_payment', 'checked_in'].includes(detail.status) && (
                <div className="rounded-lg border border-slate-200 p-4">
                  <h4 className="font-semibold text-slate-900">Physical Room Assignment</h4>
                  <p className="text-xs text-slate-500">Only sellable rooms without conflicting stays are listed.</p>
                  <div className="mt-3 flex gap-2">
                    <Select value={assignRoomId} onChange={(e) => setAssignRoomId(e.target.value)}>
                      <option value="">Unassigned</option>
                      {detail.assigned_room && (
                        <option value={detail.assigned_room.id}>
                          Room {detail.assigned_room.room_number} (Current)
                        </option>
                      )}
                      {(detail.assignable_rooms ?? [])
                        .filter((rm) => rm.id !== detail.assigned_room_id)
                        .map((rm) => (
                          <option key={rm.id} value={rm.id}>
                            Room {rm.room_number} ({rm.housekeeping_status})
                          </option>
                        ))}
                    </Select>
                    <Button size="sm" onClick={() => assignRoomMut.mutate()} loading={assignRoomMut.isPending}>
                      Save Room
                    </Button>
                  </div>
                </div>
              )}

              <div className="rounded-lg border border-slate-200 p-4">
                <h4 className="font-semibold text-slate-900">Front-Desk Lifecycle Actions</h4>
                <div className="mt-3 flex flex-wrap gap-2">
                  {can('frontdesk.checkin') && detail.status === 'confirmed' && (
                    <Button
                      size="sm"
                      onClick={() => checkInMut.mutate(assignRoomId ? Number(assignRoomId) : detail.assigned_room_id)}
                      loading={checkInMut.isPending}
                    >
                      <CheckCircle2 className="h-4 w-4" /> Check Guest In
                    </Button>
                  )}
                  {can('frontdesk.checkout') && detail.status === 'checked_in' && (
                    <Button size="sm" onClick={() => checkOutMut.mutate()} loading={checkOutMut.isPending}>
                      Check Guest Out
                    </Button>
                  )}
                </div>

                {can('reservations.cancel') && ['confirmed', 'pending_payment'].includes(detail.status) && (
                  <div className="mt-4 space-y-2 border-t border-slate-100 pt-3">
                    <div className="flex gap-2">
                      <Input placeholder="Cancellation reason…" value={cancelReason} onChange={(e) => setCancelReason(e.target.value)} />
                      <Button size="sm" variant="danger" disabled={cancelReason.length < 3} loading={cancelMut.isPending} onClick={() => cancelMut.mutate()}>
                        Cancel Stay
                      </Button>
                    </div>
                    <div className="flex gap-2">
                      <Input placeholder="No-show reason…" value={noShowReason} onChange={(e) => setNoShowReason(e.target.value)} />
                      <Button size="sm" variant="secondary" disabled={noShowReason.length < 3} loading={noShowMut.isPending} onClick={() => noShowMut.mutate()}>
                        Mark No-Show
                      </Button>
                    </div>
                  </div>
                )}
              </div>
            </div>

            {/* Payments & Refund Requests */}
            <div className="grid gap-4 lg:grid-cols-2">
              {(can('payments.record') || can('finance.record')) && (
                <form
                  className="space-y-3 rounded-lg border border-slate-200 p-4"
                  onSubmit={(e) => { e.preventDefault(); payMut.mutate() }}
                >
                  <h4 className="font-semibold text-slate-900">Record Manual Payment</h4>
                  <div className="grid gap-2 sm:grid-cols-3">
                    <Input type="number" min={1} step="0.01" placeholder="Amount (NGN)" value={payAmount} onChange={(e) => setPayAmount(e.target.value)} required />
                    <Select value={payMethod} onChange={(e) => setPayMethod(e.target.value)}>
                      <option value="cash">Cash</option>
                      <option value="pos">POS Terminal</option>
                      <option value="bank_transfer">Bank Transfer</option>
                    </Select>
                    <Input placeholder="POS / Bank Ref" value={payRef} onChange={(e) => setPayRef(e.target.value)} />
                  </div>
                  <Button type="submit" size="sm" loading={payMut.isPending}>Record Payment</Button>
                </form>
              )}

              {detail.amount_paid > 0 && (
                <form
                  className="space-y-3 rounded-lg border border-slate-200 p-4"
                  onSubmit={(e) => { e.preventDefault(); refundReqMut.mutate() }}
                >
                  <h4 className="font-semibold text-slate-900">Request Refund</h4>
                  <div className="grid gap-2 sm:grid-cols-2">
                    <Input type="number" min={1} step="0.01" placeholder="Refund Amount (NGN)" value={refundAmount} onChange={(e) => setRefundAmount(e.target.value)} required />
                    <Input placeholder="Reason for refund" value={refundReason} onChange={(e) => setRefundReason(e.target.value)} required />
                  </div>
                  <Button type="submit" size="sm" variant="secondary" loading={refundReqMut.isPending}>Submit Refund Request</Button>
                </form>
              )}
            </div>

            {/* Payment Ledger */}
            {detail.payments && detail.payments.length > 0 && (
              <div>
                <h4 className="mb-2 font-semibold text-slate-900">Payment Transactions</h4>
                <div className="divide-y divide-slate-100 rounded border border-slate-200 text-xs">
                  {detail.payments.map((tx) => (
                    <div key={tx.id} className="flex items-center justify-between p-2.5">
                      <div>
                        <span className="font-semibold">{tx.transaction_reference}</span> · <Badge tone={statusTone(tx.status)}>{tx.type}</Badge> · {tx.method}
                        {tx.provider_reference && <span className="text-slate-500"> (Ref: {tx.provider_reference})</span>}
                      </div>
                      <div className="font-bold">{formatCurrency(tx.amount, tx.currency_code)}</div>
                    </div>
                  ))}
                </div>
              </div>
            )}
          </div>
        )}
      </Modal>
    </>
  )
}

export function FrontDeskAdminPage() {
  return <ReservationsAdminPage frontDeskMode />
}

/* ========================================================================
 * 6. PAYMENT RECORDS & REFUND APPROVALS PAGE (/admin/payments)
 * ======================================================================== */
export function PaymentsAdminPage() {
  const { can } = useAuth()
  const qc = useQueryClient()
  const [tab, setTab] = useState<'transactions' | 'refunds'>('transactions')
  const [page, setPage] = useState(1)
  const [msg, setMsg] = useState<{ t: 'success' | 'error'; m: string } | null>(null)

  const txQuery = useQuery({
    queryKey: ['admin', 'payments', page],
    enabled: tab === 'transactions',
    queryFn: async () => (await api.get<Paginated<PaymentTransactionRecord>>('/admin/payments', { params: { page } })).data,
  })

  const rfdQuery = useQuery({
    queryKey: ['admin', 'refunds', page],
    enabled: tab === 'refunds',
    queryFn: async () => (await api.get<Paginated<PaymentRefundRecord>>('/admin/refunds', { params: { page } })).data,
  })

  const reverseMut = useMutation({
    mutationFn: async (txId: number) => (await api.post(`/admin/payments/${txId}/reverse`, { reason: 'Administrative reversal' })).data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'payments'] })
      setMsg({ t: 'success', m: 'Payment transaction reversed.' })
    },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  const reviewRefundMut = useMutation({
    mutationFn: async ({ id, decision }: { id: number; decision: 'approve' | 'reject' }) =>
      (await api.post(`/admin/refunds/${id}/review`, { decision })).data,
    onSuccess: (res) => {
      qc.invalidateQueries({ queryKey: ['admin', 'refunds'] })
      qc.invalidateQueries({ queryKey: ['admin', 'payments'] })
      setMsg({ t: 'success', m: res.message })
    },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  return (
    <>
      <PageHeader
        title="Payment Records & Refund Approvals"
        description="Append-only ledger of manual and online reservation payments, reversals, and refund approval workflows."
      />
      {msg && <div className="mb-4"><Alert variant={msg.t}>{msg.m}</Alert></div>}

      <div className="mb-4 flex gap-2">
        <Button variant={tab === 'transactions' ? 'primary' : 'secondary'} size="sm" onClick={() => { setTab('transactions'); setPage(1) }}>
          Payment Ledger
        </Button>
        <Button variant={tab === 'refunds' ? 'primary' : 'secondary'} size="sm" onClick={() => { setTab('refunds'); setPage(1) }}>
          Refund Requests & Approvals
        </Button>
      </div>

      {tab === 'transactions' ? (
        txQuery.isLoading ? <Spinner /> : txQuery.isError ? <ErrorState message={errorMessage(txQuery.error)} /> : txQuery.data && (
          <Card>
            <DataTable
              rows={txQuery.data.data}
              rowKey={(r) => r.id}
              page={txQuery.data.current_page}
              lastPage={txQuery.data.last_page}
              onPage={setPage}
              empty={<EmptyState icon={CreditCard} title="No payment transactions recorded yet" />}
              columns={[
                { key: 'ref', header: 'Transaction Ref', cell: (r) => <span className="font-mono text-xs font-semibold">{r.transaction_reference}</span> },
                { key: 'res', header: 'Reservation', cell: (r) => <span>{r.reservation?.reference} — {r.reservation?.guest_name}</span> },
                { key: 'type', header: 'Type & Method', cell: (r) => <span><Badge tone={statusTone(r.status)}>{r.type}</Badge> · {r.method}</span> },
                { key: 'amt', header: 'Amount', cell: (r) => <span className="font-bold">{formatCurrency(r.amount, r.currency_code)}</span> },
                { key: 'by', header: 'Recorded By', cell: (r) => r.recorder?.name ?? (r.provider || 'System') },
                { key: 'date', header: 'Date', cell: (r) => formatDateTime(r.paid_at || r.created_at) },
                {
                  key: 'actions',
                  header: 'Actions',
                  cell: (r) => (can('finance.refund') || can('finance.approve')) && r.type === 'payment' && r.status === 'completed' ? (
                    <Button size="sm" variant="danger" onClick={() => reverseMut.mutate(r.id)} loading={reverseMut.isPending}>
                      Reverse
                    </Button>
                  ) : null,
                },
              ]}
            />
          </Card>
        )
      ) : (
        rfdQuery.isLoading ? <Spinner /> : rfdQuery.isError ? <ErrorState message={errorMessage(rfdQuery.error)} /> : rfdQuery.data && (
          <Card>
            <DataTable
              rows={rfdQuery.data.data}
              rowKey={(r) => r.id}
              page={rfdQuery.data.current_page}
              lastPage={rfdQuery.data.last_page}
              onPage={setPage}
              empty={<EmptyState icon={CreditCard} title="No refund requests" />}
              columns={[
                { key: 'ref', header: 'Refund Ref', cell: (r) => <span className="font-mono text-xs font-semibold">{r.refund_reference}</span> },
                { key: 'res', header: 'Reservation', cell: (r) => <span>{r.reservation?.reference} — {r.reservation?.guest_name}</span> },
                { key: 'amt', header: 'Amount', cell: (r) => <span className="font-bold">{formatCurrency(r.amount, r.currency_code)}</span> },
                { key: 'reason', header: 'Reason', cell: (r) => r.reason },
                { key: 'status', header: 'Status', cell: (r) => <Badge tone={statusTone(r.status)}>{r.status}</Badge> },
                {
                  key: 'actions',
                  header: 'Approval (Finance)',
                  cell: (r) => can('finance.refund') && r.status === 'pending' ? (
                    <div className="flex gap-2">
                      <Button size="sm" onClick={() => reviewRefundMut.mutate({ id: r.id, decision: 'approve' })} loading={reviewRefundMut.isPending}>
                        Approve & Process
                      </Button>
                      <Button size="sm" variant="danger" onClick={() => reviewRefundMut.mutate({ id: r.id, decision: 'reject' })} loading={reviewRefundMut.isPending}>
                        Reject
                      </Button>
                    </div>
                  ) : <span className="text-xs text-slate-500">{r.reviewer?.name ? `Reviewed by ${r.reviewer.name}` : '—'}</span>,
                },
              ]}
            />
          </Card>
        )
      )}
    </>
  )
}

/* ========================================================================
 * 7. GUEST DIRECTORY PAGE (/admin/guests)
 * ======================================================================== */
type GuestListItem = {
  id: number
  full_name: string
  email: string | null
  phone: string | null
  nationality: string | null
  id_document_type?: string | null
  id_document_number?: string | null
  internal_notes?: string | null
  is_vip: boolean
  reservations_count: number
}

export function GuestsAdminPage() {
  const { can } = useAuth()
  const [page, setPage] = useState(1)
  const [search, setSearch] = useState('')
  const canSensitive = can('guests.sensitive')

  const q = useQuery({
    queryKey: ['admin', 'guests', page, search],
    queryFn: async () => (await api.get<Paginated<GuestListItem>>('/admin/guests', { params: { page, search: search || undefined } })).data,
  })

  return (
    <>
      <PageHeader
        title="Guest Directory"
        description="Guest profiles created from online and front-desk reservations. Sensitive identification fields are restricted to authorized roles."
      />
      <Card className="mb-4 p-4">
        <Input
          placeholder="Search guest by name, email, or phone…"
          value={search}
          onChange={(e) => { setSearch(e.target.value); setPage(1) }}
        />
      </Card>
      {q.isLoading ? <Spinner /> : q.isError ? <ErrorState message={errorMessage(q.error)} /> : q.data && (
        <Card>
          <DataTable
            rows={q.data.data}
            rowKey={(r) => r.id}
            page={q.data.current_page}
            lastPage={q.data.last_page}
            onPage={setPage}
            empty={<EmptyState icon={UserSquare2} title="No guest profiles yet" />}
            columns={[
              { key: 'name', header: 'Guest Name', cell: (r) => <span className="font-medium text-slate-900">{r.full_name} {r.is_vip && <Badge tone="brand">VIP</Badge>}</span> },
              { key: 'contact', header: 'Contact', cell: (r) => <span className="text-xs text-slate-600">{r.email || '—'} · {r.phone || '—'}</span> },
              { key: 'stays', header: 'Reservations', cell: (r) => <Badge>{r.reservations_count} stay(s)</Badge> },
              ...(canSensitive ? [{
                key: 'sensitive',
                header: 'Sensitive ID & Notes',
                cell: (r: GuestListItem) => (
                  <span className="text-xs text-slate-600">
                    {r.id_document_type ? `${r.id_document_type}: ${r.id_document_number}` : 'No ID on file'}
                    {r.internal_notes ? ` · ${r.internal_notes}` : ''}
                  </span>
                ),
              }] : []),
            ]}
          />
        </Card>
      )}
    </>
  )
}

/* ========================================================================
 * 8. RESERVATION SETTINGS PAGE (/admin/reservation-settings)
 * ======================================================================== */
export function ReservationSettingsAdminPage() {
  const { can } = useAuth()
  const qc = useQueryClient()
  const [msg, setMsg] = useState<{ t: 'success' | 'error'; m: string } | null>(null)

  const q = useQuery({
    queryKey: ['admin', 'settings'],
    queryFn: async () => (await api.get<{
      data: { booking: Record<string, unknown>; hotel: Record<string, unknown> }
      integrations?: { payments?: { paystack_configured: boolean; flutterwave_configured: boolean } }
    }>('/admin/settings')).data,
  })

  const [form, setForm] = useState<Record<string, unknown> | null>(null)
  const booking = form ?? q.data?.data.booking ?? {}

  const saveMut = useMutation({
    mutationFn: async () => (await api.put('/admin/settings', { booking })).data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['admin', 'settings'] })
      qc.invalidateQueries({ queryKey: ['public', 'bootstrap'] })
      setMsg({ t: 'success', m: 'Reservation settings saved.' })
    },
    onError: (e) => setMsg({ t: 'error', m: errorMessage(e) }),
  })

  if (q.isLoading) return <Spinner />
  if (q.isError) return <ErrorState message={errorMessage(q.error)} />

  const payIntegrations = q.data?.integrations?.payments

  return (
    <>
      <PageHeader
        title="Reservation & Booking Settings"
        description="Configure check-in/out times, booking hold expiration, taxes, service charges, default cancellation notice, and online payment gateway mode."
      />
      {msg && <div className="mb-4"><Alert variant={msg.t}>{msg.m}</Alert></div>}

      <div className="grid gap-6 lg:grid-cols-3">
        <Card className="p-6 lg:col-span-2">
          <form className="space-y-4" onSubmit={(e) => { e.preventDefault(); saveMut.mutate() }}>
            <div className="flex flex-wrap gap-6">
              <label className="inline-flex items-center gap-2 text-sm font-medium">
                <input
                  type="checkbox"
                  checked={!!booking.online_booking_enabled}
                  onChange={(e) => setForm({ ...booking, online_booking_enabled: e.target.checked })}
                />
                Enable Public Online Booking
              </label>
              <label className="inline-flex items-center gap-2 text-sm font-medium">
                <input
                  type="checkbox"
                  checked={!!booking.require_payment_for_online_booking}
                  onChange={(e) => setForm({ ...booking, require_payment_for_online_booking: e.target.checked })}
                />
                Require Online Payment Before Confirmation
              </label>
            </div>

            <div className="grid gap-4 sm:grid-cols-3">
              <FormField id="bs-in" label="Standard Check-In Time">
                <Input id="bs-in" value={String(booking.check_in_time ?? '14:00')} onChange={(e) => setForm({ ...booking, check_in_time: e.target.value })} />
              </FormField>
              <FormField id="bs-out" label="Standard Check-Out Time">
                <Input id="bs-out" value={String(booking.check_out_time ?? '12:00')} onChange={(e) => setForm({ ...booking, check_out_time: e.target.value })} />
              </FormField>
              <FormField id="bs-ttl" label="Booking Hold Expiry (Minutes)">
                <Input id="bs-ttl" type="number" min={5} max={60} value={Number(booking.hold_ttl_minutes ?? 15)} onChange={(e) => setForm({ ...booking, hold_ttl_minutes: Number(e.target.value) })} />
              </FormField>
            </div>

            <div className="grid gap-4 sm:grid-cols-4">
              <FormField id="bs-max-rm" label="Max Rooms / Booking">
                <Input id="bs-max-rm" type="number" min={1} max={10} value={Number(booking.max_rooms_per_booking ?? 5)} onChange={(e) => setForm({ ...booking, max_rooms_per_booking: Number(e.target.value) })} />
              </FormField>
              <FormField id="bs-max-nights" label="Max Stay Nights">
                <Input id="bs-max-nights" type="number" min={1} max={365} value={Number(booking.max_stay_nights ?? 30)} onChange={(e) => setForm({ ...booking, max_stay_nights: Number(e.target.value) })} />
              </FormField>
              <FormField id="bs-tax" label="Tax Percentage (%)">
                <Input id="bs-tax" type="number" min={0} max={50} step="0.01" value={String(booking.tax_percentage ?? '0.00')} onChange={(e) => setForm({ ...booking, tax_percentage: e.target.value })} />
              </FormField>
              <FormField id="bs-fee" label="Service Charge (%)">
                <Input id="bs-fee" type="number" min={0} max={50} step="0.01" value={String(booking.service_charge_percentage ?? '0.00')} onChange={(e) => setForm({ ...booking, service_charge_percentage: e.target.value })} />
              </FormField>
            </div>

            <FormField id="bs-provider" label="Online Payment Provider">
              <Select
                id="bs-provider"
                value={String(booking.online_payment_provider ?? 'none')}
                onChange={(e) => setForm({ ...booking, online_payment_provider: e.target.value })}
              >
                <option value="none">None (Manual / Pay at Hotel / Bank Transfer)</option>
                <option value="paystack">Paystack</option>
                <option value="flutterwave">Flutterwave</option>
              </Select>
            </FormField>

            <FormField id="bs-canc" label="Default Cancellation Policy Notice">
              <Textarea
                id="bs-canc"
                rows={3}
                value={String(booking.default_cancellation_policy ?? '')}
                onChange={(e) => setForm({ ...booking, default_cancellation_policy: e.target.value || null })}
                placeholder="Enter the hotel's official cancellation policy text…"
              />
            </FormField>

            {(can('reservations.settings') || can('settings.edit')) && (
              <Button type="submit" loading={saveMut.isPending}>
                <SlidersHorizontal className="h-4 w-4" /> Save Reservation Settings
              </Button>
            )}
          </form>
        </Card>

        <Card className="p-6">
          <CardHeader title="Payment Gateway Status" description="Credentials are read from server .env only and never exposed to browsers." />
          <dl className="divide-y divide-slate-100 text-sm">
            <div className="flex items-center justify-between py-3">
              <dt className="text-slate-600">Paystack Keys (`.env`)</dt>
              <dd>
                <Badge tone={payIntegrations?.paystack_configured ? 'success' : 'warning'}>
                  {payIntegrations?.paystack_configured ? 'Configured' : 'Not Configured'}
                </Badge>
              </dd>
            </div>
            <div className="flex items-center justify-between py-3">
              <dt className="text-slate-600">Flutterwave Keys (`.env`)</dt>
              <dd>
                <Badge tone={payIntegrations?.flutterwave_configured ? 'success' : 'warning'}>
                  {payIntegrations?.flutterwave_configured ? 'Configured' : 'Not Configured'}
                </Badge>
              </dd>
            </div>
          </dl>
          <p className="mt-4 text-xs text-slate-500">
            Webhook Endpoint: <code>/api/v1/public/webhooks/payments/&#123;paystack|flutterwave&#125;</code>
          </p>
        </Card>
      </div>
    </>
  )
}
