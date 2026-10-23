import { useState } from 'react'
import { useMutation } from '@tanstack/react-query'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { api, csrf, errorMessage } from '@/lib/api'
import { zodResolver } from '@/lib/utils'
import { Alert, Button, Card, FormField, Input, Textarea } from '@/components/ui'
import { CmsPageView } from '@/pages/public'
import { usePublicBootstrap } from '@/lib/public-context'

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
