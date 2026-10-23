import { useState } from 'react'
import { Link, useSearchParams } from 'react-router-dom'
import { useMutation, useQuery } from '@tanstack/react-query'
import { BedDouble, Calendar, CheckCircle2, Clock, ShieldCheck } from 'lucide-react'
import { api, csrf, errorMessage } from '@/lib/api'
import { formatCurrency, type AvailabilitySearchOption, type BookingQuote, type ReservationRecord } from '@/lib/cms'
import { formatDateTime } from '@/lib/utils'
import { Alert, Badge, Button, Card, EmptyState, ErrorState, FormField, Input, Spinner, Textarea } from '@/components/ui'
import { usePublicBootstrap } from '@/lib/public-context'
import { CmsPageView } from '@/pages/public'

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

  const preferredRoomType = searchParams.get('room_type_id')
  const availabilityOptions = (availQuery.data?.options ?? []).filter(option => !preferredRoomType || String(option.room_type.id) === preferredRoomType)

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
                  ...(preferredRoomType ? { room_type_id: preferredRoomType } : {}),
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

          {preferredRoomType && <div className="flex flex-wrap items-center gap-3 text-sm text-slate-600"><span>Showing your selected room type.</span><Button variant="secondary" size="sm" onClick={() => { const next = new URLSearchParams(searchParams); next.delete('room_type_id'); setSearchParams(next) }}>Show all room types</Button></div>}

          {!booking?.online_booking_enabled && (
            <Alert variant="info" title="Online Booking Notice">
              Online instant booking is currently in setup mode or managed by the reservation desk. If room inventory and rate plans have been configured by hotel administration, you may preview quotes below or contact reception directly.
            </Alert>
          )}

          {availQuery.isLoading ? (
            <Spinner label="Checking room availability and calculating exact quotes…" />
          ) : availQuery.isError ? (
            <ErrorState message={errorMessage(availQuery.error)} onRetry={() => availQuery.refetch()} />
          ) : availQuery.data && availabilityOptions.length > 0 ? (
            <div className="space-y-6">
              {availabilityOptions.map((opt) => (
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
