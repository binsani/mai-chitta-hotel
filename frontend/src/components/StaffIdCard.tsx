import { useRef, useState } from 'react'
import { useQuery } from '@tanstack/react-query'
import { api, errorMessage } from '@/lib/api'
import type { PublicBootstrap } from '@/lib/cms'
import { Alert, Button, FormField, Input, Modal } from '@/components/ui'

type CardEmployee = { id: number; name: string; staff_number: string; job_title: string; department: string; status: string }
const cardStyles = `
.hotel-id-sheet{display:flex;flex-wrap:wrap;gap:10mm;font-family:Arial,sans-serif;color:#172026}
.hotel-id{box-sizing:border-box;width:85.6mm;height:54mm;border:1px solid #ac915d;border-radius:3mm;overflow:hidden;background:#fff;position:relative;break-inside:avoid;print-color-adjust:exact;-webkit-print-color-adjust:exact}
.hotel-id *{box-sizing:border-box;margin:0}
.hotel-id-header{height:11mm;background:#24372f;color:#fff;padding:2mm 4mm;display:flex;align-items:center;gap:3mm}
.hotel-id-logo{width:7mm;height:7mm;object-fit:contain;border-radius:1mm;background:#fff}
.hotel-id-hotel{font-size:12px;font-weight:bold;line-height:1.15;overflow-wrap:anywhere}
.hotel-id-label{font-size:7px;letter-spacing:2px;margin-top:1mm}
.hotel-id-main{display:flex;gap:3mm;padding:3mm 4mm;height:35mm}
.hotel-id-photo{flex-shrink:0;width:22mm;height:28mm;object-fit:cover;background:#eee8db;border:1px solid #d2c5aa;border-radius:1mm;display:flex;align-items:center;justify-content:center;font-size:24px;color:#60502f}
.hotel-id-details{min-width:0;flex:1;overflow-wrap:anywhere}
.hotel-id-name{font-size:13px;font-weight:bold;line-height:1.15;margin-bottom:1mm}
.hotel-id-position{font-size:9px;line-height:1.15;margin-bottom:1mm}
.hotel-id-number{font-size:9px;font-weight:bold;margin-top:2mm}
.hotel-id-footer{position:absolute;bottom:0;width:100%;height:7mm;background:#f4f0e7;padding:2mm 4mm;font-size:7px;display:flex;justify-content:space-between;gap:2mm}
.hotel-id-back{padding:4mm;font-size:9px;line-height:1.35;overflow-wrap:anywhere}
.hotel-id-back h3{font-size:11px;margin-bottom:2mm;color:#24372f}
.hotel-id-back p{margin-bottom:2mm}
.hotel-id-back small{font-size:8px}
@media print{@page{size:A4;margin:12mm}body{margin:0}.hotel-id-sheet{gap:10mm}.hotel-id-print-controls{display:none!important}}
`
const today = () => new Date().toLocaleDateString('en-CA', { timeZone: 'Africa/Lagos' })
const dateLabel = (value: string) => new Date(`${value}T12:00:00`).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })

