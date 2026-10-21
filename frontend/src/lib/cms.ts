import { useEffect } from 'react'

export type MediaAssetItem = {
  id: number
  uuid: string
  disk: 'public' | 'local'
  path: string
  url: string
  original_name: string
  filename: string
  mime_type: string
  extension: string
  size_bytes: number
  width: number | null
  height: number | null
  alt_text: string
  title: string | null
  caption: string | null
  category: 'general' | 'rooms' | 'restaurant' | 'facilities' | 'events' | 'gallery' | 'branding'
  is_public: boolean
  in_gallery: boolean
  sort_order: number
  created_at?: string
}

export type SectionType =
  | 'hero'
  | 'rich_text'
  | 'feature_grid'
  | 'image_text'
  | 'cta_banner'
  | 'faq'
  | 'notice'
  | 'contact_block'

export type PageSectionItem = {
  id?: number
  type: SectionType
  title: string | null
  subtitle: string | null
  body: string | null
  media_id: number | null
  media?: MediaAssetItem | null
  cta_label: string | null
  cta_url: string | null
  data: {
    items?: Array<{ title?: string; description?: string; question?: string; answer?: string }>
  } | null
  is_visible: boolean
  sort_order: number
}

export type PageRevisionItem = {
  id: number
  page_id: number
  version: number
  note: string | null
  snapshot: {
    title: string
    subtitle: string | null
    excerpt: string | null
    body: string | null
    template: string
    status: string
    seo_title: string | null
    seo_description: string | null
    sections?: PageSectionItem[]
  }
  author?: { id: number; name: string } | null
  created_at: string
}

export type PageItem = {
  id: number
  slug: string
  title: string
  subtitle: string | null
  excerpt: string | null
  body: string | null
  template: string
  status: 'draft' | 'published' | 'archived'
  is_system: boolean
  featured_media_id: number | null
  featured_media?: MediaAssetItem | null
  seo_title: string | null
  seo_description: string | null
  canonical_url: string | null
  og_image_id: number | null
  og_image?: MediaAssetItem | null
  noindex: boolean
  published_at: string | null
  updated_at?: string
  sections_count?: number
  revisions_count?: number
  sections?: PageSectionItem[]
  revisions?: PageRevisionItem[]
}

export type NavMenuItem = {
  id?: number
  label: string
  url: string
  page_id?: number | null
  target: '_self' | '_blank'
  is_visible: boolean
  sort_order: number
}

export type NavMenuRecord = {
  id: number
  slug: 'header' | 'footer' | string
  name: string
  description: string | null
  items: NavMenuItem[]
}

export type RoomContentItem = {
  id: number
  slug: string
  title: string
  subtitle: string | null
  summary: string | null
  description: string | null
  occupancy_label: string | null
  bed_configuration: string | null
  indicative_rate: number | null
  amenities: string[] | null
  featured_media_id: number | null
  featured_media?: MediaAssetItem | null
  status: 'draft' | 'published' | 'archived'
  is_featured: boolean
  sort_order: number
  seo_title: string | null
  seo_description: string | null
  published_at: string | null
}

export type OfferItem = {
  id: number
  slug: string
  title: string
  subtitle: string | null
  summary: string | null
  body: string | null
  badge_text: string | null
  terms_text: string | null
  cta_label: string | null
  cta_url: string | null
  featured_media_id: number | null
  featured_media?: MediaAssetItem | null
  starts_at: string | null
  ends_at: string | null
  status: 'draft' | 'published' | 'archived'
  is_featured: boolean
  sort_order: number
  seo_title: string | null
  seo_description: string | null
  published_at: string | null
}

export type TestimonialItem = {
  id: number
  guest_name: string
  guest_title: string | null
  content: string
  rating: number | null
  source: string | null
  status: 'draft' | 'published' | 'archived'
  is_featured: boolean
  sort_order: number
  published_at: string | null
}

