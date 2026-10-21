import {
  BarChart3, BedDouble, Boxes, Building2, CalendarCheck, CalendarRange, ConciergeBell, CreditCard, FileText, Image, Landmark, LayoutDashboard,
  Menu, Moon, Receipt, Settings, ShieldCheck, ShoppingCart, SlidersHorizontal, Sparkles, Tag, Users, UserSquare2, UtensilsCrossed, Wrench, ScrollText,
  Globe, BriefcaseBusiness, MessageSquareQuote, type LucideIcon,
} from 'lucide-react'

export type NavItem = {
  label: string
  /** Absolute path under /admin */
  path: string
  icon: LucideIcon
  /** User needs ANY of these permissions to see/open the item. */
  permissions: string[]
  /** Implemented in Phase 1 or Phase 2? Otherwise renders a "Coming in a later phase" screen. */
  ready?: boolean
}
export type NavGroup = { label: string; items: NavItem[] }

export const navigation: NavGroup[] = [
  { label: 'Overview', items: [{ label: 'Dashboard', path: '/admin', icon: LayoutDashboard, permissions: ['dashboard.view'], ready: true }] },
  {
    label: 'Website Management',
    items: [
      { label: 'Pages', path: '/admin/website/pages', icon: FileText, permissions: ['website.view'], ready: true },
      { label: 'Media', path: '/admin/website/media', icon: Image, permissions: ['media.view'], ready: true },
      { label: 'Navigation', path: '/admin/website/navigation', icon: Menu, permissions: ['website.view'], ready: true },
      { label: 'Room Content', path: '/admin/website/rooms', icon: BedDouble, permissions: ['website.view'], ready: true },
      { label: 'Offers', path: '/admin/website/offers', icon: Tag, permissions: ['website.view'], ready: true },
      { label: 'Testimonials', path: '/admin/website/testimonials', icon: MessageSquareQuote, permissions: ['website.view'], ready: true },
      { label: 'Website Settings', path: '/admin/website/settings', icon: Globe, permissions: ['website.view', 'website.settings'], ready: true },
    ],
  },
  {
    label: 'Hotel Operations',
    items: [
      { label: 'Front Desk', path: '/admin/front-desk', icon: ConciergeBell, permissions: ['frontdesk.view', 'reservations.view'], ready: true },
      { label: 'Reservations', path: '/admin/reservations', icon: CalendarCheck, permissions: ['reservations.view'], ready: true },
      { label: 'Availability', path: '/admin/availability', icon: CalendarRange, permissions: ['reservations.view', 'rooms.view', 'frontdesk.view'], ready: true },
      { label: 'Room Types', path: '/admin/room-types', icon: BedDouble, permissions: ['rooms.view'], ready: true },
      { label: 'Rooms', path: '/admin/rooms', icon: Building2, permissions: ['rooms.view', 'housekeeping.view'], ready: true },
      { label: 'Rate Plans', path: '/admin/rate-plans', icon: Tag, permissions: ['rooms.view', 'rooms.rates'], ready: true },
      { label: 'Guest Folios', path: '/admin/folios', icon: Receipt, permissions: ['folios.view', 'folios.reports.view', 'finance.view'], ready: true },
      { label: 'Payment Records', path: '/admin/payments', icon: CreditCard, permissions: ['payments.view', 'finance.view'], ready: true },
      { label: 'Guests', path: '/admin/guests', icon: UserSquare2, permissions: ['guests.view'], ready: true },
      { label: 'Reservation Settings', path: '/admin/reservation-settings', icon: SlidersHorizontal, permissions: ['reservations.settings', 'settings.edit', 'settings.view'], ready: true },
      { label: 'Housekeeping', path: '/admin/housekeeping', icon: Sparkles, permissions: ['housekeeping.view', 'housekeeping.manage'], ready: true },
    ],
  },
  {
    label: 'Back Office',
    items: [
      { label: 'Inventory', path: '/admin/inventory', icon: Boxes, permissions: ['inventory.view'], ready: true },
      { label: 'Procurement', path: '/admin/procurement', icon: ShoppingCart, permissions: ['procurement.view'], ready: true },
      { label: 'Restaurant / POS', path: '/admin/pos', icon: UtensilsCrossed, permissions: ['pos.view'], ready: true },
      { label: 'Finance & Accounting', path: '/admin/finance', icon: Landmark, permissions: ['finance.view', 'accounting.view'], ready: true },
      { label: 'Night Audit', path: '/admin/night-audit', icon: Moon, permissions: ['night_audit.view'], ready: true },
      { label: 'Human Resources', path: '/admin/hr', icon: BriefcaseBusiness, permissions: ['hr.view', 'payroll.view'] },
      { label: 'Maintenance', path: '/admin/maintenance', icon: Wrench, permissions: ['maintenance.view'], ready: true },
      { label: 'Analytics & Reports', path: '/admin/reports', icon: BarChart3, permissions: ['reports.view', 'analytics.view'], ready: true },
    ],
  },
  {
    label: 'Administration',
    items: [
      { label: 'Users', path: '/admin/users', icon: Users, permissions: ['users.view'], ready: true },
      { label: 'Roles and Permissions', path: '/admin/roles', icon: ShieldCheck, permissions: ['roles.view'], ready: true },
      { label: 'Audit Logs', path: '/admin/audit-logs', icon: ScrollText, permissions: ['audit.view'], ready: true },
      { label: 'System Settings', path: '/admin/settings', icon: Settings, permissions: ['settings.view'], ready: true },
    ],
  },
]

export const allNavItems = navigation.flatMap((g) => g.items.map((i) => ({ ...i, group: g.label })))
