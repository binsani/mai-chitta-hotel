import { useState } from 'react'
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query'
import {
  Boxes, CheckCircle2, Plus, Printer, Receipt, ShoppingCart, Trash2, UtensilsCrossed,
} from 'lucide-react'
import { useAuth } from '@/auth/AuthProvider'
import { api, errorMessage } from '@/lib/api'
import { formatCurrency } from '@/lib/cms'
import {
  Alert, Badge, Button, Card, CardHeader, DataTable, EmptyState, ErrorState,
  FormField, Input, Modal, PageHeader, Select, Spinner, Textarea,
} from '@/components/ui'

function statusTone(status: string): 'neutral' | 'brand' | 'success' | 'warning' | 'danger' {
  switch (status) {
    case 'served':
    case 'delivered':
    case 'paid':
    case 'charged_to_room':
    case 'approved':
    case 'received':
    case 'active':
    case 'completed':
      return 'success'
    case 'submitted':
    case 'preparing':
    case 'ready':
    case 'out_for_delivery':
    case 'partially_received':
    case 'partially_paid':
      return 'brand'
    case 'draft':
    case 'pending_approval':
    case 'unpaid':
      return 'warning'
    case 'voided':
    case 'cancelled':
    case 'rejected':
    case 'suspended':
      return 'danger'
    default:
      return 'neutral'
  }
}

/* ========================================================================
 * TYPES
 * ======================================================================== */
export type PosOutletRecord = {
  id: number
  code: string
  name: string
  type: 'restaurant' | 'bar' | 'room_service' | 'cafe' | 'pool_bar'
  default_storage_location_id: number | null
  tax_percentage: number | null
  service_charge_percentage: number | null
  supports_room_service: boolean
  supports_room_charge: boolean
  accepts_orders: boolean
  is_active: boolean
}

export type MenuCategoryRecord = {
  id: number
  pos_outlet_id: number | null
  code: string
  name: string
  description: string | null
  is_active: boolean
  sort_order: number
}

export type MenuItemVariantRecord = {
  id: number
  menu_item_id: number
  code: string
  name: string
  price_minor: number
  price: number
  recipe_multiplier: number
  is_sold_out: boolean
  is_active: boolean
}

export type MenuItemModifierRecord = {
  id: number
  menu_item_id: number
  name: string
  price_minor: number
  price: number
  is_active: boolean
}

export type MenuItemRecord = {
  id: number
  sku: string
  pos_outlet_id: number | null
  menu_category_id: number
  name: string
  description: string | null
  currency_code: string
  price_minor: number
  price: number
  is_room_service_eligible: boolean
  is_available: boolean
  is_sold_out: boolean
  is_active: boolean
  stock_tracking_policy: 'none' | 'direct_item' | 'recipe'
  direct_inventory_item_id: number | null
  direct_deduction_quantity: number
  category?: MenuCategoryRecord
  variants?: MenuItemVariantRecord[]
  modifiers?: MenuItemModifierRecord[]
}

export type InHouseStayRecord = {
  reservation_id: number
  reference: string
  guest_name: string
  room_id: number
  room_number: string
  room_type: string
  check_in_date: string
  check_out_date: string
  guest_folio_id: number | null
  folio_reference: string | null
  folio_status: string
  folio_balance_due_amount: string
}

export type PosOrderItemRecord = {
  id: number
  menu_item_id: number
  item_name_snapshot: string
  variant_name_snapshot: string | null
  quantity: number
  unit_price: number
  line_subtotal: number
  prep_status: string
  special_instructions: string | null
}

export type PosPaymentRecord = {
  id: number
  payment_reference: string
  type: 'payment' | 'refund' | 'reversal'
  method: string
  amount: number
  amount_minor: number
  status: string
  receipt_number: string | null
  paid_at: string
}

export type PosOrderRecord = {
  id: number
  order_reference: string
  pos_outlet_id: number
  order_type: 'dine_in' | 'bar' | 'takeaway' | 'room_service'
  table_or_location: string | null
  covers: number
  reservation_id: number | null
  guest_folio_id: number | null
  room_id: number | null
  guest_name_snapshot: string | null
  status: string
  payment_status: string
  settlement_method: string | null
  currency_code: string
  subtotal_minor: number
  discount_minor: number
  tax_minor: number
  service_charge_minor: number
  total_minor: number
  total_amount: number
  amount_paid: number
  balance_due_amount: number
  stock_consumed: boolean
  void_reason: string | null
  created_at: string
  outlet?: PosOutletRecord
  items?: PosOrderItemRecord[]
  payments?: PosPaymentRecord[]
}

export type InventoryCategoryRecord = {
  id: number
  code: string
  name: string
  description: string | null
  is_active: boolean
}

export type StorageLocationRecord = {
  id: number
  code: string
  name: string
  location_type: string
  is_default: boolean
  is_active: boolean
}

export type InventoryItemRecord = {
  id: number
  sku: string
  inventory_category_id: number | null
  name: string
  base_unit: string
  purchase_unit: string
  purchase_to_base_factor: number
  current_stock: number
  reorder_level: number
  currency_code: string
  unit_cost?: number | null
  unit_cost_minor?: number | null
  stock_valuation_amount?: string | null
  is_low_stock?: boolean
  is_active: boolean
  category?: InventoryCategoryRecord
  balances?: { id: number; storage_location_id: number; quantity: number; location?: StorageLocationRecord }[]
}

export type StockMovementRecord = {
  id: number
  movement_reference: string
  movement_type: string
  quantity_delta: number
  balance_before: number
  balance_after: number
  unit: string
  unit_cost_minor?: number | null
  total_cost_minor?: number | null
  reason: string | null
  occurred_at: string
  item?: { id: number; sku: string; name: string }
  location?: { id: number; code: string; name: string }
  actor?: { id: number; name: string }
}

export type MenuItemRecipeRecord = {
  id: number
  menu_item_id: number
  inventory_item_id: number
  quantity_required: number
  unit: string
  conversion_to_base: number
  waste_percentage: number
  menu_item?: { id: number; sku: string; name: string }
  inventory_item?: { id: number; sku: string; name: string; base_unit: string }
}

export type SupplierRecord = {
  id: number
  supplier_code: string
  name: string
  contact_person: string | null
  phone: string | null
  email: string | null
  status: string
  tax_id?: string | null
  bank_name?: string | null
  bank_account_number?: string | null
  payment_terms: string | null
}

export type PurchaseRequisitionRecord = {
  id: number
  requisition_reference: string
  priority: string
  status: string
  reason: string | null
  created_at: string
  requester?: { id: number; name: string }
  items?: { id: number; inventory_item_id: number; quantity_requested: number; unit: string; inventory_item?: { name: string; sku: string } }[]
}

export type PurchaseOrderRecord = {
  id: number
  po_reference: string
  supplier_id: number
  storage_location_id: number
  status: string
  currency_code: string
  total_amount?: number | null
  expected_delivery_date: string | null
  created_at: string
  supplier?: { id: number; name: string; supplier_code: string }
  location?: { id: number; name: string }
  items?: {
    id: number
    inventory_item_id: number
    unit: string
    quantity_ordered: number
    quantity_received: number
    unit_cost?: number | null
    inventory_item?: { id: number; name: string; sku: string }
  }[]
}

/* ========================================================================
 * 1. RESTAURANT, BAR & ROOM SERVICE POS PAGE (/admin/pos)
 * ======================================================================== */