export type PublicBootstrap = {
  settings: {
    hotel: {
      name: string
      address: string | null
      city: string | null
      state: string | null
      country: string | null
      currency: string
      phone: string | null
      email: string | null
      logo_path: string | null
    }
    website: {
      tagline: string | null
      hero_title: string | null
      hero_subtitle: string | null
      hero_cta_label: string | null
      hero_cta_url: string | null
      hero_media_id: number | null
      seo_default_title: string | null
      seo_default_description: string | null
      seo_og_image_id: number | null
      footer_notice: string | null
      social_links: Array<{ label: string; url: string }>
      show_featured_rooms: boolean
      show_featured_offers: boolean
      show_gallery_preview: boolean
      show_testimonials: boolean
      maintenance_mode: boolean
      show_booking_widget: boolean
    }
    booking: {
      online_booking_enabled: boolean
      check_in_time: string
      check_out_time: string
      hold_ttl_minutes?: number
      max_rooms_per_booking?: number
      max_stay_nights?: number
      tax_percentage?: string
      service_charge_percentage?: string
      default_cancellation_policy?: string | null
      online_payment_provider?: 'none' | 'paystack' | 'flutterwave'
      require_payment_for_online_booking?: boolean
    }
  }
  hero_media: MediaAssetItem | null
  menus: {
    header: NavMenuItem[]
    footer: NavMenuItem[]
  }
  featured_rooms: RoomContentItem[]
  featured_offers: OfferItem[]
  gallery_preview: MediaAssetItem[]
  testimonials: TestimonialItem[]
}

/* ===================== Phase 3 Reservation & Room Types ===================== */
export type RoomTypeRecord = {
  id: number
  name: string
  slug: string
  code: string | null
  subtitle: string | null
  description: string | null
  public_description?: string | null
  max_adults: number
  max_children: number
  max_occupancy: number
  base_occupancy: number
  bed_configuration: string | null
  size_label: string | null
  amenities: string[] | null
  featured_media_id: number | null
  featured_media?: MediaAssetItem | null
  starting_rate: number | null
  starting_rate_minor?: number | null
  currency_code: string
  is_active: boolean
  is_public: boolean
  is_featured: boolean
  sort_order: number
  housekeeping_notes?: string | null
  operational_notes?: string | null
  rooms_count?: number
  active_rooms_count?: number
  out_of_service_rooms_count?: number
}

export type PhysicalRoomRecord = {
  id: number
  room_number: string
  room_type_id: number
  room_type?: RoomTypeRecord
  floor: string | null
  building: string | null
  operational_status: 'active' | 'inactive' | 'out_of_service'
  housekeeping_status: 'clean' | 'dirty' | 'inspecting' | 'maintenance'
  out_of_service_from: string | null
  out_of_service_until: string | null
  out_of_service_reason: string | null
  internal_notes: string | null
  sort_order: number
}

export type RatePlanRoomTypePivot = {
  id?: number
  rate_plan_id?: number
  room_type_id: number
  room_type?: RoomTypeRecord
  base_rate: number
  extra_adult_rate: number
  extra_child_rate: number
  weekday_rates?: Record<string, number> | null
  is_active: boolean
}

export type RatePlanOverrideRecord = {
  id: number
  rate_plan_id: number
  room_type_id: number
  room_type?: RoomTypeRecord
  label: string | null
  override_type: 'exact_date' | 'date_range'
  start_date: string
  end_date: string
  nightly_rate: number | null
  extra_adult_rate: number | null
  extra_child_rate: number | null
  min_stay_nights: number | null
  max_stay_nights: number | null
  closed_to_sell: boolean
  closed_to_arrival: boolean
  closed_to_departure: boolean
  priority: number
}

export type RatePlanRecord = {
  id: number
  name: string
  code: string
  description: string | null
  meal_Basis: 'room_only' | 'breakfast_included' | 'half_board' | 'full_board'
  cancellation_policy_type: 'unconfigured' | 'flexible' | 'moderate' | 'non_refundable' | 'custom'
  free_cancellation_hours: number | null
  cancellation_penalty_type: 'none' | 'first_night' | 'percentage' | 'full_stay'
  cancellation_penalty_value: number
  cancellation_terms: string | null
  currency_code: string
  default_base_rate: number
  extra_adult_rate: number
  extra_child_rate: number
  effective_from: string | null
  effective_until: string | null
  min_stay_nights: number
  max_stay_nights: number | null
  closed_to_arrival: boolean
  closed_to_departure: boolean
  is_active: boolean
  is_public: boolean
  is_refundable: boolean
  sort_order: number
  room_type_rates?: RatePlanRoomTypePivot[]
  overrides?: RatePlanOverrideRecord[]
}

