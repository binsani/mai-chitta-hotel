import { lazy, Suspense, type ComponentType, type ReactElement } from 'react'
import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { useAuth } from '@/auth/AuthProvider'
import { allNavItems } from '@/lib/navigation'

function lazyPage(loader: () => Promise<object>, page: string) {
  return lazy(async () => ({ default: (await loader() as Record<string, unknown>)[page] as ComponentType<any> }))
}

const auth = () => import('@/pages/auth')
const general = () => import('@/pages/general')
const cms = () => import('@/pages/cms')
const reservations = () => import('@/pages/reservations')
const operations = () => import('@/pages/operations')
const posInventory = () => import('@/pages/pos_inventory')
const accountingAnalytics = () => import('@/pages/accounting_analytics')
const publicPages = () => import('@/pages/public')
const AdminLayout = lazyPage(() => import('@/layouts/AdminLayout'), 'AdminLayout')
const RequireAuth = lazyPage(() => import('@/layouts/AdminLayout'), 'RequireAuth')
const ContactInboxPage = lazyPage(() => import('@/pages/staff'), 'ContactInboxPage')
const HumanResourcesPage = lazyPage(() => import('@/pages/staff'), 'HumanResourcesPage')

const ForgotPasswordPage = lazyPage(auth, 'ForgotPasswordPage')
const LoginPage = lazyPage(auth, 'LoginPage')
const ResetPasswordPage = lazyPage(auth, 'ResetPasswordPage')
const AccessDeniedPage = lazyPage(general, 'AccessDeniedPage')
const ComingSoonPage = lazyPage(general, 'ComingSoonPage')
const DashboardPage = lazyPage(general, 'DashboardPage')
const NotFoundPage = lazyPage(general, 'NotFoundPage')
const ProfilePage = lazyPage(general, 'ProfilePage')
const AuditLogsPage = lazyPage(() => import('@/pages/admin'), 'AuditLogsPage')
const RolesPage = lazyPage(() => import('@/pages/admin'), 'RolesPage')
const SettingsPage = lazyPage(() => import('@/pages/admin'), 'SettingsPage')
const UsersPage = lazyPage(() => import('@/pages/admin'), 'UsersPage')
const CmsMediaPage = lazyPage(cms, 'CmsMediaPage')
const CmsMenusPage = lazyPage(cms, 'CmsMenusPage')
const CmsOffersPage = lazyPage(cms, 'CmsOffersPage')
const CmsPagesPage = lazyPage(cms, 'CmsPagesPage')
const CmsRoomsPage = lazyPage(cms, 'CmsRoomsPage')
const CmsTestimonialsPage = lazyPage(cms, 'CmsTestimonialsPage')
const CmsWebsiteSettingsPage = lazyPage(cms, 'CmsWebsiteSettingsPage')
const AvailabilityAdminPage = lazyPage(reservations, 'AvailabilityAdminPage')
const FrontDeskAdminPage = lazyPage(reservations, 'FrontDeskAdminPage')
const GuestsAdminPage = lazyPage(reservations, 'GuestsAdminPage')
const PaymentsAdminPage = lazyPage(reservations, 'PaymentsAdminPage')
const RatePlansAdminPage = lazyPage(reservations, 'RatePlansAdminPage')
const ReservationSettingsAdminPage = lazyPage(reservations, 'ReservationSettingsAdminPage')
const ReservationsAdminPage = lazyPage(reservations, 'ReservationsAdminPage')
const RoomsAdminPage = lazyPage(reservations, 'RoomsAdminPage')
const RoomTypesAdminPage = lazyPage(reservations, 'RoomTypesAdminPage')
const FoliosAdminPage = lazyPage(operations, 'FoliosAdminPage')
const HousekeepingAdminPage = lazyPage(operations, 'HousekeepingAdminPage')
const MaintenanceAdminPage = lazyPage(operations, 'MaintenanceAdminPage')
const InventoryAdminPage = lazyPage(posInventory, 'InventoryAdminPage')
const PosAdminPage = lazyPage(posInventory, 'PosAdminPage')
const ProcurementAdminPage = lazyPage(posInventory, 'ProcurementAdminPage')
const AnalyticsAdminPage = lazyPage(accountingAnalytics, 'AnalyticsAdminPage')
const FinanceAdminPage = lazyPage(accountingAnalytics, 'FinanceAdminPage')
const NightAuditAdminPage = lazyPage(accountingAnalytics, 'NightAuditAdminPage')
const AboutPage = lazyPage(publicPages, 'AboutPage')
const BookingPage = lazyPage(() => import('@/pages/booking'), 'BookingPage')
const CancellationPolicyPage = lazyPage(publicPages, 'CancellationPolicyPage')
const CmsGenericPage = lazyPage(publicPages, 'CmsGenericPage')
const ContactPage = lazyPage(() => import('@/pages/contact'), 'ContactPage')
const EventsPage = lazyPage(publicPages, 'EventsPage')
const FacilitiesPage = lazyPage(publicPages, 'FacilitiesPage')
const GalleryPage = lazyPage(publicPages, 'GalleryPage')
const HomePage = lazyPage(publicPages, 'HomePage')
const OfferDetailPage = lazyPage(publicPages, 'OfferDetailPage')
const OffersPage = lazyPage(publicPages, 'OffersPage')
const PrivacyPolicyPage = lazyPage(publicPages, 'PrivacyPolicyPage')
const PublicLayout = lazyPage(publicPages, 'PublicLayout')
const RestaurantPage = lazyPage(publicPages, 'RestaurantPage')
const RoomDetailPage = lazyPage(publicPages, 'RoomDetailPage')
const RoomsPage = lazyPage(publicPages, 'RoomsPage')
const TermsPage = lazyPage(publicPages, 'TermsPage')