export function StaffIdCard({ employee, onClose }: { employee: CardEmployee; onClose: () => void }) {
  const [issued, setIssued] = useState(today)
  const [expires, setExpires] = useState('')
  const [photo, setPhoto] = useState('')
  const [photoLoading, setPhotoLoading] = useState(false)
  const [printing, setPrinting] = useState(false)
  const [error, setError] = useState('')
  const cardRef = useRef<HTMLDivElement>(null)
  const readerRef = useRef<FileReader | null>(null)
  const hotelQuery = useQuery({ queryKey: ['public', 'bootstrap'], queryFn: async () => (await api.get<{ data: PublicBootstrap }>('/public/bootstrap')).data.data })
  const hotel = hotelQuery.data?.settings.hotel
  const validDates = /^\d{4}-\d{2}-\d{2}$/.test(issued) && /^\d{4}-\d{2}-\d{2}$/.test(expires) && issued <= today() && expires > issued && expires >= today()
  const initials = employee.name.trim().split(/\s+/).slice(0, 2).map(part => part[0]).join('')

  async function printCard() {
    if (!cardRef.current || !validDates || !hotel || employee.status === 'inactive') return
    setError('')
    const printWindow = window.open('', '_blank', 'width=850,height=650')
    if (!printWindow) { setError('Please allow pop-ups for this website, then try printing again.'); return }
    setPrinting(true)
    try {
      const doc = printWindow.document
      doc.title = `Staff ID — ${employee.staff_number}`
      const style = doc.createElement('style')
      style.textContent = `${cardStyles}body{padding:20px;background:#fff}.hotel-id-print-controls{font:14px Arial,sans-serif;margin-bottom:24px}`
      doc.head.append(style)
      const controls = doc.createElement('div')
      controls.className = 'hotel-id-print-controls'
      const button = doc.createElement('button')
      button.textContent = 'Print / Save as PDF'
      button.addEventListener('click', () => printWindow.print())
      const note = doc.createElement('p')
      note.textContent = 'Print at 100% / Actual size. Turn off headers and footers. Each side measures 85.6 × 54 mm; cut and mount back-to-back.'
      controls.append(button, note)
      doc.body.append(controls, doc.importNode(cardRef.current, true))
      printWindow.opener = null
      await Promise.all(Array.from(doc.images).map(image => image.decode()))
      printWindow.focus()
      printWindow.print()
    } catch {
      printWindow.close()
      setError('The card images could not load. Check the photo and your connection, then try again.')
    } finally { setPrinting(false) }
  }

  return <Modal open onOpenChange={open => { if (!open) { readerRef.current?.abort(); onClose() } }} title="Generate staff ID card" description={`${employee.name} · ${employee.staff_number}`} wide>
    <style>{cardStyles}</style>
    <form className="space-y-5" onSubmit={event => { event.preventDefault(); void printCard() }}>
      {error && <Alert variant="error">{error}</Alert>}
      {hotelQuery.isError && <Alert variant="error">{errorMessage(hotelQuery.error)} <Button type="button" variant="secondary" size="sm" onClick={() => hotelQuery.refetch()}>Retry hotel details</Button></Alert>}
      <div className="grid gap-4 sm:grid-cols-2">
        <FormField id="id-issued" label="Issue date"><Input id="id-issued" type="date" required max={today()} value={issued} onChange={event => setIssued(event.target.value)} /></FormField>
        <FormField id="id-expires" label="Expiry date"><Input id="id-expires" type="date" required min={issued > today() ? issued : today()} value={expires} onChange={event => setExpires(event.target.value)} /></FormField>
        <FormField id="id-photo" label="Staff photo (optional)" hint="JPEG, PNG or WebP, up to 2 MB. The photo is used for this card only and is not saved to the staff record.">
          <Input id="id-photo" type="file" accept="image/jpeg,image/png,image/webp" onChange={event => {
            readerRef.current?.abort()
            setPhoto(''); setPhotoLoading(false); setError('')
            const file = event.target.files?.[0]
            if (!file) return
            if (!['image/jpeg', 'image/png', 'image/webp'].includes(file.type) || file.size > 2 * 1024 * 1024) { setError('Choose a JPEG, PNG or WebP photo no larger than 2 MB.'); event.target.value = ''; return }
            const reader = new FileReader()
            readerRef.current = reader
            setPhotoLoading(true)
            reader.onload = () => { setPhoto(String(reader.result)); setPhotoLoading(false) }
            reader.onerror = () => { setError('Could not read this photo. Please select another image.'); setPhotoLoading(false) }
            reader.readAsDataURL(file)
          }} />
        </FormField>
      </div>
      {!validDates && <p className="text-sm text-slate-600">Choose an expiry date after the issue date and on or after today.</p>}
      {hotel && <div className="overflow-x-auto rounded-lg bg-slate-100 p-4" aria-label="Staff ID card preview">
        <div className="hotel-id-sheet" ref={cardRef}>
          <section className="hotel-id" aria-label="Front of staff ID card">
            <header className="hotel-id-header"><img className="hotel-id-logo" src={new URL('/logo.webp', window.location.origin).href} alt="Hotel logo" /><div><p className="hotel-id-hotel">{hotel.name}</p><p className="hotel-id-label">STAFF IDENTIFICATION</p></div></header>
            <div className="hotel-id-main">
              {photo ? <img className="hotel-id-photo" src={photo} alt={`${employee.name} portrait`} onError={() => { setPhoto(''); setError('This photo is not a readable image. Please select another photo.') }} /> : <div className="hotel-id-photo" aria-label="No staff photo">{initials}</div>}
              <div className="hotel-id-details"><h2 className="hotel-id-name" style={{ fontSize: employee.name.length > 45 ? '9px' : undefined }}>{employee.name}</h2><p className="hotel-id-position">{employee.job_title}</p><p className="hotel-id-position">{employee.department}</p><p className="hotel-id-number">ID: {employee.staff_number}</p></div>
            </div>
            <footer className="hotel-id-footer"><span>Issued: {issued ? dateLabel(issued) : '—'}</span><span>Expires: {expires ? dateLabel(expires) : '—'}</span></footer>
          </section>
          <section className="hotel-id" aria-label="Back of staff ID card"><div className="hotel-id-back"><h3>{hotel.name}</h3><p>This card identifies the named employee. Valid only during employment and until the expiry date. It must be returned when employment ends.</p><p>If found, please return to the hotel reception.</p><p><strong>{[hotel.address, hotel.city, hotel.state, hotel.country].filter(Boolean).join(', ')}</strong>{hotel.phone && <><br />{hotel.phone}</>}</p><small>This card does not authorize payments or access to restricted areas.</small></div></section>
        </div>
      </div>}
      <Button type="submit" loading={printing || photoLoading || hotelQuery.isLoading} disabled={!validDates || !hotel || employee.status === 'inactive'}>Print / Save as PDF</Button>
      <p className="text-xs text-slate-500">Front and back print at standard card size (85.6 × 54 mm). Use 100% / Actual size and disable browser headers and footers.</p>
    </form>
  </Modal>
}