export type NightlyQuoteItem = {
  stay_date: string
  day_of_week: string
  pricing_source: string
  override_label?: string | null
  base_rate_minor: number
  base_rate: number
  extra_guest_minor: number
  extra_guest_amount: number
  total_night_minor: number
  total_night_amount: number
}

export type CancellationPolicySnapshot = {
  configured: boolean
  policy_type: string
  is_refundable: boolean
  free_cancellation_hours: number | null
  penalty_type: string
  penalty_value: number
  terms_text: string
}

export type BookingQuote = {
  room_type: {
    id: number
    name: string
    slug: string
    max_adults: number
    max_children: number
    max_occupancy: number
    base_occupancy: number
    bed_configuration: string | null
  }
  rate_plan: {
    id: number
    name: string
    code: string
    meal_basis: string
    is_refundable: boolean
  }
  check_in_date: string
  check_out_date: string
  nights: number
  rooms_count: number
  adults: number
  children: number
  currency_code: string
  average_nightly_amount: number
  subtotal_amount: number
  tax_percentage: number
  taxes_amount: number
  service_charge_percentage: number
  fees_amount: number
  total_amount: number
  total_minor: number
  available_rooms: number
  nightly_breakdown: NightlyQuoteItem[]
  cancellation_policy: CancellationPolicySnapshot
  calculated_at: string
}

export type AvailabilitySearchOption = {
  room_type: {
    id: number
    name: string
    slug: string
    code: string | null
    subtitle: string | null
    description: string | null
    max_adults: number
    max_children: number
    max_occupancy: number
    base_occupancy: number
    bed_configuration: string | null
    size_label: string | null
    amenities: string[]
    featured_media: MediaAssetItem | null
    indicative_starting_rate: number | null
    currency_code: string
  }
  available_rooms: number
  lowest_total_amount: number
  rate_plans: BookingQuote[]
}

export type PaymentTransactionRecord = {
  id: number
  transaction_reference: string
  reservation_id: number
  reservation?: {
    id: number
    reference: string
    guest_name: string
    total_amount: number
    currency_code: string
    status: string
    payment_status: string
  }
  type: 'payment' | 'reversal' | 'refund_payout'
  method: string
  provider: string | null
  provider_reference: string | null
  currency_code: string
  amount: number
  amount_minor: number
  status: 'pending' | 'completed' | 'failed' | 'reversed'
  receipt_number: string | null
  notes: string | null
  paid_at: string | null
  recorder?: { id: number; name: string } | null
  created_at: string
}

export type PaymentRefundRecord = {
  id: number
  refund_reference: string
  reservation_id: number
  reservation?: {
    id: number
    reference: string
    guest_name: string
    total_amount: number
    amount_paid: number
    currency_code: string
  }
  currency_code: string
  amount: number
  reason: string
  status: 'pending' | 'approved' | 'rejected' | 'processed'
  payout_method: string | null
  payout_reference: string | null
  review_notes: string | null
  requester?: { id: number; name: string } | null
  reviewer?: { id: number; name: string } | null
  reviewed_at: string | null
  processed_at: string | null
  created_at: string
}