export function PosAdminPage() {
  const { can } = useAuth()
  const qc = useQueryClient()
  const [tab, setTab] = useState<'terminal' | 'orders' | 'room_service' | 'menu' | 'reports'>('terminal')
  const [banner, setBanner] = useState<{ t: 'success' | 'error'; m: string } | null>(null)

  const outletsQ = useQuery({
    queryKey: ['pos', 'outlets'],
    queryFn: async () => (await api.get<{ data: PosOutletRecord[] }>('/admin/pos/outlets')).data.data,
  })
  const categoriesQ = useQuery({
    queryKey: ['pos', 'menu-categories'],
    queryFn: async () => (await api.get<{ data: MenuCategoryRecord[] }>('/admin/pos/menu-categories')).data.data,
  })
  const menuItemsQ = useQuery({
    queryKey: ['pos', 'menu-items'],
    queryFn: async () => (await api.get<{ data: MenuItemRecord[] }>('/admin/pos/menu-items')).data.data,
  })
  const staysQ = useQuery({
    queryKey: ['pos', 'in-house-stays'],
    queryFn: async () => (await api.get<{ data: InHouseStayRecord[] }>('/admin/pos/in-house-stays')).data.data,
  })
  const ordersQ = useQuery({
    queryKey: ['pos', 'orders'],
    queryFn: async () => (await api.get<{ data: PosOrderRecord[] }>('/admin/pos/orders')).data.data,
  })
  const reportsQ = useQuery({
    queryKey: ['pos', 'reports'],
    queryFn: async () => (await api.get<{ data: Record<string, unknown> }>('/admin/pos/reports/summary')).data.data,
    enabled: tab === 'reports',
  })

  // POS Cart state
  const [selectedOutletId, setSelectedOutletId] = useState<string>('')
  const [orderType, setOrderType] = useState<'dine_in' | 'bar' | 'takeaway' | 'room_service'>('dine_in')
  const [tableOrLocation, setTableOrLocation] = useState('')
  const [selectedReservationId, setSelectedReservationId] = useState('')
  const [chargeToRoom, setChargeToRoom] = useState(false)
  const [cart, setCart] = useState<{ menu_item_id: number; name: string; price: number; quantity: number }[]>([])

  // Modals for Order detail / settlement / void / discount / menu creation
  const [activeOrder, setActiveOrder] = useState<PosOrderRecord | null>(null)
  const [paymentMethod, setPaymentMethod] = useState<'cash' | 'pos_card' | 'bank_transfer'>('cash')
  const [voidReason, setVoidReason] = useState('')
  const [voidStockAction, setVoidStockAction] = useState<'waste' | 'restock'>('waste')
  const [discountType, setDiscountType] = useState<'percentage' | 'fixed'>('percentage')
  const [discountValue, setDiscountValue] = useState('')
  const [discountReason, setDiscountReason] = useState('')

  // Menu Management state
  const [showOutletModal, setShowOutletModal] = useState(false)
  const [outletForm, setOutletForm] = useState({ code: '', name: '', type: 'restaurant', tax_percentage: '7.5', service_charge_percentage: '5' })
  const [showCatModal, setShowCatModal] = useState(false)
  const [catForm, setCatForm] = useState({ code: '', name: '' })
  const [showMenuModal, setShowMenuModal] = useState(false)
  const [menuForm, setMenuForm] = useState({
    sku: '',
    menu_category_id: '',
    name: '',
    price: '',
    stock_tracking_policy: 'none',
    is_room_service_eligible: true,
  })

  const activeOutletId = selectedOutletId ? Number(selectedOutletId) : (outletsQ.data?.[0]?.id ?? 0)

  const addToCart = (item: MenuItemRecord) => {
    setCart((prev) => {
      const existing = prev.find((l) => l.menu_item_id === item.id)
      if (existing) {
        return prev.map((l) => (l.menu_item_id === item.id ? { ...l, quantity: l.quantity + 1 } : l))
      }
      return [...prev, { menu_item_id: item.id, name: item.name, price: item.price, quantity: 1 }]
    })
  }

  const createOrderMut = useMutation({
    mutationFn: async () => {
      const payload = {
        pos_outlet_id: activeOutletId,
        order_type: orderType,
        table_or_location: tableOrLocation || undefined,
        reservation_id: selectedReservationId ? Number(selectedReservationId) : undefined,
        charge_to_room: chargeToRoom,
        submit: true,
        items: cart.map((c) => ({ menu_item_id: c.menu_item_id, quantity: c.quantity })),
      }
      return (await api.post<{ data: PosOrderRecord }>('/admin/pos/orders', payload)).data.data
    },
    onSuccess: (created) => {
      qc.invalidateQueries({ queryKey: ['pos'] })
      setCart([])
      setTableOrLocation('')
      setBanner({ t: 'success', m: `Order ${created.order_reference} submitted (${formatCurrency(created.total_amount, created.currency_code)}).` })
      setActiveOrder(created)
    },
    onError: (e) => setBanner({ t: 'error', m: errorMessage(e) }),
  })

  const statusMut = useMutation({
    mutationFn: async ({ id, status }: { id: number; status: string }) =>
      (await api.post<{ data: PosOrderRecord }>(`/admin/pos/orders/${id}/status`, { status })).data.data,
    onSuccess: (updated) => {
      qc.invalidateQueries({ queryKey: ['pos'] })
      setActiveOrder(updated)
      setBanner({ t: 'success', m: `Order ${updated.order_reference} updated to ${updated.status}.` })
    },
    onError: (e) => setBanner({ t: 'error', m: errorMessage(e) }),
  })

  const payMut = useMutation({
    mutationFn: async (orderId: number) =>
      (await api.post(`/admin/pos/orders/${orderId}/payments`, { method: paymentMethod })).data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['pos'] })
      setActiveOrder(null)
      setBanner({ t: 'success', m: 'POS payment recorded.' })
    },
    onError: (e) => setBanner({ t: 'error', m: errorMessage(e) }),
  })

  const roomChargeMut = useMutation({
    mutationFn: async ({ orderId, reservationId }: { orderId: number; reservationId?: number }) =>
      (await api.post<{ data: PosOrderRecord }>(`/admin/pos/orders/${orderId}/room-charge`, {
        reservation_id: reservationId,
      })).data.data,
    onSuccess: (updated) => {
      qc.invalidateQueries({ queryKey: ['pos'] })
      setActiveOrder(updated)
      setBanner({ t: 'success', m: `Order ${updated.order_reference} charged to guest room folio.` })
    },
    onError: (e) => setBanner({ t: 'error', m: errorMessage(e) }),
  })

  const discountMut = useMutation({
    mutationFn: async (orderId: number) =>
      (await api.post<{ data: PosOrderRecord }>(`/admin/pos/orders/${orderId}/discount`, {
        discount_type: discountType,
        discount_value: Number(discountValue),
        discount_reason: discountReason,
      })).data.data,
    onSuccess: (updated) => {
      qc.invalidateQueries({ queryKey: ['pos'] })
      setActiveOrder(updated)
      setDiscountValue('')
      setDiscountReason('')
      setBanner({ t: 'success', m: 'Authorized POS discount applied.' })
    },
    onError: (e) => setBanner({ t: 'error', m: errorMessage(e) }),
  })

  const voidMut = useMutation({
    mutationFn: async (orderId: number) =>
      (await api.post<{ data: PosOrderRecord }>(`/admin/pos/orders/${orderId}/void`, {
        reason: voidReason,
        stock_action: voidStockAction,
        reverse_payments: true,
      })).data.data,
    onSuccess: (updated) => {
      qc.invalidateQueries({ queryKey: ['pos'] })
      setActiveOrder(updated)
      setVoidReason('')
      setBanner({ t: 'success', m: `Order ${updated.order_reference} voided.` })
    },
    onError: (e) => setBanner({ t: 'error', m: errorMessage(e) }),
  })

  const createOutletMut = useMutation({
    mutationFn: async () =>
      (await api.post('/admin/pos/outlets', {
        code: outletForm.code,
        name: outletForm.name,
        type: outletForm.type,
        tax_percentage: Number(outletForm.tax_percentage),
        service_charge_percentage: Number(outletForm.service_charge_percentage),
      })).data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['pos', 'outlets'] })
      setShowOutletModal(false)
      setOutletForm({ code: '', name: '', type: 'restaurant', tax_percentage: '7.5', service_charge_percentage: '5' })
      setBanner({ t: 'success', m: 'POS outlet created.' })
    },
    onError: (e) => setBanner({ t: 'error', m: errorMessage(e) }),
  })

  const createCatMut = useMutation({
    mutationFn: async () => (await api.post('/admin/pos/menu-categories', catForm)).data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['pos', 'menu-categories'] })
      setShowCatModal(false)
      setCatForm({ code: '', name: '' })
      setBanner({ t: 'success', m: 'Menu category created.' })
    },
    onError: (e) => setBanner({ t: 'error', m: errorMessage(e) }),
  })

  const createMenuMut = useMutation({
    mutationFn: async () =>
      (await api.post('/admin/pos/menu-items', {
        sku: menuForm.sku,
        menu_category_id: Number(menuForm.menu_category_id),
        name: menuForm.name,
        price: Number(menuForm.price),
        stock_tracking_policy: menuForm.stock_tracking_policy,
        is_room_service_eligible: menuForm.is_room_service_eligible,
      })).data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['pos', 'menu-items'] })
      setShowMenuModal(false)
      setMenuForm({ sku: '', menu_category_id: '', name: '', price: '', stock_tracking_policy: 'none', is_room_service_eligible: true })
      setBanner({ t: 'success', m: 'Menu item created.' })
    },
    onError: (e) => setBanner({ t: 'error', m: errorMessage(e) }),
  })

  return (
    <div className="space-y-6">
      <PageHeader
        title="Restaurant, Bar & Room Service POS"
        description="Point of Sale terminal, kitchen/bar preparation queue, checked-in room-service billing, and shift sales reconciliation."
        actions={
          <div className="flex flex-wrap gap-2">
            {(['terminal', 'orders', 'room_service', 'menu', 'reports'] as const).map((t) => (
              <Button key={t} variant={tab === t ? 'primary' : 'secondary'} onClick={() => setTab(t)}>
                {t === 'terminal' ? 'POS Terminal' : t === 'orders' ? 'Order Queue' : t === 'room_service' ? 'Room Service' : t === 'menu' ? 'Outlets & Menu' : 'Shift Reports'}
              </Button>
            ))}
          </div>
        }
      />

      {banner && <Alert variant={banner.t}>{banner.m}</Alert>}

      {outletsQ.isLoading || menuItemsQ.isLoading ? (
        <Spinner />
      ) : outletsQ.isError ? (
        <ErrorState message={errorMessage(outletsQ.error)} onRetry={() => outletsQ.refetch()} />
      ) : (
        <>
          {tab === 'terminal' && (
            <div className="grid gap-6 lg:grid-cols-3">
              <div className="lg:col-span-2 space-y-4">
                <Card>
                  <CardHeader title="Menu Catalog" description="Tap items to add to the current order ticket." />
                  <div className="p-5 space-y-4">
                    <div className="grid gap-4 sm:grid-cols-3">
                      <FormField id="pos-outlet" label="Outlet">
                        <Select id="pos-outlet" value={String(activeOutletId)} onChange={(e) => setSelectedOutletId(e.target.value)}>
                          {(outletsQ.data ?? []).map((o) => (
                            <option key={o.id} value={o.id}>{o.name} ({o.code})</option>
                          ))}
                        </Select>
                      </FormField>
                      <FormField id="pos-order-type" label="Order Type">
                        <Select id="pos-order-type" value={orderType} onChange={(e) => setOrderType(e.target.value as typeof orderType)}>
                          <option value="dine_in">Dine-In</option>
                          <option value="bar">Bar</option>
                          <option value="takeaway">Takeaway</option>
                          <option value="room_service">Room Service</option>
                        </Select>
                      </FormField>
                      <FormField id="pos-table" label="Table / Location">
                        <Input id="pos-table" placeholder="e.g. Table 6 or Poolside" value={tableOrLocation} onChange={(e) => setTableOrLocation(e.target.value)} />
                      </FormField>
                    </div>

                    {(menuItemsQ.data ?? []).length === 0 ? (
                      <EmptyState icon={UtensilsCrossed} title="No menu items configured" description="Create outlets, categories, and menu items under the Outlets & Menu tab." />
                    ) : (
                      <div className="grid gap-3 sm:grid-cols-2 md:grid-cols-3">
                        {(menuItemsQ.data ?? []).filter((m) => m.is_active && m.is_available && !m.is_sold_out).map((item) => (
                          <button
                            key={item.id}
                            type="button"
                            onClick={() => addToCart(item)}
                            className="flex flex-col justify-between rounded-lg border border-slate-200 bg-white p-3.5 text-left shadow-xs transition hover:border-brand-500 hover:bg-brand-50/20"
                          >
                            <div>
                              <div className="flex items-center justify-between gap-2">
                                <span className="text-xs font-mono text-slate-400">{item.sku}</span>
                                <Badge tone={item.stock_tracking_policy === 'none' ? 'neutral' : 'brand'}>{item.stock_tracking_policy}</Badge>
                              </div>
                              <p className="mt-1 font-medium text-slate-900">{item.name}</p>
                            </div>
                            <p className="mt-3 text-sm font-semibold text-brand-700">{formatCurrency(item.price, item.currency_code)}</p>
                          </button>
                        ))}
                      </div>
                    )}
                  </div>
                </Card>
              </div>

              <Card>
                <CardHeader title="Current Order Ticket" description="Server-validated pricing & room-charge eligibility." />
                <div className="p-5 space-y-4">
                  {(orderType === 'room_service' || chargeToRoom) && (
                    <FormField id="pos-stay" label="Checked-In Room Stay">
                      <Select id="pos-stay" value={selectedReservationId} onChange={(e) => setSelectedReservationId(e.target.value)}>
                        <option value="">Select checked-in room...</option>
                        {(staysQ.data ?? []).map((s) => (
                          <option key={s.reservation_id} value={s.reservation_id}>
                            Room {s.room_number} — {s.guest_name} ({s.reference})
                          </option>
                        ))}
                      </Select>
                    </FormField>
                  )}

                  <label className="flex items-center gap-2 text-sm text-slate-700">
                    <input type="checkbox" checked={chargeToRoom} onChange={(e) => setChargeToRoom(e.target.checked)} />
                    Post directly to Guest Room Folio upon submission
                  </label>

                  {cart.length === 0 ? (
                    <p className="py-8 text-center text-sm text-slate-400">No items added to ticket yet.</p>
                  ) : (
                    <ul className="divide-y divide-slate-100 text-sm">
                      {cart.map((line) => (
                        <li key={line.menu_item_id} className="flex items-center justify-between py-2.5">
                          <div>
                            <p className="font-medium text-slate-800">{line.name}</p>
                            <p className="text-xs text-slate-500">{line.quantity} × {formatCurrency(line.price)}</p>
                          </div>
                          <div className="flex items-center gap-2">
                            <span className="font-medium">{formatCurrency(line.price * line.quantity)}</span>
                            <Button
                              variant="ghost"
                              onClick={() => setCart((prev) => prev.filter((p) => p.menu_item_id !== line.menu_item_id))}
                            >
                              <Trash2 className="h-4 w-4 text-red-600" />
                            </Button>
                          </div>
                        </li>
                      ))}
                    </ul>
                  )}

                  <div className="border-t border-slate-200 pt-3 flex items-center justify-between font-semibold text-slate-900">
                    <span>Subtotal (before tax/service)</span>
                    <span>{formatCurrency(cart.reduce((s, c) => s + c.price * c.quantity, 0))}</span>
                  </div>

                  <Button
                    className="w-full"
                    disabled={cart.length === 0 || !activeOutletId || !can('pos.sell')}
                    loading={createOrderMut.isPending}
                    onClick={() => createOrderMut.mutate()}
                  >
                    Submit Order to Kitchen / Bar
                  </Button>
                </div>
              </Card>
            </div>
          )}

          {(tab === 'orders' || tab === 'room_service') && (
            <Card>
              <CardHeader
                title={tab === 'room_service' ? 'Room-Service Delivery Queue' : 'Active & Historical POS Orders'}
                description="Click any order to update preparation/delivery status, record payment, post room charge, or void."
              />
              <DataTable
                rows={(ordersQ.data ?? []).filter((o) => (tab === 'room_service' ? o.order_type === 'room_service' : true))}
                rowKey={(r) => r.id}
                empty={<EmptyState icon={Receipt} title="No orders found" description="Orders submitted from the POS terminal will appear here." />}
                columns={[
                  { key: 'order_reference', header: 'Reference', cell: (r) => <span className="font-mono font-medium">{r.order_reference}</span> },
                  { key: 'order_type', header: 'Type', cell: (r) => <Badge tone="neutral">{r.order_type}</Badge> },
                  { key: 'table_or_location', header: 'Table / Room', cell: (r) => r.table_or_location || '—' },
                  { key: 'guest_name_snapshot', header: 'Guest', cell: (r) => r.guest_name_snapshot || 'Walk-in' },
                  { key: 'status', header: 'Prep Status', cell: (r) => <Badge tone={statusTone(r.status)}>{r.status}</Badge> },
                  { key: 'payment_status', header: 'Payment', cell: (r) => <Badge tone={statusTone(r.payment_status)}>{r.payment_status}</Badge> },
                  { key: 'total_amount', header: 'Total', cell: (r) => <span className="font-semibold">{formatCurrency(r.total_amount, r.currency_code)}</span> },
                  {
                    key: 'actions',
                    header: 'Actions',
                    cell: (r) => (
                      <Button variant="secondary" size="sm" onClick={() => setActiveOrder(r)}>Manage</Button>
                    ),
                  },
                ]}
              />
            </Card>
          )}

          {tab === 'menu' && (
            <div className="space-y-6">
              <div className="flex flex-wrap gap-2">
                {can('pos.menu', 'pos.outlets.manage') && (
                  <>
                    <Button onClick={() => setShowOutletModal(true)}><Plus className="mr-1.5 h-4 w-4" />Add Outlet</Button>
                    <Button variant="secondary" onClick={() => setShowCatModal(true)}><Plus className="mr-1.5 h-4 w-4" />Add Menu Category</Button>
                    <Button variant="secondary" onClick={() => setShowMenuModal(true)}><Plus className="mr-1.5 h-4 w-4" />Add Menu Item</Button>
                  </>
                )}
              </div>

              <Card>
                <CardHeader title="Configured POS Outlets" />
                <DataTable
                  rows={outletsQ.data ?? []}
                  rowKey={(r) => r.id}
                  empty={<EmptyState title="No outlets configured" />}
                  columns={[
                    { key: 'code', header: 'Code', cell: (r) => <span className="font-mono">{r.code}</span> },
                    { key: 'name', header: 'Name', cell: (r) => r.name },
                    { key: 'type', header: 'Type', cell: (r) => <Badge>{r.type}</Badge> },
                    { key: 'tax_percentage', header: 'Tax %', cell: (r) => `${r.tax_percentage ?? 0}%` },
                    { key: 'service_charge_percentage', header: 'Service %', cell: (r) => `${r.service_charge_percentage ?? 0}%` },
                    { key: 'is_active', header: 'Status', cell: (r) => <Badge tone={r.is_active ? 'success' : 'danger'}>{r.is_active ? 'Active' : 'Closed'}</Badge> },
                  ]}
                />
              </Card>

              <Card>
                <CardHeader title="Menu Items" />
                <DataTable
                  rows={menuItemsQ.data ?? []}
                  rowKey={(r) => r.id}
                  empty={<EmptyState title="No menu items" />}
                  columns={[
                    { key: 'sku', header: 'SKU', cell: (r) => <span className="font-mono">{r.sku}</span> },
                    { key: 'name', header: 'Item Name', cell: (r) => r.name },
                    { key: 'category', header: 'Category', cell: (r) => r.category?.name ?? '—' },
                    { key: 'price', header: 'Price', cell: (r) => formatCurrency(r.price, r.currency_code) },
                    { key: 'stock_tracking_policy', header: 'Stock Tracking', cell: (r) => <Badge tone="brand">{r.stock_tracking_policy}</Badge> },
                    { key: 'is_room_service_eligible', header: 'Room Service', cell: (r) => (r.is_room_service_eligible ? 'Yes' : 'No') },
                  ]}
                />
              </Card>
            </div>
          )}

          {tab === 'reports' && (
            <Card>
              <CardHeader title="POS Shift & Daily Sales Reconciliation" description="Verified sales totals, discounts, voids, and room-service charges." />
              <div className="p-5">
                {reportsQ.isLoading ? (
                  <Spinner />
                ) : reportsQ.data ? (
                  <pre className="overflow-x-auto rounded-lg bg-slate-900 p-4 text-xs text-slate-100">
                    {JSON.stringify(reportsQ.data, null, 2)}
                  </pre>
                ) : null}
              </div>
            </Card>
          )}
        </>
      )}

      {/* Order Management / Receipt / Settlement Modal */}
      <Modal open={!!activeOrder} onOpenChange={(o) => !o && setActiveOrder(null)} title={activeOrder ? `Order ${activeOrder.order_reference}` : 'Order'}>
        {activeOrder && (
          <div className="space-y-4 text-sm">
            <div className="flex flex-wrap items-center justify-between gap-2 rounded-lg bg-slate-50 p-3">
              <div>
                <p className="font-medium text-slate-900">{activeOrder.order_type.toUpperCase()} • {activeOrder.table_or_location || 'Counter'}</p>
                <p className="text-xs text-slate-500">Guest: {activeOrder.guest_name_snapshot || 'Walk-in'}</p>
              </div>
              <div className="flex gap-2">
                <Badge tone={statusTone(activeOrder.status)}>{activeOrder.status}</Badge>
                <Badge tone={statusTone(activeOrder.payment_status)}>{activeOrder.payment_status}</Badge>
              </div>
            </div>

            <div className="divide-y divide-slate-100 border-y border-slate-200 py-2">
              {(activeOrder.items ?? []).map((item) => (
                <div key={item.id} className="flex justify-between py-1.5">
                  <span>{item.quantity}× {item.item_name_snapshot} {item.variant_name_snapshot ? `(${item.variant_name_snapshot})` : ''}</span>
                  <span className="font-medium">{formatCurrency(item.line_subtotal, activeOrder.currency_code)}</span>
                </div>
              ))}
            </div>

            <div className="space-y-1 text-right">
              <p className="text-slate-600">Total: <span className="font-semibold text-slate-900">{formatCurrency(activeOrder.total_amount, activeOrder.currency_code)}</span></p>
              <p className="text-slate-600">Balance Due: <span className="font-semibold text-brand-700">{formatCurrency(activeOrder.balance_due_amount, activeOrder.currency_code)}</span></p>
            </div>

            {/* Status progression buttons */}
            {!['voided', 'cancelled', 'served', 'delivered'].includes(activeOrder.status) && (
              <div className="flex flex-wrap gap-2">
                {['preparing', 'ready', activeOrder.order_type === 'room_service' ? 'out_for_delivery' : 'served', activeOrder.order_type === 'room_service' ? 'delivered' : 'served']
                  .filter((v, i, a) => a.indexOf(v) === i && v !== activeOrder.status)
                  .map((st) => (
                    <Button key={st} variant="secondary" loading={statusMut.isPending} onClick={() => statusMut.mutate({ id: activeOrder.id, status: st })}>
                      Mark {st.replace(/_/g, ' ')}
                    </Button>
                  ))}
              </div>
            )}

            {/* Settlement controls */}
            {activeOrder.payment_status === 'unpaid' && !['voided', 'cancelled'].includes(activeOrder.status) && (
              <div className="space-y-3 rounded-lg border border-slate-200 p-3">
                <p className="font-medium text-slate-900">Settle Order Bill</p>
                <div className="flex flex-wrap items-center gap-2">
                  <Select value={paymentMethod} onChange={(e) => setPaymentMethod(e.target.value as typeof paymentMethod)}>
                    <option value="cash">Cash</option>
                    <option value="pos_card">POS Card Terminal</option>
                    <option value="bank_transfer">Bank Transfer</option>
                  </Select>
                  <Button loading={payMut.isPending} onClick={() => payMut.mutate(activeOrder.id)}>
                    Record Direct Payment
                  </Button>
                  {activeOrder.reservation_id && can('pos.room_charge.post') && (
                    <Button variant="secondary" loading={roomChargeMut.isPending} onClick={() => roomChargeMut.mutate({ orderId: activeOrder.id })}>
                      Charge to Guest Room Folio
                    </Button>
                  )}
                </div>

                {can('pos.discount') && (
                  <div className="border-t border-slate-100 pt-3 space-y-2">
                    <p className="text-xs font-medium text-slate-700">Apply Supervisor Discount</p>
                    <div className="grid gap-2 sm:grid-cols-3">
                      <Select value={discountType} onChange={(e) => setDiscountType(e.target.value as 'percentage' | 'fixed')}>
                        <option value="percentage">Percentage (%)</option>
                        <option value="fixed">Fixed Amount</option>
                      </Select>
                      <Input placeholder="Value" value={discountValue} onChange={(e) => setDiscountValue(e.target.value)} />
                      <Input placeholder="Reason (required)" value={discountReason} onChange={(e) => setDiscountReason(e.target.value)} />
                    </div>
                    <Button variant="secondary" loading={discountMut.isPending} onClick={() => discountMut.mutate(activeOrder.id)}>
                      Apply Discount
                    </Button>
                  </div>
                )}
              </div>
            )}

            {/* Void controls */}
            {!['voided', 'cancelled'].includes(activeOrder.status) && can('pos.void') && (
              <div className="space-y-2 rounded-lg border border-red-200 bg-red-50/40 p-3">
                <p className="text-xs font-semibold text-red-800">Authorized Order Void / Reversal</p>
                <div className="grid gap-2 sm:grid-cols-2">
                  <Input placeholder="Mandatory void reason..." value={voidReason} onChange={(e) => setVoidReason(e.target.value)} />
                  <Select value={voidStockAction} onChange={(e) => setVoidStockAction(e.target.value as 'waste' | 'restock')}>
                    <option value="waste">Keep ingredient deduction as kitchen waste</option>
                    <option value="restock">Restock unopened/unprepared ingredients</option>
                  </Select>
                </div>
                <Button variant="danger" loading={voidMut.isPending} onClick={() => voidMut.mutate(activeOrder.id)}>
                  Void Order
                </Button>
              </div>
            )}

            <div className="flex justify-end">
              <Button variant="secondary" onClick={() => window.print()}><Printer className="mr-1.5 h-4 w-4" />Print Receipt</Button>
            </div>
          </div>
        )}
      </Modal>

      {/* Create Outlet Modal */}
      <Modal open={showOutletModal} onOpenChange={(o) => !o && setShowOutletModal(false)} title="Create POS Outlet">
        <div className="space-y-3">
          <FormField id="out-code" label="Outlet Code"><Input id="out-code" value={outletForm.code} onChange={(e) => setOutletForm({ ...outletForm, code: e.target.value })} placeholder="MAIN-REST" /></FormField>
          <FormField id="out-name" label="Outlet Name"><Input id="out-name" value={outletForm.name} onChange={(e) => setOutletForm({ ...outletForm, name: e.target.value })} placeholder="Zaria Courtyard Restaurant" /></FormField>
          <FormField id="out-type" label="Outlet Type">
            <Select id="out-type" value={outletForm.type} onChange={(e) => setOutletForm({ ...outletForm, type: e.target.value })}>
              <option value="restaurant">Restaurant</option>
              <option value="bar">Bar</option>
              <option value="room_service">Room Service</option>
              <option value="cafe">Cafe</option>
              <option value="pool_bar">Pool Bar</option>
            </Select>
          </FormField>
          <Button loading={createOutletMut.isPending} onClick={() => createOutletMut.mutate()}>Save Outlet</Button>
        </div>
      </Modal>

      {/* Create Menu Category Modal */}
      <Modal open={showCatModal} onOpenChange={(o) => !o && setShowCatModal(false)} title="Create Menu Category">
        <div className="space-y-3">
          <FormField id="cat-code" label="Category Code"><Input id="cat-code" value={catForm.code} onChange={(e) => setCatForm({ ...catForm, code: e.target.value })} placeholder="GRILLS" /></FormField>
          <FormField id="cat-name" label="Category Name"><Input id="cat-name" value={catForm.name} onChange={(e) => setCatForm({ ...catForm, name: e.target.value })} placeholder="Grills & Suya" /></FormField>
          <Button loading={createCatMut.isPending} onClick={() => createCatMut.mutate()}>Save Category</Button>
        </div>
      </Modal>

      {/* Create Menu Item Modal */}
      <Modal open={showMenuModal} onOpenChange={(o) => !o && setShowMenuModal(false)} title="Create Menu Item">
        <div className="space-y-3">
          <FormField id="m-sku" label="SKU"><Input id="m-sku" value={menuForm.sku} onChange={(e) => setMenuForm({ ...menuForm, sku: e.target.value })} placeholder="MENU-JOLLOF" /></FormField>
          <FormField id="m-cat" label="Menu Category">
            <Select id="m-cat" value={menuForm.menu_category_id} onChange={(e) => setMenuForm({ ...menuForm, menu_category_id: e.target.value })}>
              <option value="">Select category...</option>
              {(categoriesQ.data ?? []).map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </Select>
          </FormField>
          <FormField id="m-name" label="Item Name"><Input id="m-name" value={menuForm.name} onChange={(e) => setMenuForm({ ...menuForm, name: e.target.value })} /></FormField>
          <FormField id="m-price" label="Selling Price (NGN)"><Input id="m-price" type="number" value={menuForm.price} onChange={(e) => setMenuForm({ ...menuForm, price: e.target.value })} /></FormField>
          <FormField id="m-policy" label="Stock Deduction Policy">
            <Select id="m-policy" value={menuForm.stock_tracking_policy} onChange={(e) => setMenuForm({ ...menuForm, stock_tracking_policy: e.target.value })}>
              <option value="none">None (non-stock / service)</option>
              <option value="direct_item">Direct Inventory Item</option>
              <option value="recipe">Recipe / Bill of Materials (BOM)</option>
            </Select>
          </FormField>
          <Button loading={createMenuMut.isPending} onClick={() => createMenuMut.mutate()}>Save Menu Item</Button>
        </div>
      </Modal>
    </div>
  )
}

