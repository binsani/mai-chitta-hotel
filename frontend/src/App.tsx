import type { ReactElement } from 'react'
import { BrowserRouter, Route, Routes } from 'react-router-dom'
import { useAuth } from '@/auth/AuthProvider'
import { AdminLayout, RequireAuth } from '@/layouts/AdminLayout'
import { allNavItems } from '@/lib/navigation'
import { ForgotPasswordPage, LoginPage, ResetPasswordPage } from '@/pages/auth'
import { AccessDeniedPage, ComingSoonPage, DashboardPage, NotFoundPage, ProfilePage } from '@/pages/general'
import { AuditLogsPage, RolesPage, SettingsPage, UsersPage } from '@/pages/admin'
import {
  CmsMediaPage, CmsMenusPage, CmsOffersPage, CmsPagesPage,
  CmsRoomsPage, CmsTestimonialsPage, CmsWebsiteSettingsPage,
} from '@/pages/cms'
import {
  AvailabilityAdminPage, FrontDeskAdminPage, GuestsAdminPage, PaymentsAdminPage,
  RatePlansAdminPage, ReservationSettingsAdminPage, ReservationsAdminPage,
  RoomsAdminPage, RoomTypesAdminPage,
} from '@/pages/reservations'
import {
  FoliosAdminPage, HousekeepingAdminPage, MaintenanceAdminPage,
} from '@/pages/operations'
import {
  InventoryAdminPage, PosAdminPage, ProcurementAdminPage,
} from '@/pages/pos_inventory'
import {
  AnalyticsAdminPage, FinanceAdminPage, NightAuditAdminPage,
} from '@/pages/accounting_analytics'
import {
  AboutPage, BookingPage, CancellationPolicyPage, CmsGenericPage, ContactPage,
  EventsPage, FacilitiesPage, GalleryPage, HomePage, OfferDetailPage, OffersPage,
  PrivacyPolicyPage, PublicLayout, RestaurantPage, RoomDetailPage, RoomsPage, TermsPage,
} from '@/pages/public'

/** UI-level guard for UX only. The API enforces the same permissions server-side. */
function Can({ permissions, children }: { permissions: string[]; children: ReactElement }) {
  const { can } = useAuth()
  return can(...permissions) ? children : <AccessDeniedPage />
}

const implementedAdmin: Record<string, ReactElement> = {
  '/admin': <DashboardPage />,
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
        <Route path="*" element={<PublicLayout />} />
      </Routes>
    </BrowserRouter>
  )
}
