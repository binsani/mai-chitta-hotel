import { useState, type ReactNode } from 'react'
import { Link, Navigate, useLocation, useNavigate, useSearchParams } from 'react-router-dom'
import { useForm } from 'react-hook-form'
import { z } from 'zod'
import { Hotel } from 'lucide-react'
import { useAuth } from '@/auth/AuthProvider'
import { api, csrf, errorMessage } from '@/lib/api'
import { zodResolver } from '@/lib/utils'
import { Alert, Button, Card, FormField, Input } from '@/components/ui'

function AuthShell({ title, subtitle, children }: { title: string; subtitle?: string; children: ReactNode }) {
  return (
    <div className="flex min-h-full items-center justify-center bg-gradient-to-br from-brand-900 to-brand-700 px-4 py-12">
      <div className="w-full max-w-md">
        <div className="mb-6 flex flex-col items-center text-center text-white">
          <Hotel className="mb-2 h-10 w-10 text-brand-200" aria-hidden />
          <p className="text-xl font-semibold">Mai Chitta Hotel</p>
          <p className="text-sm text-brand-100/80">Staff portal</p>
        </div>
        <Card className="p-6 sm:p-8">
          <h1 className="text-lg font-semibold text-slate-900">{title}</h1>
          {subtitle && <p className="mt-1 text-sm text-slate-500">{subtitle}</p>}
          <div className="mt-6">{children}</div>
        </Card>
        <p className="mt-4 text-center text-xs text-brand-100/80">
          <Link to="/" className="hover:underline">Return to public website</Link>
        </p>
      </div>
    </div>
  )
}

const loginSchema = z.object({
  email: z.string().trim().email('Enter a valid email address'),
  password: z.string().min(1, 'Password is required'),
  remember: z.boolean(),
})
type LoginValues = z.infer<typeof loginSchema>

export function LoginPage() {
  const { user, login } = useAuth()
  const navigate = useNavigate()
  const location = useLocation()
  const [error, setError] = useState<string | null>(null)
  const { register, handleSubmit, formState: { errors, isSubmitting } } = useForm<LoginValues>({
    resolver: zodResolver(loginSchema), defaultValues: { email: '', password: '', remember: false },
  })
  const requestedPath = (location.state as { from?: string } | null)?.from
  const from = requestedPath?.startsWith('/admin/') && ! /^\/admin\/(login|forgot-password|reset-password)(\/|\?|#|$)/.test(requestedPath)
    ? requestedPath
    : '/admin'
  if (user) return <Navigate to={from} replace />

  const onSubmit = handleSubmit(async (v) => {
    setError(null)
    try {
      await login(v.email, v.password, v.remember)
      navigate(from, { replace: true })
    } catch (e) {
      setError(errorMessage(e, 'Unable to sign in.'))
    }
  })

  return (
    <AuthShell title="Sign in" subtitle="Use your staff account to continue.">
      <form onSubmit={onSubmit} noValidate className="space-y-4">
        {error && <Alert variant="error">{error}</Alert>}
        <FormField id="email" label="Email address" error={errors.email?.message}>
          <Input id="email" type="email" autoComplete="username" autoFocus aria-invalid={!!errors.email} {...register('email')} />
        </FormField>
        <FormField id="password" label="Password" error={errors.password?.message}>
          <Input id="password" type="password" autoComplete="current-password" aria-invalid={!!errors.password} {...register('password')} />
        </FormField>
        <div className="flex items-center justify-between text-sm">
          <label className="flex items-center gap-2 text-slate-600"><input type="checkbox" className="rounded border-slate-300" {...register('remember')} /> Keep me signed in</label>
          <Link to="/admin/forgot-password" className="font-medium text-brand-700 hover:underline">Forgot password?</Link>
        </div>
        <Button type="submit" className="w-full" loading={isSubmitting}>Sign in</Button>
      </form>
    </AuthShell>
  )
}

const forgotSchema = z.object({ email: z.string().trim().email('Enter a valid email address') })

export function ForgotPasswordPage() {
  const [done, setDone] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const { register, handleSubmit, formState: { errors, isSubmitting } } = useForm<z.infer<typeof forgotSchema>>({ resolver: zodResolver(forgotSchema), defaultValues: { email: '' } })
  const onSubmit = handleSubmit(async (v) => {
    setError(null)
    try {
      await csrf()
      const res = await api.post<{ message: string }>('/auth/forgot-password', v)
      setDone(res.data.message)
    } catch (e) { setError(errorMessage(e)) }
  })
  return (
    <AuthShell title="Reset your password" subtitle="We'll email you a link to choose a new password.">
      {done ? (
        <div className="space-y-4"><Alert variant="success">{done}</Alert><Link to="/admin/login" className="text-sm font-medium text-brand-700 hover:underline">Back to sign in</Link></div>
      ) : (
        <form onSubmit={onSubmit} noValidate className="space-y-4">
          {error && <Alert variant="error">{error}</Alert>}
          <FormField id="email" label="Email address" error={errors.email?.message}>
            <Input id="email" type="email" autoComplete="username" aria-invalid={!!errors.email} {...register('email')} />
          </FormField>
          <Button type="submit" className="w-full" loading={isSubmitting}>Send reset link</Button>
          <p className="text-center text-sm"><Link to="/admin/login" className="text-brand-700 hover:underline">Back to sign in</Link></p>
        </form>
      )}
    </AuthShell>
  )
}

const resetSchema = z.object({
  password: z.string().min(8, 'At least 8 characters'),
  password_confirmation: z.string(),
}).refine((d) => d.password === d.password_confirmation, { message: 'Passwords do not match', path: ['password_confirmation'] })

export function ResetPasswordPage() {
  const [params] = useSearchParams()
  const navigate = useNavigate()
  const token = params.get('token') ?? ''
  const email = params.get('email') ?? ''
  const [error, setError] = useState<string | null>(null)
  const { register, handleSubmit, formState: { errors, isSubmitting } } = useForm<z.infer<typeof resetSchema>>({ resolver: zodResolver(resetSchema), defaultValues: { password: '', password_confirmation: '' } })
  const onSubmit = handleSubmit(async (v) => {
    setError(null)
    try {
      await csrf()
      await api.post('/auth/reset-password', { ...v, token, email })
      navigate('/admin/login', { replace: true, state: { reset: true } })
    } catch (e) { setError(errorMessage(e)) }
  })
  if (!token || !email) return <AuthShell title="Invalid link"><Alert variant="error">This password reset link is incomplete or invalid.</Alert></AuthShell>
  return (
    <AuthShell title="Choose a new password" subtitle={email}>
      <form onSubmit={onSubmit} noValidate className="space-y-4">
        {error && <Alert variant="error">{error}</Alert>}
        <FormField id="password" label="New password" error={errors.password?.message}>
          <Input id="password" type="password" autoComplete="new-password" {...register('password')} />
        </FormField>
        <FormField id="password_confirmation" label="Confirm new password" error={errors.password_confirmation?.message}>
          <Input id="password_confirmation" type="password" autoComplete="new-password" {...register('password_confirmation')} />
        </FormField>
        <Button type="submit" className="w-full" loading={isSubmitting}>Reset password</Button>
      </form>
    </AuthShell>
  )
}