/* ========================================================================
 * 2. INVENTORY, STOCK MOVEMENTS & RECIPES PAGE (/admin/inventory)
 * ======================================================================== */
export function InventoryAdminPage() {
  const { can } = useAuth()
  const qc = useQueryClient()
  const [tab, setTab] = useState<'items' | 'movements' | 'recipes' | 'locations'>('items')
  const [banner, setBanner] = useState<{ t: 'success' | 'error'; m: string } | null>(null)

  const dashboardQ = useQuery({
    queryKey: ['inventory', 'dashboard'],
    queryFn: async () => (await api.get<{ data: Record<string, unknown> }>('/admin/inventory/dashboard')).data.data,
  })
  const itemsQ = useQuery({
    queryKey: ['inventory', 'items'],
    queryFn: async () => (await api.get<{ data: InventoryItemRecord[] }>('/admin/inventory/items')).data.data,
  })
  const locationsQ = useQuery({
    queryKey: ['inventory', 'locations'],
    queryFn: async () => (await api.get<{ data: StorageLocationRecord[] }>('/admin/inventory/locations')).data.data,
  })
  const recipesQ = useQuery({
    queryKey: ['inventory', 'recipes'],
    queryFn: async () => (await api.get<{ data: MenuItemRecipeRecord[] }>('/admin/inventory/recipes')).data.data,
    enabled: tab === 'recipes',
  })

  const [showItemModal, setShowItemModal] = useState(false)
  const [itemForm, setItemForm] = useState({ sku: '', name: '', base_unit: 'kg', purchase_unit: 'kg', purchase_to_base_factor: '1', reorder_level: '5', unit_cost: '0' })

  const [showMoveModal, setShowMoveModal] = useState(false)
  const [moveForm, setMoveForm] = useState({ inventory_item_id: '', storage_location_id: '', movement_type: 'adjustment', direction: 'add', quantity: '', reason: '' })

  const createItemMut = useMutation({
    mutationFn: async () =>
      (await api.post('/admin/inventory/items', {
        sku: itemForm.sku,
        name: itemForm.name,
        base_unit: itemForm.base_unit,
        purchase_unit: itemForm.purchase_unit,
        purchase_to_base_factor: Number(itemForm.purchase_to_base_factor),
        reorder_level: Number(itemForm.reorder_level),
        unit_cost: Number(itemForm.unit_cost),
      })).data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['inventory'] })
      setShowItemModal(false)
      setBanner({ t: 'success', m: 'Inventory item created.' })
    },
    onError: (e) => setBanner({ t: 'error', m: errorMessage(e) }),
  })

  const recordMoveMut = useMutation({
    mutationFn: async () =>
      (await api.post('/admin/inventory/movements', {
        inventory_item_id: Number(moveForm.inventory_item_id),
        storage_location_id: Number(moveForm.storage_location_id),
        movement_type: moveForm.movement_type,
        direction: moveForm.direction,
        quantity: Number(moveForm.quantity),
        reason: moveForm.reason,
      })).data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['inventory'] })
      setShowMoveModal(false)
      setBanner({ t: 'success', m: 'Immutable stock movement recorded.' })
    },
    onError: (e) => setBanner({ t: 'error', m: errorMessage(e) }),
  })

  return (
    <div className="space-y-6">
      <PageHeader
        title="Inventory, Stock Ledger & Recipes (BOM)"
        description="Weighted-average stock valuation, multi-location balances, append-only stock movements, and kitchen/bar recipe BOM mappings."
        actions={
          <div className="flex flex-wrap gap-2">
            {(['items', 'movements', 'recipes', 'locations'] as const).map((t) => (
              <Button key={t} variant={tab === t ? 'primary' : 'secondary'} onClick={() => setTab(t)}>
                {t === 'items' ? 'Stock Items' : t === 'movements' ? 'Movement Ledger' : t === 'recipes' ? 'Recipes (BOM)' : 'Storage Locations'}
              </Button>
            ))}
            {can('inventory.create') && <Button onClick={() => setShowItemModal(true)}><Plus className="mr-1.5 h-4 w-4" />New Item</Button>}
            {can('inventory.adjust', 'inventory.waste') && <Button variant="secondary" onClick={() => setShowMoveModal(true)}>Record Adjustment / Waste</Button>}
          </div>
        }
      />

      {banner && <Alert variant={banner.t}>{banner.m}</Alert>}

      {itemsQ.isLoading ? (
        <Spinner />
      ) : itemsQ.isError ? (
        <ErrorState message={errorMessage(itemsQ.error)} onRetry={() => itemsQ.refetch()} />
      ) : (
        <>
          {tab === 'items' && (
            <Card>
              <CardHeader title="Inventory Items & Balances" />
              <DataTable
                rows={itemsQ.data ?? []}
                rowKey={(r) => r.id}
                empty={<EmptyState icon={Boxes} title="No inventory items" description="Create stock items to track hotel supplies and kitchen ingredients." />}
                columns={[
                  { key: 'sku', header: 'SKU', cell: (r) => <span className="font-mono">{r.sku}</span> },
                  { key: 'name', header: 'Item Name', cell: (r) => r.name },
                  { key: 'current_stock', header: 'Current Stock', cell: (r) => <span className="font-semibold">{r.current_stock} {r.base_unit}</span> },
                  { key: 'reorder_level', header: 'Reorder Level', cell: (r) => `${r.reorder_level} ${r.base_unit}` },
                  {
                    key: 'unit_cost',
                    header: 'Weighted Avg Cost',
                    cell: (r) => (r.unit_cost !== null && r.unit_cost !== undefined ? formatCurrency(r.unit_cost, r.currency_code) : 'Restricted'),
                  },
                  {
                    key: 'status',
                    header: 'Stock Alert',
                    cell: (r) => (r.is_low_stock ? <Badge tone="danger">Low Stock</Badge> : <Badge tone="success">OK</Badge>),
                  },
                ]}
              />
            </Card>
          )}

          {tab === 'movements' && (
            <Card>
              <CardHeader title="Immutable Stock Movement Ledger" description="Protected by MariaDB BEFORE UPDATE / BEFORE DELETE triggers." />
              <DataTable
                rows={((dashboardQ.data as { recent_movements?: StockMovementRecord[] })?.recent_movements ?? [])}
                rowKey={(r) => r.id}
                empty={<EmptyState title="No stock movements recorded yet" />}
                columns={[
                  { key: 'movement_reference', header: 'Reference', cell: (r) => <span className="font-mono">{r.movement_reference}</span> },
                  { key: 'item', header: 'Item', cell: (r) => r.item?.name ?? '—' },
                  { key: 'location', header: 'Location', cell: (r) => r.location?.name ?? '—' },
                  { key: 'movement_type', header: 'Type', cell: (r) => <Badge tone="brand">{r.movement_type}</Badge> },
                  { key: 'quantity_delta', header: 'Delta', cell: (r) => <span className={r.quantity_delta < 0 ? 'text-red-600 font-medium' : 'text-emerald-700 font-medium'}>{r.quantity_delta > 0 ? `+${r.quantity_delta}` : r.quantity_delta} {r.unit}</span> },
                  { key: 'balance_after', header: 'Balance After', cell: (r) => `${r.balance_after} ${r.unit}` },
                  { key: 'reason', header: 'Reason', cell: (r) => r.reason || '—' },
                ]}
              />
            </Card>
          )}

          {tab === 'recipes' && (
            <Card>
              <CardHeader title="Menu Item Recipes / Bill of Materials (BOM)" />
              <DataTable
                rows={recipesQ.data ?? []}
                rowKey={(r) => r.id}
                empty={<EmptyState title="No recipes configured" />}
                columns={[
                  { key: 'menu_item', header: 'Menu Item', cell: (r) => r.menu_item?.name ?? `#${r.menu_item_id}` },
                  { key: 'inventory_item', header: 'Ingredient', cell: (r) => r.inventory_item?.name ?? `#${r.inventory_item_id}` },
                  { key: 'quantity_required', header: 'Qty per Portion', cell: (r) => `${r.quantity_required} ${r.unit}` },
                  { key: 'waste_percentage', header: 'Prep Waste %', cell: (r) => `${r.waste_percentage}%` },
                ]}
              />
            </Card>
          )}

          {tab === 'locations' && (
            <Card>
              <CardHeader title="Storage Locations" />
              <DataTable
                rows={locationsQ.data ?? []}
                rowKey={(r) => r.id}
                empty={<EmptyState title="No storage locations" />}
                columns={[
                  { key: 'code', header: 'Code', cell: (r) => <span className="font-mono">{r.code}</span> },
                  { key: 'name', header: 'Location Name', cell: (r) => r.name },
                  { key: 'location_type', header: 'Type', cell: (r) => <Badge>{r.location_type}</Badge> },
                  { key: 'is_default', header: 'Default', cell: (r) => (r.is_default ? 'Yes' : 'No') },
                ]}
              />
            </Card>
          )}
        </>
      )}

      {/* New Inventory Item Modal */}
      <Modal open={showItemModal} onOpenChange={(o) => !o && setShowItemModal(false)} title="Create Inventory Item">
        <div className="space-y-3">
          <FormField id="inv-sku" label="SKU"><Input id="inv-sku" value={itemForm.sku} onChange={(e) => setItemForm({ ...itemForm, sku: e.target.value })} placeholder="INV-RICE-KG" /></FormField>
          <FormField id="inv-name" label="Item Name"><Input id="inv-name" value={itemForm.name} onChange={(e) => setItemForm({ ...itemForm, name: e.target.value })} placeholder="Parboiled Rice" /></FormField>
          <div className="grid gap-3 sm:grid-cols-3">
            <FormField id="inv-base" label="Base Unit"><Input id="inv-base" value={itemForm.base_unit} onChange={(e) => setItemForm({ ...itemForm, base_unit: e.target.value })} /></FormField>
            <FormField id="inv-pur" label="Purchase Unit"><Input id="inv-pur" value={itemForm.purchase_unit} onChange={(e) => setItemForm({ ...itemForm, purchase_unit: e.target.value })} /></FormField>
            <FormField id="inv-fac" label="Conversion Factor"><Input id="inv-fac" type="number" value={itemForm.purchase_to_base_factor} onChange={(e) => setItemForm({ ...itemForm, purchase_to_base_factor: e.target.value })} /></FormField>
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <FormField id="inv-reord" label="Reorder Threshold"><Input id="inv-reord" type="number" value={itemForm.reorder_level} onChange={(e) => setItemForm({ ...itemForm, reorder_level: e.target.value })} /></FormField>
            <FormField id="inv-cost" label="Initial Unit Cost (NGN)"><Input id="inv-cost" type="number" value={itemForm.unit_cost} onChange={(e) => setItemForm({ ...itemForm, unit_cost: e.target.value })} /></FormField>
          </div>
          <Button loading={createItemMut.isPending} onClick={() => createItemMut.mutate()}>Create Item</Button>
        </div>
      </Modal>

      {/* Record Stock Movement Modal */}
      <Modal open={showMoveModal} onOpenChange={(o) => !o && setShowMoveModal(false)} title="Record Stock Adjustment or Waste">
        <div className="space-y-3">
          <FormField id="mv-item" label="Inventory Item">
            <Select id="mv-item" value={moveForm.inventory_item_id} onChange={(e) => setMoveForm({ ...moveForm, inventory_item_id: e.target.value })}>
              <option value="">Select item...</option>
              {(itemsQ.data ?? []).map((i) => <option key={i.id} value={i.id}>{i.name} ({i.sku})</option>)}
            </Select>
          </FormField>
          <FormField id="mv-loc" label="Storage Location">
            <Select id="mv-loc" value={moveForm.storage_location_id} onChange={(e) => setMoveForm({ ...moveForm, storage_location_id: e.target.value })}>
              <option value="">Select location...</option>
              {(locationsQ.data ?? []).map((l) => <option key={l.id} value={l.id}>{l.name}</option>)}
            </Select>
          </FormField>
          <div className="grid gap-3 sm:grid-cols-3">
            <FormField id="mv-type" label="Movement Type">
              <Select id="mv-type" value={moveForm.movement_type} onChange={(e) => setMoveForm({ ...moveForm, movement_type: e.target.value })}>
                <option value="adjustment">Adjustment</option>
                <option value="waste">Waste / Spoilage</option>
                <option value="supplier_return">Supplier Return</option>
              </Select>
            </FormField>
            <FormField id="mv-dir" label="Direction">
              <Select id="mv-dir" value={moveForm.direction} onChange={(e) => setMoveForm({ ...moveForm, direction: e.target.value })}>
                <option value="add">Add Stock (+)</option>
                <option value="deduct">Deduct Stock (-)</option>
              </Select>
            </FormField>
            <FormField id="mv-qty" label="Quantity"><Input id="mv-qty" type="number" value={moveForm.quantity} onChange={(e) => setMoveForm({ ...moveForm, quantity: e.target.value })} /></FormField>
          </div>
          <FormField id="mv-reason" label="Mandatory Audit Reason"><Textarea id="mv-reason" value={moveForm.reason} onChange={(e) => setMoveForm({ ...moveForm, reason: e.target.value })} /></FormField>
          <Button loading={recordMoveMut.isPending} onClick={() => recordMoveMut.mutate()}>Commit Movement</Button>
        </div>
      </Modal>
    </div>
  )
}