export type ReservationRecord = {
  id: number
  reference: string
  access_token?: string | null
  status: 'pending_payment' | 'confirmed' | 'checked_in' | 'checked_out' | 'cancelled' | 'no_show' | 'expired'
  payment_status: 'unpaid' | 'partially_paid' | 'paid' | 'partially_refunded' | 'refunded' | 'payment_failed'
  source: string
  room_type_id: number
  rate_plan_id: number
  assigned_room_id: number | null
  room_type?: RoomTypeRecord | null
  rate_plan?: RatePlanRecord | null
  assigned_room?: PhysicalRoomRecord | null
  assignable_rooms?: PhysicalRoomRecord[]
  guest_id?: number | null
  guest_name: string
  guest_email: string
  guest_phone: string
  check_in_date: string
  check_out_date: string
  nights: number
  rooms_count: number
  adults: number
  children: number
  currency_code: string
  subtotal_amount: number
  taxes_amount: number
  fees_amount: number
  total_amount: number
  amount_paid: number
  amount_refunded: number
  balance_due_amount?: number
  cancellation_fee_minor?: number
  price_snapshot: BookingQuote
  cancellation_policy_snapshot?: CancellationPolicySnapshot | null
  cancellation_policy?: CancellationPolicySnapshot | null
  special_requests: string | null
  internal_notes?: string | null
  cancellation_reason?: string | null
  no_show_reason?: string | null
  confirmed_at?: string | null
  checked_in_at?: string | null
  checked_out_at?: string | null
  cancelled_at?: string | null
  created_at: string
  payments?: PaymentTransactionRecord[]
  refunds?: PaymentRefundRecord[]
  status_histories?: Array<{
    id: number
    from_status: string | null
    to_status: string
    from_payment_status: string | null
    to_payment_status: string | null
    reason: string | null
    actor?: { id: number; name: string } | null
    created_at: string
  }>
}

const ALLOWED_TAGS = new Set([
  'P', 'BR', 'STRONG', 'B', 'EM', 'I', 'U', 'UL', 'OL', 'LI',
  'H2', 'H3', 'H4', 'BLOCKQUOTE', 'A', 'CODE', 'PRE', 'HR', 'SPAN',
])

/**
 * Defense-in-depth client-side HTML sanitizer (server also sanitizes via DOMDocument).
 */
export function sanitizeHtml(dirty?: string | null): string {
  if (!dirty) return ''
  if (typeof DOMParser === 'undefined') return dirty
  const doc = new DOMParser().parseFromString(dirty, 'text/html')
  const walk = (node: Node) => {
    for (const child of Array.from(node.childNodes)) {
      if (child.nodeType === Node.ELEMENT_NODE) {
        const el = child as Element
        const tag = el.tagName.toUpperCase()
        if (['SCRIPT', 'STYLE', 'IFRAME', 'OBJECT', 'EMBED', 'SVG', 'FORM'].includes(tag)) {
          el.remove()
          continue
        }
        walk(el)
        if (!ALLOWED_TAGS.has(tag)) {
          while (el.firstChild) node.insertBefore(el.firstChild, el)
          el.remove()
          continue
        }
        for (const attr of Array.from(el.attributes)) {
          const name = attr.name.toLowerCase()
          if (tag !== 'A' || !['href', 'title', 'target', 'rel'].includes(name)) {
            el.removeAttribute(attr.name)
          } else if (name === 'href') {
            const v = attr.value.trim().toLowerCase()
            if (v.startsWith('javascript:') || v.startsWith('data:') || v.startsWith('vbscript:')) {
              el.removeAttribute(attr.name)
            }
          }
        }
      }
    }
  }
  walk(doc.body)
  return doc.body.innerHTML
}

export function useSeo(opts: { title?: string | null; description?: string | null; canonical?: string | null; noindex?: boolean }) {
  useEffect(() => {
    if (opts.title) document.title = opts.title
    const setMeta = (selector: string, attr: 'name' | 'property', key: string, content: string) => {
      let el = document.querySelector<HTMLMetaElement>(selector)
      if (!el) {
        el = document.createElement('meta')
        el.setAttribute(attr, key)
        document.head.appendChild(el)
      }
      el.setAttribute('content', content)
    }
    if (opts.description) {
      setMeta('meta[name="description"]', 'name', 'description', opts.description)
      setMeta('meta[property="og:description"]', 'property', 'og:description', opts.description)
    }
    if (opts.title) {
      setMeta('meta[property="og:title"]', 'property', 'og:title', opts.title)
    }
    setMeta('meta[name="robots"]', 'name', 'robots', opts.noindex ? 'noindex,nofollow' : 'index,follow')
  }, [opts.title, opts.description, opts.canonical, opts.noindex])
}

export function formatCurrency(amount: number | null | undefined, currency = 'NGN'): string {
  if (amount === null || amount === undefined) return 'Rate on inquiry'
  try {
    return new Intl.NumberFormat('en-NG', { style: 'currency', currency, maximumFractionDigits: 0 }).format(amount)
  } catch {
    return `${currency} ${amount.toLocaleString()}`
  }
}