/** UI-level guard for UX only. The API enforces the same permissions server-side. */
function Can({ permissions, children }: { permissions: string[]; children: ReactElement }) {
  const { can } = useAuth()
  return can(...permissions) ? children : <AccessDeniedPage />
}

const implementedAdmin: Record<string, ReactElement> = {
  '/admin': <DashboardPage />,
  '/admin/hr': <HumanResourcesPage />,
  '/admin/website/inquiries': <ContactInboxPage />,
  '/admin/website/pages': <CmsPagesPage />,
  '/admin/website/media': <CmsMediaPage />,
  '/admin/website/navigation': <CmsMenusPage />,
  '/admin/website/rooms': <CmsRoomsPage />,
  '/admin/website/offers': <CmsOffersPage />,
  '/admin/website/testimonials': <CmsTestimonialsPage />,
  '/admin/website/settings': <CmsWebsiteSettingsPage />,
  '/admin/front-desk': <FrontDeskAdminPage />,
  '/admin/reservations': <ReservationsAdminPage />,
  '/admin/availability': <AvailabilityAdminPage />,
  '/admin/room-types': <RoomTypesAdminPage />,
  '/admin/rooms': <RoomsAdminPage />,
  '/admin/rate-plans': <RatePlansAdminPage />,
  '/admin/folios': <FoliosAdminPage />,
  '/admin/payments': <PaymentsAdminPage />,
  '/admin/guests': <GuestsAdminPage />,
  '/admin/reservation-settings': <ReservationSettingsAdminPage />,
  '/admin/housekeeping': <HousekeepingAdminPage />,
  '/admin/maintenance': <MaintenanceAdminPage />,
  '/admin/pos': <PosAdminPage />,
  '/admin/inventory': <InventoryAdminPage />,
  '/admin/procurement': <ProcurementAdminPage />,
  '/admin/finance': <FinanceAdminPage />,
  '/admin/night-audit': <NightAuditAdminPage />,
  '/admin/reports': <AnalyticsAdminPage />,
  '/admin/users': <UsersPage />,
  '/admin/roles': <RolesPage />,
  '/admin/audit-logs': <AuditLogsPage />,
  '/admin/settings': <SettingsPage />,
}

export default function App() {
  return (
    <BrowserRouter>
      <Suspense fallback={<div className="flex min-h-screen items-center justify-center text-sm text-slate-600">Loading…</div>}>
      <Routes>
        {/* Public Hotel Website */}
        <Route element={<PublicLayout />}>
          <Route path="/" element={<HomePage />} />
          <Route path="/about" element={<AboutPage />} />
          <Route path="/rooms" element={<RoomsPage />} />
          <Route path="/rooms/:slug" element={<RoomDetailPage />} />
          <Route path="/booking" element={<BookingPage />} />
          <Route path="/facilities" element={<FacilitiesPage />} />
          <Route path="/restaurant" element={<RestaurantPage />} />
          <Route path="/gallery" element={<GalleryPage />} />
          <Route path="/offers" element={<OffersPage />} />
          <Route path="/offers/:slug" element={<OfferDetailPage />} />
          <Route path="/events" element={<EventsPage />} />
          <Route path="/contact" element={<ContactPage />} />
          <Route path="/privacy-policy" element={<PrivacyPolicyPage />} />
          <Route path="/terms-and-conditions" element={<TermsPage />} />
          <Route path="/cancellation-policy" element={<CancellationPolicyPage />} />
          <Route path="/p/:slug" element={<CmsGenericPage />} />
        </Route>

        {/* Staff Authentication */}
        <Route path="/admin/login" element={<LoginPage />} />
        <Route path="/admin/forgot-password" element={<ForgotPasswordPage />} />
        <Route path="/admin/reset-password" element={<ResetPasswordPage />} />

        {/* Protected Staff Administration Portal */}
        <Route element={<RequireAuth />}>
          <Route element={<AdminLayout />}>
            {allNavItems.map((item) => (
              <Route
                key={item.path}
                path={item.path}
                element={
                  <Can permissions={item.permissions}>
                    {implementedAdmin[item.path] ?? <ComingSoonPage title={item.label} group={item.group} />}
                  </Can>
                }
              />
            ))}
            <Route path="/admin/profile" element={<ProfilePage />} />
            <Route path="/admin/access-denied" element={<AccessDeniedPage />} />
            <Route path="/admin/*" element={<NotFoundPage />} />
          </Route>
        </Route>

        {/* Public 404 */}
        <Route element={<PublicLayout />}>
          <Route path="*" element={<NotFoundPage />} />
        </Route>
      </Routes>
      </Suspense>
    </BrowserRouter>
  )
}