/* ========================================================================
 * 3. SUPPLIERS, REQUISITIONS, PURCHASE ORDERS & GRN PAGE (/admin/procurement)
 * ======================================================================== */
export function ProcurementAdminPage() {
  const { can } = useAuth()
  const qc = useQueryClient()
  const [tab, setTab] = useState<'pos' | 'requisitions' | 'suppliers'>('pos')
  const [banner, setBanner] = useState<{ t: 'success' | 'error'; m: string } | null>(null)

  const posQ = useQuery({
    queryKey: ['procurement', 'purchase-orders'],
    queryFn: async () => (await api.get<{ data: PurchaseOrderRecord[] }>('/admin/procurement/purchase-orders')).data.data,
  })
  const reqsQ = useQuery({
    queryKey: ['procurement', 'requisitions'],
    queryFn: async () => (await api.get<{ data: PurchaseRequisitionRecord[] }>('/admin/procurement/requisitions')).data.data,
  })
  const suppliersQ = useQuery({
    queryKey: ['procurement', 'suppliers'],
    queryFn: async () => (await api.get<{ data: SupplierRecord[] }>('/admin/procurement/suppliers')).data.data,
  })

  const [showSupplierModal, setShowSupplierModal] = useState(false)
  const [supForm, setSupForm] = useState({ supplier_code: '', name: '', contact_person: '', phone: '', email: '' })

  const createSupplierMut = useMutation({
    mutationFn: async () => (await api.post('/admin/procurement/suppliers', supForm)).data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['procurement', 'suppliers'] })
      setShowSupplierModal(false)
      setBanner({ t: 'success', m: 'Supplier created.' })
    },
    onError: (e) => setBanner({ t: 'error', m: errorMessage(e) }),
  })

  const reviewPoMut = useMutation({
    mutationFn: async ({ id, decision }: { id: number; decision: 'approve' | 'cancel' }) =>
      (await api.post(`/admin/procurement/purchase-orders/${id}/review`, { decision, reason: `Reviewed (${decision})` })).data,
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['procurement'] })
      setBanner({ t: 'success', m: 'Purchase order status updated.' })
    },
    onError: (e) => setBanner({ t: 'error', m: errorMessage(e) }),
  })

  const receiveFullPoMut = useMutation({
    mutationFn: async (po: PurchaseOrderRecord) => {
      const items = (po.items ?? [])
        .map((i) => ({
          purchase_order_item_id: i.id,
          quantity_received: Math.max(0, Number(i.quantity_ordered) - Number(i.quantity_received)),
        }))
        .filter((i) => i.quantity_received > 0)
      return (await api.post(`/admin/procurement/purchase-orders/${po.id}/receive`, { items })).data
    },
    onSuccess: () => {
      qc.invalidateQueries({ queryKey: ['procurement'] })
      qc.invalidateQueries({ queryKey: ['inventory'] })
      setBanner({ t: 'success', m: 'Goods Receiving Note (GRN) committed and inventory stock incremented.' })
    },
    onError: (e) => setBanner({ t: 'error', m: errorMessage(e) }),
  })

  return (
    <div className="space-y-6">
      <PageHeader
        title="Procurement, Purchase Orders & Goods Receiving"
        description="Separation-of-duties purchase approvals, supplier directory, and verified Goods Receiving Notes (GRN)."
        actions={
          <div className="flex flex-wrap gap-2">
            <Button variant={tab === 'pos' ? 'primary' : 'secondary'} onClick={() => setTab('pos')}>Purchase Orders</Button>
            <Button variant={tab === 'requisitions' ? 'primary' : 'secondary'} onClick={() => setTab('requisitions')}>Requisitions</Button>
            <Button variant={tab === 'suppliers' ? 'primary' : 'secondary'} onClick={() => setTab('suppliers')}>Suppliers</Button>
            {can('procurement.suppliers.manage') && (
              <Button variant="secondary" onClick={() => setShowSupplierModal(true)}><Plus className="mr-1.5 h-4 w-4" />Add Supplier</Button>
            )}
          </div>
        }
      />

      {banner && <Alert variant={banner.t}>{banner.m}</Alert>}

      {posQ.isLoading ? (
        <Spinner />
      ) : (
        <>
          {tab === 'pos' && (
            <Card>
              <CardHeader title="Purchase Orders (PO)" description="Stock increases ONLY when an approved PO is received via a Goods Receiving Note (GRN)." />
              <DataTable
                rows={posQ.data ?? []}
                rowKey={(r) => r.id}
                empty={<EmptyState icon={ShoppingCart} title="No purchase orders" />}
                columns={[
                  { key: 'po_reference', header: 'PO Ref', cell: (r) => <span className="font-mono font-medium">{r.po_reference}</span> },
                  { key: 'supplier', header: 'Supplier', cell: (r) => r.supplier?.name ?? '—' },
                  { key: 'location', header: 'Target Store', cell: (r) => r.location?.name ?? '—' },
                  { key: 'status', header: 'Status', cell: (r) => <Badge tone={statusTone(r.status)}>{r.status}</Badge> },
                  {
                    key: 'total_amount',
                    header: 'Total Cost',
                    cell: (r) => (r.total_amount !== null && r.total_amount !== undefined ? formatCurrency(r.total_amount, r.currency_code) : 'Restricted'),
                  },
                  {
                    key: 'actions',
                    header: 'Actions',
                    cell: (r) => (
                      <div className="flex gap-2">
                        {r.status === 'pending_approval' && can('procurement.approve') && (
                          <Button variant="secondary" size="sm" onClick={() => reviewPoMut.mutate({ id: r.id, decision: 'approve' })}>
                            <CheckCircle2 className="mr-1 h-4 w-4" />Approve
                          </Button>
                        )}
                        {['approved', 'partially_received'].includes(r.status) && can('procurement.receive') && (
                          <Button size="sm" onClick={() => receiveFullPoMut.mutate(r)}>Receive Goods (GRN)</Button>
                        )}
                      </div>
                    ),
                  },
                ]}
              />
            </Card>
          )}

          {tab === 'requisitions' && (
            <Card>
              <CardHeader title="Internal Purchase Requisitions" />
              <DataTable
                rows={reqsQ.data ?? []}
                rowKey={(r) => r.id}
                empty={<EmptyState title="No requisitions submitted" />}
                columns={[
                  { key: 'requisition_reference', header: 'Ref', cell: (r) => <span className="font-mono">{r.requisition_reference}</span> },
                  { key: 'priority', header: 'Priority', cell: (r) => <Badge>{r.priority}</Badge> },
                  { key: 'status', header: 'Status', cell: (r) => <Badge tone={statusTone(r.status)}>{r.status}</Badge> },
                  { key: 'reason', header: 'Reason', cell: (r) => r.reason || '—' },
                ]}
              />
            </Card>
          )}

          {tab === 'suppliers' && (
            <Card>
              <CardHeader title="Approved Suppliers Directory" />
              <DataTable
                rows={suppliersQ.data ?? []}
                rowKey={(r) => r.id}
                empty={<EmptyState title="No suppliers registered" />}
                columns={[
                  { key: 'supplier_code', header: 'Code', cell: (r) => <span className="font-mono">{r.supplier_code}</span> },
                  { key: 'name', header: 'Supplier Name', cell: (r) => r.name },
                  { key: 'contact_person', header: 'Contact Person', cell: (r) => r.contact_person || '—' },
                  { key: 'phone', header: 'Phone', cell: (r) => r.phone || '—' },
                  { key: 'status', header: 'Status', cell: (r) => <Badge tone={statusTone(r.status)}>{r.status}</Badge> },
                ]}
              />
            </Card>
          )}
        </>
      )}

      <Modal open={showSupplierModal} onOpenChange={(o) => !o && setShowSupplierModal(false)} title="Add Supplier">
        <div className="space-y-3">
          <FormField id="sup-code" label="Supplier Code"><Input id="sup-code" value={supForm.supplier_code} onChange={(e) => setSupForm({ ...supForm, supplier_code: e.target.value })} placeholder="SUP-ZARIA-01" /></FormField>
          <FormField id="sup-name" label="Company Name"><Input id="sup-name" value={supForm.name} onChange={(e) => setSupForm({ ...supForm, name: e.target.value })} /></FormField>
          <FormField id="sup-contact" label="Contact Person"><Input id="sup-contact" value={supForm.contact_person} onChange={(e) => setSupForm({ ...supForm, contact_person: e.target.value })} /></FormField>
          <FormField id="sup-phone" label="Phone"><Input id="sup-phone" value={supForm.phone} onChange={(e) => setSupForm({ ...supForm, phone: e.target.value })} /></FormField>
          <Button loading={createSupplierMut.isPending} onClick={() => createSupplierMut.mutate()}>Save Supplier</Button>
        </div>
      </Modal>
    </div>
  )
}
