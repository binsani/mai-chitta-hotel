import { createContext, useContext, useState } from 'react'
import { Link, NavLink, Outlet, useParams, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import {
  ArrowRight, BedDouble, Calendar, CheckCircle2, ChevronRight, Clock,
  Hotel, Mail, MapPin, Menu, Phone, ShieldCheck,
  Sparkles, Tag, Utensils, X, Zap,
} from 'lucide-react'
import { api, csrf, errorMessage, type Paginated } from '@/lib/api'
import {
  formatCurrency, sanitizeHtml, useSeo,
  type AvailabilitySearchOption, type BookingQuote, type MediaAssetItem, type OfferItem,
  type PageItem, type PageSectionItem, type PublicBootstrap, type ReservationRecord, type RoomContentItem,
} from '@/lib/cms'
import { formatDateTime, zodResolver } from '@/lib/utils'
import { Alert, Badge, Button, Card, EmptyState, ErrorState, FormField, Input, Modal, Spinner, Textarea } from '@/components/ui'
import { useAuth } from '@/auth/AuthProvider'

const BootstrapCtx = createContext<PublicBootstrap | null>(null)
function usePublicBootstrap() {
  return useContext(BootstrapCtx)
}

/* ===================== Public Layout ===================== */
export function PublicLayout() {
  const { user } = useAuth()
  const [mobileMenu, setMobileMenu] = useState(false)
  const q = useQuery({
    queryKey: ['public', 'bootstrap'],
    queryFn: async () => (await api.get<{ data: PublicBootstrap }>('/public/bootstrap')).data.data,
  })

  if (q.isLoading) return <div className="min-h-screen bg-brand-50/40"><Spinner label="Loading Mai Chitta Hotel…" /></div>
  if (q.isError || !q.data) return <div className="mx-auto max-w-2xl py-16"><ErrorState message={errorMessage(q.error)} onRetry={() => q.refetch()} /></div>

  const { settings, menus } = q.data
  const { hotel, website, booking } = settings
  const locationText = [hotel.city, hotel.state, hotel.country].filter(Boolean).join(', ')

  const headerItems = Array.isArray(menus?.header) ? menus.header : []
  const footerItems = Array.isArray(menus?.footer) ? menus.footer : []

  if (website.maintenance_mode && !user) {
    return (
      <div className="flex min-h-screen flex-col items-center justify-center bg-brand-900 px-4 text-center text-white">
        <Hotel className="mb-4 h-12 w-12 text-brand-200" aria-hidden />
        <h1 className="text-2xl font-semibold">{hotel.name}</h1>
        <p className="mt-1 text-sm text-brand-200">{locationText}</p>
        <p className="mt-4 max-w-md text-brand-100/90">
          Our website is currently undergoing scheduled updates. Please check back shortly.
        </p>
        <Link to="/admin/login" className="mt-8 text-xs text-brand-200/70 hover:underline">Staff Sign In</Link>
      </div>
    )
  }

  return (
    <BootstrapCtx.Provider value={q.data}>
      <div className="flex min-h-screen flex-col bg-slate-50 text-slate-900">
        <a href="#public-main" className="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded focus:bg-white focus:px-4 focus:py-2 focus:shadow">
          Skip to main content
        </a>

        {website.maintenance_mode && user && (
          <div className="bg-amber-500 px-4 py-1.5 text-center text-xs font-medium text-slate-950">
            Maintenance mode is active. Visitors see a maintenance notice; you can view the site because you are signed in ({user.name}).
          </div>
        )}

        {/* Top info bar */}
        <div className="bg-brand-900 px-4 py-2 text-xs text-brand-100/90">
          <div className="mx-auto flex max-w-7xl flex-wrap items-center justify-between gap-2">
            <div className="flex flex-wrap items-center gap-4">
              <span className="inline-flex items-center gap-1.5">
                <MapPin className="h-3.5 w-3.5 text-brand-200" aria-hidden />
                {hotel.address ? `${hotel.address}, ${locationText}` : locationText}
              </span>
              {hotel.phone && (
                <a href={`tel:${hotel.phone}`} className="inline-flex items-center gap-1.5 hover:text-white">
                  <Phone className="h-3.5 w-3.5 text-brand-200" aria-hidden /> {hotel.phone}
                </a>
              )}
              {hotel.email && (
                <a href={`mailto:${hotel.email}`} className="inline-flex items-center gap-1.5 hover:text-white">
                  <Mail className="h-3.5 w-3.5 text-brand-200" aria-hidden /> {hotel.email}
                </a>
              )}
            </div>
            <div className="flex items-center gap-4">
              <span>Check-in {booking.check_in_time} · Check-out {booking.check_out_time}</span>
              <span>Currency: {hotel.currency}</span>
              {user ? (
                <Link to="/admin" className="font-medium text-brand-200 hover:underline">Admin Dashboard →</Link>
              ) : (
                <Link to="/admin/login" className="text-brand-200/80 hover:text-white">Staff Portal</Link>
              )}
            </div>
          </div>
        </div>

        {/* Primary header navigation */}
        <header className="sticky top-0 z-30 border-b border-sand-200/80 bg-sand-50/95 backdrop-blur-md transition-shadow">
          <div className="mx-auto flex h-20 max-w-7xl items-center justify-between gap-4 px-4 sm:px-6 lg:px-8">
            <Link to="/" className="flex items-center gap-3 group">
              <img src="/logo.jpg" alt="M&C Hotel Logo" className="h-11 w-11 rounded-lg object-cover shadow-sm ring-1 ring-sand-300 transition-transform group-hover:scale-105" />
              <div>
                <span className="font-serif text-2xl font-bold tracking-tight text-slate-900 group-hover:text-amber-800 transition-colors">{hotel.name}</span>
                <span className="block text-[11px] font-medium tracking-wider uppercase text-amber-900/70">{website.tagline || locationText}</span>
              </div>
            </Link>

            <nav aria-label="Primary website navigation" className="hidden items-center gap-2 lg:flex">
              {headerItems.map((item, idx) => (
                <NavLink
                  key={`${item.url}-${idx}`}
                  to={item.url}
                  target={item.target}
                  end={item.url === '/'}
                  className={({ isActive }) =>
                    `rounded-full px-4 py-1.5 text-sm font-medium transition-all ${
                      isActive
                        ? 'bg-amber-900/10 text-amber-900 font-semibold'
                        : 'text-slate-700 hover:text-amber-900 hover:bg-sand-200/50'
                    }`
                  }
                >
                  {item.label}
                </NavLink>
              ))}
            </nav>

            <div className="flex items-center gap-3">
              <Link
                to="/booking"
                className="hidden sm:inline-flex items-center justify-center rounded-full bg-emerald-900 px-5 py-2 text-sm font-medium text-white shadow-sm hover:bg-emerald-800 transition-colors"
              >
                Book a Stay
              </Link>
              <button
                type="button"
                className="rounded-lg p-2 text-slate-700 hover:bg-sand-200/70 lg:hidden"
                aria-label={mobileMenu ? 'Close navigation menu' : 'Open navigation menu'}
                aria-expanded={mobileMenu}
                onClick={() => setMobileMenu((v) => !v)}
              >
                {mobileMenu ? <X className="h-6 w-6" /> : <Menu className="h-6 w-6" />}
              </button>
            </div>
          </div>

          {mobileMenu && (
            <nav aria-label="Mobile website navigation" className="border-t border-sand-200 bg-sand-50 px-4 py-4 lg:hidden">
              <ul className="space-y-1.5">
                {headerItems.map((item, idx) => (
                  <li key={`${item.url}-${idx}`}>
                    <NavLink
                      to={item.url}
                      target={item.target}
                      end={item.url === '/'}
                      onClick={() => setMobileMenu(false)}
                      className={({ isActive }) =>
                        `block rounded-lg px-3 py-2 text-sm font-medium ${
                          isActive ? 'bg-amber-900/10 text-amber-900 font-semibold' : 'text-slate-700 hover:bg-sand-200/60'
                        }`
                      }
                    >
                      {item.label}
                    </NavLink>
                  </li>
                ))}
                <li className="pt-2">
                  <Link
                    to="/booking"
                    onClick={() => setMobileMenu(false)}
                    className="block text-center rounded-full bg-emerald-900 px-4 py-2 text-sm font-medium text-white shadow-sm"
                  >
                    Book a Stay
                  </Link>
                </li>
              </ul>
            </nav>
          )}
        </header>

        <main id="public-main" className="flex-1">
          <Outlet />
        </main>

        {/* Refined Deep Navy & Warm Gold Footer */}
        <footer className="border-t border-slate-900 bg-[#0d1520] text-slate-300">
          <div className="mx-auto grid max-w-7xl gap-10 px-4 py-16 sm:px-6 lg:px-8 md:grid-cols-4">
            <div className="md:col-span-1">
              <div className="flex items-center gap-3">
                <img src="/logo.jpg" alt="M&C Hotel Logo" className="h-11 w-11 rounded-lg object-cover shadow-sm ring-1 ring-white/10" />
                <span className="font-serif text-xl font-bold text-white tracking-wide">{hotel.name}</span>
              </div>
              <p className="mt-4 text-xs leading-relaxed text-slate-400">
                Northern hospitality, refined. A warm sanctuary in the historic city of Zaria, Kaduna State, Nigeria.
              </p>
            </div>

            <div>
              <h2 className="text-xs font-semibold uppercase tracking-[0.2em] text-amber-300/80">Explore</h2>
              <ul className="mt-4 space-y-2.5 text-xs">
                {footerItems.map((item, idx) => (
                  <li key={`${item.url}-${idx}`}>
                    <Link to={item.url} target={item.target} className="text-slate-400 hover:text-white transition-colors">
                      {item.label}
                    </Link>
                  </li>
                ))}
              </ul>
            </div>

            <div>
              <h2 className="text-xs font-semibold uppercase tracking-[0.2em] text-amber-300/80">Reach Us</h2>
              <dl className="mt-4 space-y-2.5 text-xs text-slate-400">
                {hotel.phone && (
                  <div>
                    <dt className="text-slate-500 uppercase text-[10px]">Phone</dt>
                    <dd><a href={`tel:${hotel.phone}`} className="hover:text-white transition-colors">{hotel.phone}</a></dd>
                  </div>
                )}
                {hotel.email && (
                  <div className="pt-1">
                    <dt className="text-slate-500 uppercase text-[10px]">Email</dt>
                    <dd><a href={`mailto:${hotel.email}`} className="hover:text-white transition-colors">{hotel.email}</a></dd>
                  </div>
                )}
                <div className="pt-1">
                  <dt className="text-slate-500 uppercase text-[10px]">Address</dt>
                  <dd>{hotel.address ? `${hotel.address}, ${locationText}` : locationText}</dd>
                </div>
              </dl>
            </div>

            <div>
              <h2 className="text-xs font-semibold uppercase tracking-[0.2em] text-amber-300/80">Team</h2>
              <div className="mt-4">
                <Link
                  to="/admin/login"
                  className="inline-flex items-center justify-center rounded-lg border border-slate-700 bg-slate-900/60 px-4 py-2 text-xs font-medium text-slate-300 hover:border-amber-400/60 hover:text-white transition-colors"
                >
                  Staff Portal Sign In →
                </Link>
                <p className="mt-3 text-[11px] text-slate-500">
                  Front desk, housekeeping, maintenance and administrative access.
                </p>
              </div>
            </div>
          </div>

          <div className="border-t border-slate-800/80 px-4 py-6 text-center text-xs text-slate-500">
            © {new Date().getFullYear()} {hotel.name} (Zaria, Kaduna State, Nigeria). All rights reserved.
          </div>
        </footer>
      </div>
    </BootstrapCtx.Provider>
  )
}

/* ===================== Reusable Public Components ===================== */
export function PageBanner({ title, subtitle, media }: { title: string; subtitle?: string | null; media?: MediaAssetItem | null }) {
  return (
    <section className="relative overflow-hidden bg-brand-900 py-14 text-white sm:py-20">
      {media && (
        <img
          src={media.url}
          alt={media.alt_text}
          className="absolute inset-0 h-full w-full object-cover opacity-25"
          loading="eager"
        />
      )}
      <div className="relative mx-auto max-w-7xl px-4 sm:px-6">
        <h1 className="text-3xl font-bold tracking-tight sm:text-4xl">{title}</h1>
        {subtitle && <p className="mt-2 max-w-2xl text-base text-brand-100 sm:text-lg">{subtitle}</p>}
      </div>
    </section>
  )
}

export function SafeHtml({ html, className = '' }: { html?: string | null; className?: string }) {
  const clean = sanitizeHtml(html)
  if (!clean) return null
  return (
    <div
      className={`prose prose-slate max-w-none space-y-3 text-slate-700 [&_a]:text-brand-700 [&_a]:underline [&_h2]:mt-6 [&_h2]:text-xl [&_h2]:font-semibold [&_h2]:text-slate-900 [&_h3]:mt-4 [&_h3]:text-lg [&_h3]:font-semibold [&_ul]:list-disc [&_ul]:pl-5 ${className}`}
      dangerouslySetInnerHTML={{ __html: clean }}
    />
  )
}

export function PageSectionRenderer({ section }: { section: PageSectionItem }) {
  if (!section.is_visible) return null

  switch (section.type) {
    case 'hero':
      return (
        <section className="relative overflow-hidden rounded-xl bg-brand-900 px-6 py-14 text-white sm:px-12">
          {section.media && (
            <img src={section.media.url} alt={section.media.alt_text} className="absolute inset-0 h-full w-full object-cover opacity-30" loading="lazy" />
          )}
          <div className="relative max-w-2xl">
            {section.title && <h2 className="text-2xl font-bold sm:text-3xl">{section.title}</h2>}
            {section.subtitle && <p className="mt-2 text-brand-100">{section.subtitle}</p>}
            {section.body && <SafeHtml html={section.body} className="mt-3 text-brand-50" />}
            {section.cta_label && section.cta_url && (
              <div className="mt-6">
                <Button asChild><Link to={section.cta_url}>{section.cta_label}</Link></Button>
              </div>
            )}
          </div>
        </section>
      )

    case 'image_text':
      return (
        <section className="grid items-center gap-8 rounded-xl border border-slate-200 bg-white p-6 shadow-sm sm:p-8 lg:grid-cols-2">
          {section.media && (
            <figure className="overflow-hidden rounded-lg bg-slate-100">
              <img src={section.media.url} alt={section.media.alt_text} className="h-64 w-full object-cover sm:h-80" loading="lazy" />
              {section.media.caption && <figcaption className="px-3 py-2 text-xs text-slate-500">{section.media.caption}</figcaption>}
            </figure>
          )}
          <div>
            {section.subtitle && <p className="text-xs font-semibold uppercase tracking-wider text-brand-700">{section.subtitle}</p>}
            {section.title && <h2 className="mt-1 text-2xl font-bold text-slate-900">{section.title}</h2>}
            {section.body && <SafeHtml html={section.body} className="mt-3" />}
            {section.cta_label && section.cta_url && (
              <div className="mt-5">
                <Button asChild variant="secondary">
                  <Link to={section.cta_url}>{section.cta_label} <ArrowRight className="h-4 w-4" /></Link>
                </Button>
              </div>
            )}
          </div>
        </section>
      )

    case 'feature_grid': {
      const items = section.data?.items ?? []
      return (
        <section className="space-y-6">
          <div>
            {section.title && <h2 className="text-2xl font-bold text-slate-900">{section.title}</h2>}
            {section.subtitle && <p className="mt-1 text-sm text-slate-600">{section.subtitle}</p>}
          </div>
          {section.body && <SafeHtml html={section.body} />}
          {items.length > 0 && (
            <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
              {items.map((it, idx) => (
                <Card key={idx} className="p-6">
                  <Sparkles className="mb-3 h-5 w-5 text-brand-700" aria-hidden />
                  <h3 className="font-semibold text-slate-900">{it.title}</h3>
                  {it.description && <p className="mt-1.5 text-sm text-slate-600">{it.description}</p>}
                </Card>
              ))}
            </div>
          )}
        </section>
      )
    }

    case 'faq': {
      const items = section.data?.items ?? []
      return (
        <section className="space-y-4">
          {section.title && <h2 className="text-2xl font-bold text-slate-900">{section.title}</h2>}
          <div className="divide-y divide-slate-200 rounded-lg border border-slate-200 bg-white">
            {items.map((it, idx) => (
              <div key={idx} className="p-5">
                <h3 className="font-semibold text-slate-900">{it.question || it.title}</h3>
                <p className="mt-1 text-sm text-slate-600">{it.answer || it.description}</p>
              </div>
            ))}
          </div>
        </section>
      )
    }

    case 'cta_banner':
      return (
        <section className="flex flex-wrap items-center justify-between gap-6 rounded-xl bg-brand-800 px-6 py-8 text-white sm:px-10">
          <div>
            {section.title && <h2 className="text-xl font-bold">{section.title}</h2>}
            {section.subtitle && <p className="mt-1 text-sm text-brand-100">{section.subtitle}</p>}
            {section.body && <SafeHtml html={section.body} className="mt-2 text-brand-50" />}
          </div>
          {section.cta_label && section.cta_url && (
            <Button asChild variant="secondary">
              <Link to={section.cta_url}>{section.cta_label}</Link>
            </Button>
          )}
        </section>
      )

    case 'notice':
      return (
        <Alert variant="info" title={section.title ?? undefined}>
          <SafeHtml html={section.body} />
        </Alert>
      )

    default:
      return (
        <Card className="p-6 sm:p-8">
          {section.title && <h2 className="text-xl font-bold text-slate-900">{section.title}</h2>}
          {section.subtitle && <p className="mt-1 text-sm text-slate-500">{section.subtitle}</p>}
          {section.body && <SafeHtml html={section.body} className="mt-3" />}
        </Card>
      )
  }
}

export function RoomCard({ room, currency = 'NGN' }: { room: RoomContentItem; currency?: string }) {
  return (
    <Card className="flex flex-col overflow-hidden">
      {room.featured_media ? (
        <img src={room.featured_media.url} alt={room.featured_media.alt_text} className="h-52 w-full object-cover" loading="lazy" />
      ) : (
        <div className="flex h-52 items-center justify-center bg-brand-100 text-brand-800">
          <BedDouble className="h-10 w-10" aria-hidden />
        </div>
      )}
      <div className="flex flex-1 flex-col p-5">
        <div className="flex items-start justify-between gap-2">
          <h3 className="text-lg font-semibold text-slate-900">{room.title}</h3>
          <Badge tone="brand">{formatCurrency(room.indicative_rate, currency)}</Badge>
        </div>
        {room.subtitle && <p className="mt-0.5 text-xs text-slate-500">{room.subtitle}</p>}
        {room.summary && <p className="mt-2 flex-1 text-sm text-slate-600">{room.summary}</p>}
        <div className="mt-3 flex flex-wrap gap-1.5 text-xs text-slate-500">
          {room.occupancy_label && <Badge>{room.occupancy_label}</Badge>}
          {room.bed_configuration && <Badge>{room.bed_configuration}</Badge>}
        </div>
        <div className="mt-4 pt-2">
          <Button asChild variant="secondary" size="sm" className="w-full">
            <Link to={`/rooms/${room.slug}`}>View Details <ArrowRight className="h-3.5 w-3.5" /></Link>
          </Button>
        </div>
      </div>
    </Card>
  )
}

export function OfferCard({ offer }: { offer: OfferItem }) {
  return (
    <Card className="flex flex-col overflow-hidden">
      {offer.featured_media && (
        <img src={offer.featured_media.url} alt={offer.featured_media.alt_text} className="h-48 w-full object-cover" loading="lazy" />
      )}
      <div className="flex flex-1 flex-col p-5">
        <div className="flex items-center gap-2">
          {offer.badge_text && <Badge tone="brand">{offer.badge_text}</Badge>}
          {offer.ends_at && <span className="text-xs text-slate-500">Valid until {formatDateTime(offer.ends_at)}</span>}
        </div>
        <h3 className="mt-2 text-lg font-semibold text-slate-900">{offer.title}</h3>
        {offer.subtitle && <p className="text-xs text-slate-500">{offer.subtitle}</p>}
        {offer.summary && <p className="mt-2 flex-1 text-sm text-slate-600">{offer.summary}</p>}
        <div className="mt-4">
          <Button asChild variant="secondary" size="sm">
            <Link to={`/offers/${offer.slug}`}>{offer.cta_label || 'View Offer'}</Link>
          </Button>
        </div>
      </div>
    </Card>
  )
}

/* ===================== CMS-Driven Standard Page Hook & Wrapper ===================== */
function usePublicPage(slug: string) {
  return useQuery({
    queryKey: ['public', 'page', slug],
    queryFn: async () => (await api.get<{ data: PageItem }>(`/pages/${slug}`)).data.data,
  })
}

function CmsPageView({ slug, children }: { slug: string; children?: React.ReactNode }) {
  const boot = usePublicBootstrap()
  const q = usePublicPage(slug)
  const hotelName = boot?.settings.hotel.name ?? 'Mai Chitta Hotel'

  useSeo({
    title: q.data ? (q.data.seo_title || `${q.data.title} | ${hotelName}`) : hotelName,
    description: q.data?.seo_description || q.data?.excerpt || boot?.settings.website.seo_default_description,
    canonical: q.data?.canonical_url,
    noindex: q.data?.noindex,
  })

  if (q.isLoading) return <Spinner />
  if (q.isError || !q.data) return <div className="mx-auto max-w-4xl px-4 py-12"><ErrorState message={errorMessage(q.error, 'Page not found.')} onRetry={() => q.refetch()} /></div>

  const page = q.data
  return (
    <div>
      <PageBanner title={page.title} subtitle={page.subtitle} media={page.featured_media} />
      <div className="mx-auto max-w-7xl space-y-10 px-4 py-10 sm:px-6 sm:py-14">
        {page.body && (
          <Card className="p-6 sm:p-8">
            <SafeHtml html={page.body} />
          </Card>
        )}
        {children}
        {page.sections?.map((sec, idx) => (
          <PageSectionRenderer key={sec.id ?? idx} section={sec} />
        ))}
      </div>
    </div>
  )
}

/* ===================== 14 Public Website Pages ===================== */
export function HomePage() {
  const boot = usePublicBootstrap()
  const q = usePublicPage('home')
  const website = boot?.settings.website
  const hotel = boot?.settings.hotel
  const locationText = [hotel?.city, hotel?.state, hotel?.country].filter(Boolean).join(', ')

  useSeo({
    title: q.data?.seo_title || website?.seo_default_title || 'Mai Chitta Hotel — Zaria, Kaduna State, Nigeria',
    description: q.data?.seo_description || website?.seo_default_description,
    canonical: q.data?.canonical_url,
    noindex: q.data?.noindex,
  })

  // Quick booking bar state
  const [checkIn, setCheckIn] = useState('')
  const [checkOut, setCheckOut] = useState('')
  const [adults, setAdults] = useState('1')
  const [roomType, setRoomType] = useState('all')

  if (q.isLoading) return <Spinner />
  const page = q.data
  const heroMedia = boot?.hero_media ?? page?.featured_media

  return (
    <div className="bg-sand-50">
      {/* 1. Refined Luxury Hero Section */}
      <section className="relative min-h-[82vh] flex items-center justify-center overflow-hidden bg-slate-950 text-white">
        {heroMedia ? (
          <img
            src={heroMedia.url}
            alt={heroMedia.alt_text || 'Mai Chitta Hotel Exterior'}
            className="absolute inset-0 h-full w-full object-cover object-center opacity-45 scale-105 transition-transform duration-1000"
            loading="eager"
          />
        ) : (
          <div className="absolute inset-0 bg-gradient-to-br from-slate-950 via-brand-900 to-amber-950 opacity-90" />
        )}
        {/* Subtle Dark Vignette Gradient */}
        <div className="absolute inset-0 bg-gradient-to-t from-slate-950/90 via-slate-950/40 to-slate-950/60" />

        <div className="relative mx-auto max-w-5xl px-4 py-24 sm:px-6 lg:px-8 text-center flex flex-col items-center">
          <span className="inline-block text-xs uppercase tracking-[0.25em] font-semibold text-amber-300/90 mb-4">
            WHERE TO STAY · ZARIA, KADUNA STATE
          </span>
          <h1 className="font-serif text-4xl sm:text-6xl md:text-7xl font-normal tracking-tight text-white max-w-4xl leading-[1.1]">
            Northern hospitality, <br />
            <span className="italic font-light text-amber-200">refined.</span>
          </h1>
          <p className="mt-6 max-w-2xl text-base sm:text-lg text-slate-200/90 font-light leading-relaxed">
            {website?.hero_subtitle || 'Rest, dine and celebrate at Mai Chitta — a warm sanctuary in the heart of historic Zaria.'}
          </p>

          {/* Quick Booking Bar Floating On Hero */}
          <div className="mt-10 w-full max-w-4xl rounded-2xl bg-white/95 p-3 sm:p-4 text-slate-900 shadow-2xl backdrop-blur-md ring-1 ring-black/5">
            <form
              onSubmit={(e) => {
                e.preventDefault()
                const params = new URLSearchParams()
                if (checkIn) params.set('check_in', checkIn)
                if (checkOut) params.set('check_out', checkOut)
                if (adults) params.set('adults', adults)
                window.location.href = `/booking?${params.toString()}`
              }}
              className="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-5 text-left"
            >
              <div className="rounded-xl border border-slate-200/90 bg-white px-3 py-2">
                <label className="block text-[11px] font-semibold uppercase tracking-wider text-slate-500">Check-in</label>
                <input
                  type="date"
                  value={checkIn}
                  onChange={(e) => setCheckIn(e.target.value)}
                  className="w-full bg-transparent text-sm font-medium text-slate-900 focus:outline-none"
                />
              </div>

              <div className="rounded-xl border border-slate-200/90 bg-white px-3 py-2">
                <label className="block text-[11px] font-semibold uppercase tracking-wider text-slate-500">Check-out</label>
                <input
                  type="date"
                  value={checkOut}
                  onChange={(e) => setCheckOut(e.target.value)}
                  className="w-full bg-transparent text-sm font-medium text-slate-900 focus:outline-none"
                />
              </div>

              <div className="rounded-xl border border-slate-200/90 bg-white px-3 py-2">
                <label className="block text-[11px] font-semibold uppercase tracking-wider text-slate-500">Guests</label>
                <select
                  value={adults}
                  onChange={(e) => setAdults(e.target.value)}
                  className="w-full bg-transparent text-sm font-medium text-slate-900 focus:outline-none"
                >
                  <option value="1">1 Guest</option>
                  <option value="2">2 Guests</option>
                  <option value="3">3 Guests</option>
                  <option value="4">4+ Guests</option>
                </select>
              </div>

              <div className="rounded-xl border border-slate-200/90 bg-white px-3 py-2">
                <label className="block text-[11px] font-semibold uppercase tracking-wider text-slate-500">Room Type</label>
                <select
                  value={roomType}
                  onChange={(e) => setRoomType(e.target.value)}
                  className="w-full bg-transparent text-sm font-medium text-slate-900 focus:outline-none"
                >
                  <option value="all">Any Available Room</option>
                  <option value="standard">Standard Room</option>
                  <option value="deluxe">Deluxe Room</option>
                  <option value="suite">Executive Suite</option>
                  <option value="presidential">Presidential Suite</option>
                </select>
              </div>

              <button
                type="submit"
                className="flex items-center justify-center rounded-xl bg-amber-700 px-6 py-3 text-sm font-semibold text-white shadow-md hover:bg-amber-800 transition-colors"
              >
                Book a Room
              </button>
            </form>
          </div>
        </div>
      </section>

      {/* 2. Key Hotel Highlights ("Comfort you can count on") */}
      <section className="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
        <div className="text-center sm:text-left mb-10">
          <span className="text-xs uppercase tracking-[0.2em] font-semibold text-amber-800">WHY GUESTS CHOOSE US</span>
          <h2 className="font-serif text-3xl sm:text-4xl font-normal text-slate-900 mt-2">
            Comfort you can count on
          </h2>
        </div>

        <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
          <div className="group rounded-2xl border border-sand-200 bg-white p-7 shadow-sm transition-all hover:shadow-md hover:border-amber-300">
            <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-amber-50 text-amber-700 group-hover:bg-amber-700 group-hover:text-white transition-colors">
              <Zap className="h-6 w-6" />
            </div>
            <h3 className="font-serif text-xl font-semibold text-slate-900 mt-5">24/7 Power</h3>
            <p className="mt-2 text-sm text-slate-600 leading-relaxed">
              Uninterrupted electricity, day and night, powered by dedicated hotel generators and solar backup.
            </p>
          </div>

          <div className="group rounded-2xl border border-sand-200 bg-white p-7 shadow-sm transition-all hover:shadow-md hover:border-amber-300">
            <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-amber-50 text-amber-700 group-hover:bg-amber-700 group-hover:text-white transition-colors">
              <ShieldCheck className="h-6 w-6" />
            </div>
            <h3 className="font-serif text-xl font-semibold text-slate-900 mt-5">Round-the-clock Security</h3>
            <p className="mt-2 text-sm text-slate-600 leading-relaxed">
              Trained security personnel, 24/7 CCTV surveillance, perimeter access control, and secure guest parking.
            </p>
          </div>

          <div className="group rounded-2xl border border-sand-200 bg-white p-7 shadow-sm transition-all hover:shadow-md hover:border-amber-300">
            <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-amber-50 text-amber-700 group-hover:bg-amber-700 group-hover:text-white transition-colors">
              <Utensils className="h-6 w-6" />
            </div>
            <h3 className="font-serif text-xl font-semibold text-slate-900 mt-5">Restaurant & Lounge</h3>
            <p className="mt-2 text-sm text-slate-600 leading-relaxed">
              Northern culinary specialties, continental cuisines, fresh breakfast, and room service on demand.
            </p>
          </div>

          <div className="group rounded-2xl border border-sand-200 bg-white p-7 shadow-sm transition-all hover:shadow-md hover:border-amber-300">
            <div className="flex h-12 w-12 items-center justify-center rounded-xl bg-amber-50 text-amber-700 group-hover:bg-amber-700 group-hover:text-white transition-colors">
              <Sparkles className="h-6 w-6" />
            </div>
            <h3 className="font-serif text-xl font-semibold text-slate-900 mt-5">Event & Banquet Hall</h3>
            <p className="mt-2 text-sm text-slate-600 leading-relaxed">
              Weddings, corporate conferences, and celebrations hosted with premium audio-visual support.
            </p>
          </div>
        </div>
      </section>

      {/* 3. Rooms & Suites Showcase Section */}
      <section className="bg-sand-200/50 py-16 sm:py-20 border-y border-sand-200">
        <div className="mx-auto max-w-7xl px-4 sm:px-6 lg:px-8">
          <div className="flex flex-col sm:flex-row sm:items-end sm:justify-between gap-4 mb-10">
            <div>
              <span className="text-xs uppercase tracking-[0.2em] font-semibold text-amber-800">STAY</span>
              <h2 className="font-serif text-3xl sm:text-4xl font-normal text-slate-900 mt-2">
                Rooms & Suites
              </h2>
            </div>
            <Link to="/rooms" className="text-sm font-medium text-amber-800 hover:text-amber-900 underline underline-offset-4">
              View all rooms →
            </Link>
          </div>

          {/* Render Rooms Grid (Fallback or Real DB items) */}
          <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-4">
            {(boot?.featured_rooms && boot.featured_rooms.length > 0
              ? boot.featured_rooms.slice(0, 4)
              : [
                  {
                    id: 'std',
                    name: 'Standard Room',
                    slug: 'standard-room',
                    teaser: 'Serene guestroom with king bed, desk and ensuite bath.',
                    min_price_cents: 2500000,
                    featured_media: { url: '/hotel/lobby-monstera-planters.webp', alt_text: 'Standard Room' },
                  },
                  {
                    id: 'dlx',
                    name: 'Deluxe Room',
                    slug: 'deluxe-room',
                    teaser: 'Spacious sanctuary featuring lounge seating and city views.',
                    min_price_cents: 3500000,
                    featured_media: { url: '/hotel/reception-hallway-planters.webp', alt_text: 'Deluxe Room' },
                  },
                  {
                    id: 'exec',
                    name: 'Executive Suite',
                    slug: 'executive-suite',
                    teaser: 'Separate living room, dining area, and VIP amenities.',
                    min_price_cents: 6000000,
                    featured_media: { url: '/hotel/atrium-ceiling-light.webp', alt_text: 'Executive Suite' },
                  },
                  {
                    id: 'pres',
                    name: 'Presidential / Royal Suite',
                    slug: 'presidential-suite',
                    teaser: 'The pinnacle of northern hospitality with master bedroom and balcony.',
                    min_price_cents: 12000000,
                    featured_media: { url: '/hotel/corridor-art-gallery.webp', alt_text: 'Presidential Suite' },
                  },
                ]
            ).map((room: any) => (
              <div
                key={room.id}
                className="group flex flex-col overflow-hidden rounded-2xl bg-white shadow-sm ring-1 ring-sand-300/80 transition-all hover:shadow-lg hover:ring-amber-400/80"
              >
                <div className="relative aspect-[4/3] overflow-hidden bg-sand-200">
                  <img
                    src={room.featured_media?.url || '/hotel/lobby-monstera-planters.webp'}
                    alt={room.featured_media?.alt_text || room.name}
                    className="h-full w-full object-cover transition-transform duration-500 group-hover:scale-105"
                    loading="lazy"
                  />
                  <div className="absolute inset-0 bg-gradient-to-t from-black/40 via-transparent to-transparent" />
                </div>
                <div className="flex flex-1 flex-col p-5">
                  <h3 className="font-serif text-lg font-semibold text-slate-900 group-hover:text-amber-800 transition-colors">
                    {room.name}
                  </h3>
                  <p className="mt-1 flex-1 text-xs text-slate-600 line-clamp-2">
                    {room.teaser || room.summary}
                  </p>
                  <div className="mt-4 flex items-center justify-between border-t border-sand-100 pt-3">
                    <span className="text-xs text-slate-500">
                      From <span className="font-bold text-amber-800">{formatCurrency(room.min_price_cents ?? 2500000, hotel?.currency ?? 'NGN')}</span> / night
                    </span>
                    <Link
                      to={`/rooms/${room.slug}`}
                      className="text-xs font-semibold text-slate-800 hover:text-amber-800 flex items-center gap-1"
                    >
                      Details <ChevronRight className="h-3.5 w-3.5" />
                    </Link>
                  </div>
                </div>
              </div>
            ))}
          </div>
        </div>
      </section>

      {/* 4. Find Us / Location in Zaria */}
      <section className="mx-auto max-w-7xl px-4 py-16 sm:px-6 lg:px-8">
        <div className="mb-8">
          <span className="text-xs uppercase tracking-[0.2em] font-semibold text-amber-800">FIND US</span>
          <h2 className="font-serif text-3xl sm:text-4xl font-normal text-slate-900 mt-2">
            In the heart of Zaria
          </h2>
        </div>

        <div className="grid gap-8 lg:grid-cols-3">
          <div className="space-y-6 lg:col-span-1">
            <div className="rounded-2xl border border-sand-200 bg-white p-6 shadow-sm">
              <h3 className="font-serif text-xl font-semibold text-slate-900">Visiting Mai Chitta</h3>
              <p className="mt-2 text-sm text-slate-600">
                Conveniently situated in Zaria with quick access to Ahmadu Bello University, historic city landmarks, and Kaduna expressway.
              </p>

              <dl className="mt-6 space-y-4 text-sm">
                <div className="flex items-start gap-3">
                  <MapPin className="h-5 w-5 text-amber-700 shrink-0 mt-0.5" />
                  <div>
                    <dt className="font-medium text-slate-900">Address</dt>
                    <dd className="text-slate-600">{hotel?.address ? `${hotel.address}, ${locationText}` : locationText}</dd>
                  </div>
                </div>

                {hotel?.phone && (
                  <div className="flex items-start gap-3">
                    <Phone className="h-5 w-5 text-amber-700 shrink-0 mt-0.5" />
                    <div>
                      <dt className="font-medium text-slate-900">Phone</dt>
                      <dd><a href={`tel:${hotel.phone}`} className="text-amber-800 hover:underline">{hotel.phone}</a></dd>
                    </div>
                  </div>
                )}

                {hotel?.email && (
                  <div className="flex items-start gap-3">
                    <Mail className="h-5 w-5 text-amber-700 shrink-0 mt-0.5" />
                    <div>
                      <dt className="font-medium text-slate-900">Email</dt>
                      <dd><a href={`mailto:${hotel.email}`} className="text-amber-800 hover:underline">{hotel.email}</a></dd>
                    </div>
                  </div>
                )}
              </dl>
            </div>
          </div>

          <div className="overflow-hidden rounded-2xl border border-sand-200 bg-white shadow-sm lg:col-span-2">
            {/* Interactive Embedded Google Map for Zaria */}
            <iframe
              title="Mai Chitta Hotel Location"
              src="https://www.google.com/maps/embed?pb=!1m18!1m12!1m3!1d125345.2415176182!2d7.6322!3d11.0855!2m3!1f0!2f0!3f0!3m2!1i1024!2i768!4f13.1!3m3!1m2!1s0x11b27f549cf8a5f3%3A0xb35a09ad4a2f8d0!2sZaria%2C%20Kaduna!5e0!3m2!1sen!2sng!4v1700000000000!5m2!1sen!2sng"
              className="h-[360px] w-full border-0 sm:h-[420px]"
              loading="lazy"
              referrerPolicy="no-referrer-when-downgrade"
            />
          </div>
        </div>
      </section>

      {/* 5. Rich Editorial Body / Additional CMS Sections if present */}
      {page?.sections && page.sections.length > 0 && (
        <div className="mx-auto max-w-7xl px-4 pb-16 sm:px-6 lg:px-8 space-y-12">
          {page.sections.map((sec, idx) => (
            <PageSectionRenderer key={sec.id ?? idx} section={sec} />
          ))}
        </div>
      )}
    </div>
  )
}

export function AboutPage() {
  return <CmsPageView slug="about" />
}

export function RoomsPage() {
  const boot = usePublicBootstrap()
  const q = useQuery({
    queryKey: ['public', 'rooms'],
    queryFn: async () => (await api.get<{ data: RoomContentItem[] }>('/rooms')).data.data,
  })
  return (
    <CmsPageView slug="rooms">
      {q.isLoading ? <Spinner /> : q.isError ? <ErrorState message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : (
        q.data && q.data.length > 0 ? (
          <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            {q.data.map((r) => <RoomCard key={r.id} room={r} currency={boot?.settings.hotel.currency} />)}
          </div>
        ) : (
          <Card>
            <EmptyState
              icon={BedDouble}
              title="Room catalogue is being prepared"
              description="Verified room categories, photographs, and rates will appear here once published by hotel administration. Please contact our reception team for immediate accommodation inquiries."
              action={<Button asChild variant="secondary"><Link to="/contact">Inquire About Rooms</Link></Button>}
            />
          </Card>
        )
      )}
    </CmsPageView>
  )
}

export function RoomDetailPage() {
  const { slug = '' } = useParams()
  const boot = usePublicBootstrap()
  const hotelName = boot?.settings.hotel.name ?? 'Mai Chitta Hotel'
  const q = useQuery({
    queryKey: ['public', 'room', slug],
    queryFn: async () => (await api.get<{ data: RoomContentItem }>(`/rooms/${slug}`)).data.data,
  })

  useSeo({
    title: q.data ? (q.data.seo_title || `${q.data.title} | ${hotelName}`) : hotelName,
    description: q.data?.seo_description || q.data?.summary,
  })

  if (q.isLoading) return <Spinner />
  if (q.isError || !q.data) return <div className="mx-auto max-w-4xl px-4 py-12"><ErrorState message="Room category not found or not currently published." /></div>

  const room = q.data
  return (
    <div>
      <PageBanner title={room.title} subtitle={room.subtitle} media={room.featured_media} />
      <div className="mx-auto grid max-w-7xl gap-8 px-4 py-12 sm:px-6 lg:grid-cols-3">
        <div className="space-y-6 lg:col-span-2">
          {room.featured_media && (
            <figure className="overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
              <img src={room.featured_media.url} alt={room.featured_media.alt_text} className="max-h-96 w-full object-cover" />
              {room.featured_media.caption && <figcaption className="p-3 text-xs text-slate-500">{room.featured_media.caption}</figcaption>}
            </figure>
          )}
          <Card className="p-6 sm:p-8">
            <h2 className="text-xl font-semibold text-slate-900">Accommodation Overview</h2>
            {room.summary && <p className="mt-2 text-slate-600">{room.summary}</p>}
            {room.description && <SafeHtml html={room.description} className="mt-4" />}
          </Card>
          {room.amenities && room.amenities.length > 0 && (
            <Card className="p-6 sm:p-8">
              <h2 className="text-lg font-semibold text-slate-900">Room Amenities</h2>
              <ul className="mt-4 grid gap-2 sm:grid-cols-2">
                {room.amenities.map((a, idx) => (
                  <li key={idx} className="flex items-center gap-2 text-sm text-slate-700">
                    <CheckCircle2 className="h-4 w-4 text-emerald-600" aria-hidden /> {a}
                  </li>
                ))}
              </ul>
            </Card>
          )}
        </div>
        <div>
          <Card className="p-6">
            <p className="text-xs font-semibold uppercase tracking-wider text-slate-500">Indicative Rate</p>
            <p className="mt-1 text-2xl font-bold text-slate-900">{formatCurrency(room.indicative_rate, boot?.settings.hotel.currency)}</p>
            <dl className="mt-4 divide-y divide-slate-100 text-sm">
              {room.occupancy_label && <div className="flex justify-between py-2"><dt className="text-slate-500">Occupancy</dt><dd className="font-medium">{room.occupancy_label}</dd></div>}
              {room.bed_configuration && <div className="flex justify-between py-2"><dt className="text-slate-500">Beds</dt><dd className="font-medium">{room.bed_configuration}</dd></div>}
              <div className="flex justify-between py-2"><dt className="text-slate-500">Check-in</dt><dd className="font-medium">{boot?.settings.booking.check_in_time}</dd></div>
              <div className="flex justify-between py-2"><dt className="text-slate-500">Check-out</dt><dd className="font-medium">{boot?.settings.booking.check_out_time}</dd></div>
            </dl>
            <div className="mt-6 space-y-2">
              <Button asChild className="w-full"><Link to={`/booking?room=${encodeURIComponent(room.slug)}`}>Request This Room</Link></Button>
              <Button asChild variant="secondary" className="w-full"><Link to="/rooms">← All Rooms</Link></Button>
            </div>
          </Card>
        </div>
      </div>
    </div>
  )
}

function addDaysIso(days: number): string {
  const d = new Date()
  d.setDate(d.getDate() + days)
  return d.toISOString().slice(0, 10)
}

export function BookingPage() {
  const boot = usePublicBootstrap()
  const booking = boot?.settings.booking
  const currency = boot?.settings.hotel.currency ?? 'NGN'
  const [searchParams, setSearchParams] = useSearchParams()

  const [defaultCheckIn] = useState(() => addDaysIso(1))
  const [defaultCheckOut] = useState(() => addDaysIso(3))

  const [checkIn, setCheckIn] = useState(searchParams.get('check_in') || defaultCheckIn)
  const [checkOut, setCheckOut] = useState(searchParams.get('check_out') || defaultCheckOut)
  const [adults, setAdults] = useState(Number(searchParams.get('adults') || 2))
  const [children, setChildren] = useState(Number(searchParams.get('children') || 0))
  const [roomsCount, setRoomsCount] = useState(Number(searchParams.get('rooms') || 1))
  const [searchSubmitted, setSearchSubmitted] = useState(true)

  // Step state: 'search' | 'guest' | 'confirmed' | 'lookup'
  const initialRef = searchParams.get('reference') || ''
  const initialToken = searchParams.get('token') || ''
  const [activeTab, setActiveTab] = useState<'book' | 'lookup'>(initialRef ? 'lookup' : 'book')

  const [selectedQuote, setSelectedQuote] = useState<BookingQuote | null>(null)
  const [holdData, setHoldData] = useState<{ hold_token: string; expires_at: string; quote: BookingQuote } | null>(null)
  const [confirmedReservation, setConfirmedReservation] = useState<ReservationRecord | null>(null)
  const [bookingErr, setBookingErr] = useState<string | null>(null)

  const [guestName, setGuestName] = useState('')
  const [guestEmail, setGuestEmail] = useState('')
  const [guestPhone, setGuestPhone] = useState('')
  const [specialRequests, setSpecialRequests] = useState('')

  // Lookup state
  const [lookupRef, setLookupRef] = useState(initialRef)
  const [lookupToken, setLookupToken] = useState(initialToken)
  const [lookupResult, setLookupResult] = useState<ReservationRecord | null>(null)
  const [lookupErr, setLookupErr] = useState<string | null>(null)
  const [cancelReason, setCancelReason] = useState('')

  const availQuery = useQuery({
    queryKey: ['public', 'availability', checkIn, checkOut, adults, children, roomsCount],
    enabled: searchSubmitted && activeTab === 'book' && !holdData && !confirmedReservation,
    queryFn: async () => (await api.get<{ data: { search: Record<string, unknown>; options: AvailabilitySearchOption[] } }>('/public/availability', {
      params: { check_in_date: checkIn, check_out_date: checkOut, adults, children, rooms_count: roomsCount },
    })).data.data,
  })

  const createHoldMut = useMutation({
    mutationFn: async (quote: BookingQuote) => {
      await csrf()
      const idemKey = `hold-${quote.room_type.id}-${quote.rate_plan.id}-${quote.check_in_date}-${quote.check_out_date}-${quote.rooms_count}`
      return (await api.post<{ data: { hold_token: string; expires_at: string; quote: BookingQuote } }>('/public/booking-holds', {
        room_type_id: quote.room_type.id,
        rate_plan_id: quote.rate_plan.id,
        check_in_date: quote.check_in_date,
        check_out_date: quote.check_out_date,
        adults: quote.adults,
        children: quote.children,
        rooms_count: quote.rooms_count,
        idempotency_key: idemKey,
      })).data.data
    },
    onSuccess: (d, quote) => {
      setBookingErr(null)
      setSelectedQuote(quote)
      setHoldData(d)
    },
    onError: (e) => setBookingErr(errorMessage(e)),
  })

  const createResMut = useMutation({
    mutationFn: async () => {
      if (!holdData) throw new Error('No active booking hold.')
      await csrf()
      return (await api.post<{ data: ReservationRecord }>('/public/reservations', {
        hold_token: holdData.hold_token,
        idempotency_key: `res-${holdData.hold_token}`,
        guest_name: guestName,
        guest_email: guestEmail,
        guest_phone: guestPhone,
        special_requests: specialRequests || undefined,
      })).data.data
    },
    onSuccess: (res) => {
      setBookingErr(null)
      setConfirmedReservation(res)
      setHoldData(null)
    },
    onError: (e) => setBookingErr(errorMessage(e)),
  })

  const lookupMut = useMutation({
    mutationFn: async () => {
      setLookupErr(null)
      return (await api.get<{ data: ReservationRecord }>(`/public/reservations/${encodeURIComponent(lookupRef.trim())}`, {
        params: { token: lookupToken.trim() },
      })).data.data
    },
    onSuccess: (res) => setLookupResult(res),
    onError: (e) => { setLookupResult(null); setLookupErr(errorMessage(e)) },
  })

  const cancelMut = useMutation({
    mutationFn: async () => {
      await csrf()
      return (await api.post<{ data: ReservationRecord }>(`/public/reservations/${encodeURIComponent(lookupRef.trim())}/cancellation-requests`, {
        token: lookupToken.trim(),
        reason: cancelReason,
      })).data.data
    },
    onSuccess: (res) => {
      setLookupResult(res)
      setCancelReason('')
    },
    onError: (e) => setLookupErr(errorMessage(e)),
  })

  return (
    <CmsPageView slug="booking">
      {/* Top operational summary cards */}
      <div className="grid gap-4 md:grid-cols-3">
        <Card className="p-5">
          <Clock className="mb-2 h-5 w-5 text-brand-700" aria-hidden />
          <h2 className="text-sm font-semibold text-slate-900">Check-In & Check-Out</h2>
          <p className="mt-1 text-xs text-slate-600">
            Check-in from <strong>{booking?.check_in_time ?? '14:00'}</strong> · Check-out by <strong>{booking?.check_out_time ?? '12:00'}</strong> (Africa/Lagos).
          </p>
        </Card>
        <Card className="p-5">
          <Calendar className="mb-2 h-5 w-5 text-brand-700" aria-hidden />
          <h2 className="text-sm font-semibold text-slate-900">Real-Time Availability & Quotes</h2>
          <p className="mt-1 text-xs text-slate-600">
            All quotes are calculated on the server for your exact dates, occupancy, and applicable rate plan rules.
          </p>
        </Card>
        <Card className="p-5">
          <ShieldCheck className="mb-2 h-5 w-5 text-brand-700" aria-hidden />
          <h2 className="text-sm font-semibold text-slate-900">Manage Existing Booking</h2>
          <p className="mt-1 text-xs text-slate-600">Have a booking reference and security token? View or manage your reservation online.</p>
          <Button
            size="sm"
            variant="secondary"
            className="mt-3"
            onClick={() => setActiveTab(activeTab === 'book' ? 'lookup' : 'book')}
          >
            {activeTab === 'book' ? 'Lookup Existing Booking' : '← Back to New Reservation'}
          </Button>
        </Card>
      </div>

      {activeTab === 'lookup' ? (
        <Card className="p-6 sm:p-8">
          <h2 className="text-xl font-bold text-slate-900">Lookup & Manage Your Reservation</h2>
          <p className="mt-1 text-sm text-slate-600">
            Enter your reservation reference (e.g. <code>MCH-2026-XXXXXX</code>) and the security access token provided at confirmation.
          </p>
          {lookupErr && <div className="mt-4"><Alert variant="error">{lookupErr}</Alert></div>}
          <form
            className="mt-4 grid gap-4 sm:grid-cols-3"
            onSubmit={(e) => { e.preventDefault(); lookupMut.mutate() }}
          >
            <FormField id="lookup-ref" label="Booking Reference">
              <Input id="lookup-ref" placeholder="MCH-2026-XXXXXX" value={lookupRef} onChange={(e) => setLookupRef(e.target.value)} required />
            </FormField>
            <FormField id="lookup-token" label="Security Access Token">
              <Input id="lookup-token" placeholder="Your 48-character security token" value={lookupToken} onChange={(e) => setLookupToken(e.target.value)} required />
            </FormField>
            <div className="flex items-end">
              <Button type="submit" loading={lookupMut.isPending} className="w-full">Find Reservation</Button>
            </div>
          </form>

          {lookupResult && (
            <div className="mt-6 space-y-4 rounded-xl border border-slate-200 bg-slate-50 p-6">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                  <span className="text-xs font-semibold uppercase tracking-wider text-slate-500">Booking Reference</span>
                  <h3 className="text-xl font-bold text-slate-900">{lookupResult.reference}</h3>
                </div>
                <div className="flex gap-2">
                  <Badge tone={lookupResult.status === 'confirmed' ? 'success' : lookupResult.status === 'cancelled' ? 'danger' : 'brand'}>
                    Status: {lookupResult.status.replace('_', ' ')}
                  </Badge>
                  <Badge tone={lookupResult.payment_status === 'paid' ? 'success' : 'warning'}>
                    Payment: {lookupResult.payment_status.replace('_', ' ')}
                  </Badge>
                </div>
              </div>
              <dl className="grid gap-3 text-sm sm:grid-cols-3">
                <div><dt className="text-slate-500">Guest</dt><dd className="font-medium">{lookupResult.guest_name} ({lookupResult.guest_email})</dd></div>
                <div><dt className="text-slate-500">Stay Dates</dt><dd className="font-medium">{lookupResult.check_in_date} → {lookupResult.check_out_date} ({lookupResult.nights} night(s))</dd></div>
                <div><dt className="text-slate-500">Room & Rate Plan</dt><dd className="font-medium">{lookupResult.room_type?.name} · {lookupResult.rate_plan?.name} ({lookupResult.rooms_count} room)</dd></div>
                <div><dt className="text-slate-500">Total Quoted</dt><dd className="font-semibold">{formatCurrency(lookupResult.total_amount, lookupResult.currency_code)}</dd></div>
                <div><dt className="text-slate-500">Amount Paid</dt><dd className="font-medium">{formatCurrency(lookupResult.amount_paid, lookupResult.currency_code)}</dd></div>
                <div><dt className="text-slate-500">Balance Due</dt><dd className="font-semibold text-brand-800">{formatCurrency(lookupResult.balance_due_amount ?? 0, lookupResult.currency_code)}</dd></div>
              </dl>
              {lookupResult.cancellation_policy && (
                <div className="rounded border border-slate-200 bg-white p-3 text-xs text-slate-600">
                  <strong>Cancellation Policy:</strong> {lookupResult.cancellation_policy.terms_text}
                </div>
              )}
              {['confirmed', 'pending_payment'].includes(lookupResult.status) && (
                <div className="border-t border-slate-200 pt-4">
                  <p className="text-xs font-semibold text-slate-700">Need to cancel this reservation?</p>
                  <div className="mt-2 flex flex-wrap gap-2">
                    <Input
                      placeholder="Reason for cancellation"
                      value={cancelReason}
                      onChange={(e) => setCancelReason(e.target.value)}
                      className="max-w-md"
                    />
                    <Button
                      variant="danger"
                      size="sm"
                      disabled={cancelReason.trim().length < 3}
                      loading={cancelMut.isPending}
                      onClick={() => cancelMut.mutate()}
                    >
                      Cancel Reservation
                    </Button>
                  </div>
                </div>
              )}
            </div>
          )}
        </Card>
      ) : confirmedReservation ? (
        /* Step F: Confirmation Screen */
        <Card className="border-emerald-300 bg-emerald-50/40 p-6 sm:p-8">
          <div className="flex items-start gap-3">
            <CheckCircle2 className="mt-0.5 h-7 w-7 shrink-0 text-emerald-600" aria-hidden />
            <div className="flex-1">
              <p className="text-xs font-semibold uppercase tracking-wider text-emerald-800">Reservation Confirmed</p>
              <h2 className="mt-1 text-2xl font-bold text-slate-900">Reference: {confirmedReservation.reference}</h2>
              <p className="mt-1 text-sm text-slate-700">
                Thank you, <strong>{confirmedReservation.guest_name}</strong>. Please save your booking reference and security access token below to view or manage your reservation at any time.
              </p>

              {confirmedReservation.access_token && (
                <div className="mt-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-xs text-amber-950">
                  <p className="font-semibold">Security Access Token (save this for online lookup):</p>
                  <code className="mt-1 block break-all rounded bg-white px-2 py-1.5 font-mono text-xs text-slate-900">
                    {confirmedReservation.access_token}
                  </code>
                </div>
              )}

              <dl className="mt-5 grid gap-4 rounded-xl border border-slate-200 bg-white p-5 text-sm sm:grid-cols-3">
                <div><dt className="text-slate-500">Check-In</dt><dd className="font-semibold">{confirmedReservation.check_in_date} ({booking?.check_in_time ?? '14:00'})</dd></div>
                <div><dt className="text-slate-500">Check-Out</dt><dd className="font-semibold">{confirmedReservation.check_out_date} ({booking?.check_out_time ?? '12:00'})</dd></div>
                <div><dt className="text-slate-500">Duration & Rooms</dt><dd className="font-semibold">{confirmedReservation.nights} night(s) · {confirmedReservation.rooms_count} room(s)</dd></div>
                <div><dt className="text-slate-500">Room Type</dt><dd className="font-medium">{confirmedReservation.room_type?.name}</dd></div>
                <div><dt className="text-slate-500">Rate Plan</dt><dd className="font-medium">{confirmedReservation.rate_plan?.name}</dd></div>
                <div><dt className="text-slate-500">Reservation / Payment Status</dt><dd className="font-medium">{confirmedReservation.status} / {confirmedReservation.payment_status}</dd></div>
                <div><dt className="text-slate-500">Subtotal</dt><dd>{formatCurrency(confirmedReservation.subtotal_amount, confirmedReservation.currency_code)}</dd></div>
                <div><dt className="text-slate-500">Taxes & Service Fees</dt><dd>{formatCurrency(confirmedReservation.taxes_amount + confirmedReservation.fees_amount, confirmedReservation.currency_code)}</dd></div>
                <div><dt className="text-slate-500">Total Quoted Price</dt><dd className="text-lg font-bold text-brand-900">{formatCurrency(confirmedReservation.total_amount, confirmedReservation.currency_code)}</dd></div>
              </dl>

              <div className="mt-6 flex flex-wrap gap-3">
                <Button onClick={() => window.print()} variant="secondary">Print Confirmation</Button>
                <Button
                  onClick={() => {
                    setConfirmedReservation(null)
                    setSelectedQuote(null)
                    setHoldData(null)
                  }}
                >
                  Make Another Reservation
                </Button>
              </div>
            </div>
          </div>
        </Card>
      ) : holdData && selectedQuote ? (
        /* Step C & D: Active Booking Hold & Guest Details Form */
        <div className="grid gap-6 lg:grid-cols-3">
          <Card className="p-6 sm:p-8 lg:col-span-2">
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 pb-4">
              <div>
                <Badge tone="brand">Step 2 of 2 · Complete Guest Details</Badge>
                <h2 className="mt-2 text-xl font-bold text-slate-900">Guest Information</h2>
              </div>
              <div className="text-right text-xs text-slate-500">
                Hold expires at: <strong>{formatDateTime(holdData.expires_at)}</strong>
              </div>
            </div>

            {bookingErr && <div className="mt-4"><Alert variant="error">{bookingErr}</Alert></div>}

            <form
              className="mt-5 space-y-4"
              onSubmit={(e) => { e.preventDefault(); createResMut.mutate() }}
            >
              <FormField id="guest-name" label="Full Name *">
                <Input id="guest-name" value={guestName} onChange={(e) => setGuestName(e.target.value)} required placeholder="e.g. Dr. Amina Yusuf" />
              </FormField>
              <div className="grid gap-4 sm:grid-cols-2">
                <FormField id="guest-email" label="Email Address *">
                  <Input id="guest-email" type="email" value={guestEmail} onChange={(e) => setGuestEmail(e.target.value)} required placeholder="guest@example.com" />
                </FormField>
                <FormField id="guest-phone" label="Phone Number *">
                  <Input id="guest-phone" type="tel" value={guestPhone} onChange={(e) => setGuestPhone(e.target.value)} required placeholder="+234 ..." />
                </FormField>
              </div>
              <FormField id="special-requests" label="Special Requests (optional)">
                <Textarea id="special-requests" rows={3} value={specialRequests} onChange={(e) => setSpecialRequests(e.target.value)} placeholder="Dietary preferences, arrival time notes, accessibility requests…" />
              </FormField>

              <Alert variant="info" title="Payment & Security Notice">
                We never collect credit/debit card numbers or CVVs on this form. Your reservation will be recorded with a guaranteed server-side price snapshot, and payment can be settled at reception or via verified hotel bank transfer/POS.
              </Alert>

              <div className="flex flex-wrap justify-between gap-3 pt-2">
                <Button type="button" variant="secondary" onClick={() => { setHoldData(null); setSelectedQuote(null) }}>
                  ← Change Room or Rate Plan
                </Button>
                <Button type="submit" loading={createResMut.isPending}>
                  Confirm Reservation ({formatCurrency(holdData.quote.total_amount, holdData.quote.currency_code)})
                </Button>
              </div>
            </form>
          </Card>

          <Card className="p-6">
            <h3 className="text-base font-bold text-slate-900">Your Price Quote & Hold</h3>
            <p className="mt-1 text-xs text-slate-500">Inventory is held for your stay while you complete this form.</p>
            <dl className="mt-4 divide-y divide-slate-100 text-sm">
              <div className="flex justify-between py-2"><dt className="text-slate-500">Room Type</dt><dd className="font-medium">{holdData.quote.room_type.name}</dd></div>
              <div className="flex justify-between py-2"><dt className="text-slate-500">Rate Plan</dt><dd className="font-medium">{holdData.quote.rate_plan.name}</dd></div>
              <div className="flex justify-between py-2"><dt className="text-slate-500">Check-In</dt><dd className="font-medium">{holdData.quote.check_in_date}</dd></div>
              <div className="flex justify-between py-2"><dt className="text-slate-500">Check-Out</dt><dd className="font-medium">{holdData.quote.check_out_date} ({holdData.quote.nights} night(s))</dd></div>
              <div className="flex justify-between py-2"><dt className="text-slate-500">Guests / Rooms</dt><dd className="font-medium">{holdData.quote.adults} Adult(s), {holdData.quote.children} Child(ren) · {holdData.quote.rooms_count} Room(s)</dd></div>
              <div className="flex justify-between py-2"><dt className="text-slate-500">Room Subtotal</dt><dd>{formatCurrency(holdData.quote.subtotal_amount, holdData.quote.currency_code)}</dd></div>
              {holdData.quote.taxes_amount > 0 && (
                <div className="flex justify-between py-2"><dt className="text-slate-500">Tax ({holdData.quote.tax_percentage}%)</dt><dd>{formatCurrency(holdData.quote.taxes_amount, holdData.quote.currency_code)}</dd></div>
              )}
              {holdData.quote.fees_amount > 0 && (
                <div className="flex justify-between py-2"><dt className="text-slate-500">Service Charge ({holdData.quote.service_charge_percentage}%)</dt><dd>{formatCurrency(holdData.quote.fees_amount, holdData.quote.currency_code)}</dd></div>
              )}
              <div className="flex justify-between py-3 text-base font-bold text-slate-900">
                <dt>Total Price</dt>
                <dd>{formatCurrency(holdData.quote.total_amount, holdData.quote.currency_code)}</dd>
              </div>
            </dl>
            <div className="mt-4 rounded-lg bg-slate-50 p-3 text-xs text-slate-600">
              <p className="font-semibold text-slate-800">Cancellation Policy</p>
              <p className="mt-1">{holdData.quote.cancellation_policy.terms_text}</p>
            </div>
          </Card>
        </div>
      ) : (
        /* Step A & B: Search Bar & Available Room/Rate Plan Options */
        <div className="space-y-6">
          <Card className="p-6">
            <form
              className="grid gap-4 sm:grid-cols-2 lg:grid-cols-6"
              onSubmit={(e) => {
                e.preventDefault()
                setSearchParams({
                  check_in: checkIn,
                  check_out: checkOut,
                  adults: String(adults),
                  children: String(children),
                  rooms: String(roomsCount),
                })
                setSearchSubmitted(true)
                availQuery.refetch()
              }}
            >
              <FormField id="search-checkin" label="Check-In Date">
                <Input id="search-checkin" type="date" min={defaultCheckIn} value={checkIn} onChange={(e) => setCheckIn(e.target.value)} required />
              </FormField>
              <FormField id="search-checkout" label="Check-Out Date">
                <Input id="search-checkout" type="date" min={checkIn} value={checkOut} onChange={(e) => setCheckOut(e.target.value)} required />
              </FormField>
              <FormField id="search-adults" label="Adults">
                <Input id="search-adults" type="number" min={1} max={20} value={adults} onChange={(e) => setAdults(Number(e.target.value))} required />
              </FormField>
              <FormField id="search-children" label="Children">
                <Input id="search-children" type="number" min={0} max={20} value={children} onChange={(e) => setChildren(Number(e.target.value))} />
              </FormField>
              <FormField id="search-rooms" label="Rooms">
                <Input id="search-rooms" type="number" min={1} max={5} value={roomsCount} onChange={(e) => setRoomsCount(Number(e.target.value))} required />
              </FormField>
              <div className="flex items-end">
                <Button type="submit" className="w-full" loading={availQuery.isFetching}>Search Availability</Button>
              </div>
            </form>
          </Card>

          {bookingErr && <Alert variant="error">{bookingErr}</Alert>}

          {!booking?.online_booking_enabled && (
            <Alert variant="info" title="Online Booking Notice">
              Online instant booking is currently in setup mode or managed by the reservation desk. If room inventory and rate plans have been configured by hotel administration, you may preview quotes below or contact reception directly.
            </Alert>
          )}

          {availQuery.isLoading ? (
            <Spinner label="Checking room availability and calculating exact quotes…" />
          ) : availQuery.isError ? (
            <ErrorState message={errorMessage(availQuery.error)} onRetry={() => availQuery.refetch()} />
          ) : availQuery.data && availQuery.data.options.length > 0 ? (
            <div className="space-y-6">
              {availQuery.data.options.map((opt) => (
                <Card key={opt.room_type.id} className="overflow-hidden">
                  <div className="grid gap-6 p-6 lg:grid-cols-3">
                    <div>
                      {opt.room_type.featured_media ? (
                        <img src={opt.room_type.featured_media.url} alt={opt.room_type.featured_media.alt_text} className="h-48 w-full rounded-lg object-cover" />
                      ) : (
                        <div className="flex h-48 items-center justify-center rounded-lg bg-brand-100 text-brand-800">
                          <BedDouble className="h-10 w-10" aria-hidden />
                        </div>
                      )}
                      <h3 className="mt-3 text-lg font-bold text-slate-900">{opt.room_type.name}</h3>
                      {opt.room_type.subtitle && <p className="text-xs text-slate-500">{opt.room_type.subtitle}</p>}
                      <div className="mt-2 flex flex-wrap gap-1.5 text-xs">
                        <Badge>Up to {opt.room_type.max_occupancy} guests/room</Badge>
                        {opt.room_type.bed_configuration && <Badge>{opt.room_type.bed_configuration}</Badge>}
                        <Badge tone="success">{opt.available_rooms} room(s) left</Badge>
                      </div>
                      {opt.room_type.indicative_starting_rate !== null && (
                        <p className="mt-2 text-xs text-slate-500">
                          Indicative starting rate: {formatCurrency(opt.room_type.indicative_starting_rate, currency)}/night
                        </p>
                      )}
                    </div>

                    <div className="space-y-4 lg:col-span-2">
                      <h4 className="text-xs font-semibold uppercase tracking-wider text-slate-500">
                        Available Rate Plans & Exact Quotes for {opt.rate_plans[0]?.nights} Night(s)
                      </h4>
                      <div className="divide-y divide-slate-200 rounded-xl border border-slate-200">
                        {opt.rate_plans.map((quote) => (
                          <div key={quote.rate_plan.id} className="flex flex-wrap items-center justify-between gap-4 p-4">
                            <div className="space-y-1">
                              <div className="flex items-center gap-2">
                                <span className="font-semibold text-slate-900">{quote.rate_plan.name}</span>
                                <Badge tone="brand">{quote.rate_plan.meal_basis.replace('_', ' ')}</Badge>
                                <Badge tone={quote.rate_plan.is_refundable ? 'success' : 'warning'}>
                                  {quote.rate_plan.is_refundable ? 'Refundable' : 'Non-refundable'}
                                </Badge>
                              </div>
                              <p className="text-xs text-slate-600">{quote.cancellation_policy.terms_text}</p>
                              <div className="flex flex-wrap gap-2 pt-1 text-xs text-slate-500">
                                {quote.nightly_breakdown.map((n) => (
                                  <span key={n.stay_date} className="rounded bg-slate-100 px-2 py-0.5">
                                    {n.stay_date}: {formatCurrency(n.total_night_amount, quote.currency_code)}
                                  </span>
                                ))}
                              </div>
                            </div>
                            <div className="text-right">
                              <p className="text-xs text-slate-500">
                                {quote.nights} night(s), {quote.rooms_count} room(s) incl. taxes/fees
                              </p>
                              <p className="text-xl font-bold text-slate-900">
                                {formatCurrency(quote.total_amount, quote.currency_code)}
                              </p>
                              <Button
                                size="sm"
                                className="mt-2"
                                loading={createHoldMut.isPending}
                                onClick={() => createHoldMut.mutate(quote)}
                              >
                                Select & Hold Room
                              </Button>
                            </div>
                          </div>
                        ))}
                      </div>
                    </div>
                  </div>
                </Card>
              ))}
            </div>
          ) : (
            <Card>
              <EmptyState
                icon={BedDouble}
                title="No available rooms or rate plans match your search"
                description="Either room inventory and rate plans have not yet been configured by hotel administration for these dates, or all rooms are reserved. You can adjust your dates or send an inquiry to our reservation desk."
                action={<Button asChild variant="secondary"><Link to="/contact">Contact Reservation Desk</Link></Button>}
              />
            </Card>
          )}
        </div>
      )}
    </CmsPageView>
  )
}

export function FacilitiesPage() {
  return <CmsPageView slug="facilities" />
}

export function RestaurantPage() {
  return <CmsPageView slug="restaurant" />
}

export function GalleryPage() {
  const [category, setCategory] = useState('')
  const [lightbox, setLightbox] = useState<MediaAssetItem | null>(null)
  const q = useQuery({
    queryKey: ['public', 'gallery', category],
    queryFn: async () => (await api.get<Paginated<MediaAssetItem>>('/gallery', { params: { category: category || undefined } })).data,
  })
  const categories = [
    { value: '', label: 'All' },
    { value: 'general', label: 'Exterior & General' },
    { value: 'rooms', label: 'Rooms' },
    { value: 'restaurant', label: 'Restaurant' },
    { value: 'facilities', label: 'Facilities' },
    { value: 'events', label: 'Events' },
  ]

  return (
    <CmsPageView slug="gallery">
      <div className="flex flex-wrap gap-2">
        {categories.map((c) => (
          <Button
            key={c.value}
            size="sm"
            variant={category === c.value ? 'primary' : 'secondary'}
            onClick={() => setCategory(c.value)}
          >
            {c.label}
          </Button>
        ))}
      </div>
      {q.isLoading ? <Spinner /> : q.isError ? <ErrorState message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : (
        q.data?.data.length ? (
          <div className="grid gap-6 sm:grid-cols-2 lg:grid-cols-3">
            {q.data.data.map((m) => (
              <figure key={m.id} className="group overflow-hidden rounded-xl border border-slate-200 bg-white shadow-sm">
                <button type="button" className="block w-full text-left" onClick={() => setLightbox(m)}>
                  <img src={m.url} alt={m.alt_text} className="h-56 w-full object-cover transition-transform group-hover:scale-105" loading="lazy" />
                </button>
                <figcaption className="p-4">
                  <p className="font-medium text-slate-900">{m.title || m.alt_text}</p>
                  {m.caption && <p className="mt-1 text-xs text-slate-500">{m.caption}</p>}
                </figcaption>
              </figure>
            ))}
          </div>
        ) : (
          <Card><EmptyState title="No gallery images in this category" /></Card>
        )
      )}
      <Modal open={!!lightbox} onOpenChange={(o) => !o && setLightbox(null)} title={lightbox?.title || lightbox?.alt_text || 'Image'} wide>
        {lightbox && (
          <figure className="space-y-3">
            <img src={lightbox.url} alt={lightbox.alt_text} className="max-h-[70vh] w-full rounded object-contain" />
            <figcaption className="text-sm text-slate-600">{lightbox.caption || lightbox.alt_text}</figcaption>
          </figure>
        )}
      </Modal>
    </CmsPageView>
  )
}

export function OffersPage() {
  const q = useQuery({
    queryKey: ['public', 'offers'],
    queryFn: async () => (await api.get<{ data: OfferItem[] }>('/offers')).data.data,
  })
  return (
    <CmsPageView slug="offers">
      {q.isLoading ? <Spinner /> : q.isError ? <ErrorState message={errorMessage(q.error)} onRetry={() => q.refetch()} /> : (
        q.data && q.data.length > 0 ? (
          <div className="grid gap-6 sm:grid-cols-2">
            {q.data.map((o) => <OfferCard key={o.id} offer={o} />)}
          </div>
        ) : (
          <Card>
            <EmptyState
              icon={Tag}
              title="No active promotions at the moment"
              description="Special offers and seasonal packages will appear here as soon as they are published by management."
              action={<Button asChild variant="secondary"><Link to="/contact">Contact Us for Group Rates</Link></Button>}
            />
          </Card>
        )
      )}
    </CmsPageView>
  )
}

export function OfferDetailPage() {
  const { slug = '' } = useParams()
  const boot = usePublicBootstrap()
  const hotelName = boot?.settings.hotel.name ?? 'Mai Chitta Hotel'
  const q = useQuery({
    queryKey: ['public', 'offer', slug],
    queryFn: async () => (await api.get<{ data: OfferItem }>(`/offers/${slug}`)).data.data,
  })
  useSeo({
    title: q.data ? (q.data.seo_title || `${q.data.title} | ${hotelName}`) : hotelName,
    description: q.data?.seo_description || q.data?.summary,
  })
  if (q.isLoading) return <Spinner />
  if (q.isError || !q.data) return <div className="mx-auto max-w-4xl px-4 py-12"><ErrorState message="Offer not found or no longer active." /></div>
  const offer = q.data
  return (
    <div>
      <PageBanner title={offer.title} subtitle={offer.subtitle} media={offer.featured_media} />
      <div className="mx-auto max-w-4xl space-y-6 px-4 py-12 sm:px-6">
        <Card className="p-6 sm:p-8">
          <div className="mb-4 flex flex-wrap items-center gap-2">
            {offer.badge_text && <Badge tone="brand">{offer.badge_text}</Badge>}
            {offer.ends_at && <span className="text-xs text-slate-500">Valid until {formatDateTime(offer.ends_at)}</span>}
          </div>
          {offer.summary && <p className="text-base font-medium text-slate-800">{offer.summary}</p>}
          {offer.body && <SafeHtml html={offer.body} className="mt-4" />}
          {offer.terms_text && (
            <div className="mt-6 rounded-md bg-slate-50 p-4 text-xs text-slate-600">
              <p className="font-semibold text-slate-800">Terms & Conditions</p>
              <p className="mt-1">{offer.terms_text}</p>
            </div>
          )}
          <div className="mt-6 flex gap-3">
            <Button asChild><Link to={offer.cta_url || '/contact'}>{offer.cta_label || 'Inquire About This Offer'}</Link></Button>
            <Button asChild variant="secondary"><Link to="/offers">← All Offers</Link></Button>
          </div>
        </Card>
      </div>
    </div>
  )
}

export function EventsPage() {
  return <CmsPageView slug="events" />
}

const contactSchema = z.object({
  name: z.string().trim().min(2, 'Enter your full name').max(120),
  email: z.string().trim().email('Enter a valid email address'),
  phone: z.string().max(32).regex(/^[0-9+()\s-]*$/, 'Digits, spaces, + ( ) - only').optional(),
  subject: z.string().trim().min(3, 'Enter a subject').max(180),
  message: z.string().trim().min(10, 'Please enter at least 10 characters').max(3000),
})
type ContactValues = z.infer<typeof contactSchema>

export function ContactPage() {
  const boot = usePublicBootstrap()
  const hotel = boot?.settings.hotel
  const [sent, setSent] = useState<string | null>(null)
  const [err, setErr] = useState<string | null>(null)
  const form = useForm<ContactValues>({
    resolver: zodResolver(contactSchema),
    defaultValues: { name: '', email: '', phone: '', subject: '', message: '' },
  })
  const submit = useMutation({
    mutationFn: async (v: ContactValues) => {
      await csrf()
      return (await api.post<{ message: string }>('/contact', v)).data.message
    },
    onSuccess: (m) => { setSent(m); setErr(null); form.reset() },
    onError: (e) => setErr(errorMessage(e)),
  })
  const e = form.formState.errors

  return (
    <CmsPageView slug="contact">
      <div className="grid gap-8 lg:grid-cols-3">
        <Card className="p-6">
          <h2 className="text-lg font-semibold text-slate-900">Hotel Location & Contact</h2>
          <dl className="mt-4 space-y-3 text-sm">
            <div>
              <dt className="font-medium text-slate-500">Hotel</dt>
              <dd className="text-slate-900">{hotel?.name}</dd>
            </div>
            <div>
              <dt className="font-medium text-slate-500">Location</dt>
              <dd className="text-slate-900">
                {hotel?.address ? `${hotel.address}, ` : ''}
                {[hotel?.city, hotel?.state, hotel?.country].filter(Boolean).join(', ')}
              </dd>
            </div>
            <div>
              <dt className="font-medium text-slate-500">Telephone</dt>
              <dd className="text-slate-900">{hotel?.phone || <span className="text-xs text-slate-500">Use the inquiry form while official telephone lines are being updated</span>}</dd>
            </div>
            <div>
              <dt className="font-medium text-slate-500">Email</dt>
              <dd className="text-slate-900">{hotel?.email || <span className="text-xs text-slate-500">Use the inquiry form to message our front desk</span>}</dd>
            </div>
          </dl>
        </Card>

        <Card className="p-6 sm:p-8 lg:col-span-2">
          <h2 className="text-lg font-semibold text-slate-900">Send an Inquiry</h2>
          <p className="mt-1 text-sm text-slate-500">Our staff will review your message and respond promptly.</p>
          {sent ? (
            <div className="mt-6 space-y-4">
              <Alert variant="success">{sent}</Alert>
              <Button variant="secondary" size="sm" onClick={() => setSent(null)}>Send another message</Button>
            </div>
          ) : (
            <form onSubmit={form.handleSubmit((v) => submit.mutate(v))} noValidate className="mt-6 space-y-4">
              {err && <Alert variant="error">{err}</Alert>}
              <div className="grid gap-4 sm:grid-cols-2">
                <FormField id="c-name" label="Your name" error={e.name?.message}>
                  <Input id="c-name" {...form.register('name')} />
                </FormField>
                <FormField id="c-email" label="Email address" error={e.email?.message}>
                  <Input id="c-email" type="email" {...form.register('email')} />
                </FormField>
                <FormField id="c-phone" label="Phone number (optional)" error={e.phone?.message}>
                  <Input id="c-phone" type="tel" {...form.register('phone')} />
                </FormField>
                <FormField id="c-subject" label="Subject" error={e.subject?.message}>
                  <Input id="c-subject" {...form.register('subject')} />
                </FormField>
              </div>
              <FormField id="c-message" label="Message" error={e.message?.message}>
                <Textarea id="c-message" rows={5} {...form.register('message')} />
              </FormField>
              <Button type="submit" loading={submit.isPending}>Submit Inquiry</Button>
            </form>
          )}
        </Card>
      </div>
    </CmsPageView>
  )
}

export function PrivacyPolicyPage() {
  return <CmsPageView slug="privacy-policy" />
}

export function TermsPage() {
  return <CmsPageView slug="terms-and-conditions" />
}

export function CancellationPolicyPage() {
  return <CmsPageView slug="cancellation-policy" />
}

export function CmsGenericPage() {
  const { slug = '' } = useParams()
  return <CmsPageView slug={slug} />
}
