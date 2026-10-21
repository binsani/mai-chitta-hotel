import { forwardRef, type ButtonHTMLAttributes, type InputHTMLAttributes, type ReactNode, type SelectHTMLAttributes, type TextareaHTMLAttributes } from 'react'
import { Slot } from '@radix-ui/react-slot'
import * as LabelPrimitive from '@radix-ui/react-label'
import * as Dialog from '@radix-ui/react-dialog'
import { cva, type VariantProps } from 'class-variance-authority'
import { AlertCircle, CheckCircle2, Info, Inbox, Loader2, TriangleAlert, X } from 'lucide-react'
import { cn } from '@/lib/utils'

/* ---------- Button ---------- */
const buttonVariants = cva(
  'inline-flex items-center justify-center gap-2 rounded-md text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-brand-500 focus-visible:ring-offset-2 disabled:pointer-events-none disabled:opacity-50',
  {
    variants: {
      variant: {
        primary: 'bg-brand-700 text-white hover:bg-brand-800',
        secondary: 'border border-slate-300 bg-white text-slate-800 hover:bg-slate-50',
        ghost: 'text-slate-700 hover:bg-slate-100',
        danger: 'bg-red-600 text-white hover:bg-red-700',
      },
      size: { sm: 'h-8 px-3', md: 'h-10 px-4', icon: 'h-9 w-9' },
    },
    defaultVariants: { variant: 'primary', size: 'md' },
  },
)
type ButtonProps = ButtonHTMLAttributes<HTMLButtonElement> & VariantProps<typeof buttonVariants> & { asChild?: boolean; loading?: boolean }
export const Button = forwardRef<HTMLButtonElement, ButtonProps>(({ className, variant, size, asChild, loading, children, disabled, ...props }, ref) => {
  const Comp = asChild ? Slot : 'button'
  return (
    <Comp ref={ref} className={cn(buttonVariants({ variant, size }), className)} disabled={disabled || loading} {...props}>
      {asChild ? children : <>{loading && <Loader2 className="h-4 w-4 animate-spin" aria-hidden />}{children}</>}
    </Comp>
  )
})
Button.displayName = 'Button'

/* ---------- Form controls ---------- */
const inputBase = 'block w-full rounded-md border border-slate-300 bg-white px-3 py-2 text-sm shadow-sm placeholder:text-slate-400 focus:border-brand-500 focus:outline-none focus:ring-2 focus:ring-brand-500/30 disabled:bg-slate-100 aria-[invalid=true]:border-red-500'
export const Input = forwardRef<HTMLInputElement, InputHTMLAttributes<HTMLInputElement>>(({ className, ...p }, ref) => (
  <input ref={ref} className={cn(inputBase, className)} {...p} />
))
Input.displayName = 'Input'
export const Select = forwardRef<HTMLSelectElement, SelectHTMLAttributes<HTMLSelectElement>>(({ className, ...p }, ref) => (
  <select ref={ref} className={cn(inputBase, className)} {...p} />
))
Select.displayName = 'Select'
export const Textarea = forwardRef<HTMLTextAreaElement, TextareaHTMLAttributes<HTMLTextAreaElement>>(({ className, ...p }, ref) => (
  <textarea ref={ref} className={cn(inputBase, className)} {...p} />
))
Textarea.displayName = 'Textarea'

export function Label({ className, ...p }: LabelPrimitive.LabelProps) {
  return <LabelPrimitive.Root className={cn('mb-1 block text-sm font-medium text-slate-700', className)} {...p} />
}

export function FormField({ id, label, error, hint, children }: { id: string; label: string; error?: string; hint?: string; children: ReactNode }) {
  return (
    <div>
      <Label htmlFor={id}>{label}</Label>
      {children}
      {hint && !error && <p className="mt-1 text-xs text-slate-500">{hint}</p>}
      {error && <p id={`${id}-error`} role="alert" className="mt-1 text-xs text-red-600">{error}</p>}
    </div>
  )
}

/* ---------- Layout primitives ---------- */
export function Card({ className, ...p }: React.HTMLAttributes<HTMLDivElement>) {
  return <div className={cn('rounded-lg border border-slate-200 bg-white shadow-sm', className)} {...p} />
}
export function CardHeader({ title, description, actions }: { title: string; description?: string; actions?: ReactNode }) {
  return (
    <div className="flex flex-wrap items-start justify-between gap-3 border-b border-slate-200 px-5 py-4">
      <div>
        <h2 className="text-base font-semibold text-slate-900">{title}</h2>
        {description && <p className="mt-0.5 text-sm text-slate-500">{description}</p>}
      </div>
      {actions}
    </div>
  )
}
export function PageHeader({ title, description, actions }: { title: string; description?: string; actions?: ReactNode }) {
  return (
    <div className="mb-6 flex flex-wrap items-end justify-between gap-3">
      <div>
        <h1 className="text-2xl font-semibold tracking-tight text-slate-900">{title}</h1>
        {description && <p className="mt-1 text-sm text-slate-500">{description}</p>}
      </div>
      {actions}
    </div>
  )
}

/* ---------- Feedback ---------- */
const alertStyles = {
  info: ['border-sky-200 bg-sky-50 text-sky-900', Info],
  success: ['border-emerald-200 bg-emerald-50 text-emerald-900', CheckCircle2],
  warning: ['border-amber-200 bg-amber-50 text-amber-900', TriangleAlert],
  error: ['border-red-200 bg-red-50 text-red-900', AlertCircle],
} as const
export function Alert({ variant = 'info', title, children }: { variant?: keyof typeof alertStyles; title?: string; children?: ReactNode }) {
  const [cls, Icon] = alertStyles[variant]
  return (
    <div role={variant === 'error' ? 'alert' : 'status'} className={cn('flex gap-3 rounded-md border p-3 text-sm', cls)}>
      <Icon className="mt-0.5 h-4 w-4 shrink-0" aria-hidden />
      <div>{title && <p className="font-medium">{title}</p>}{children}</div>
    </div>
  )
}

const badgeTones = {
  neutral: 'bg-slate-100 text-slate-700', success: 'bg-emerald-100 text-emerald-800',
  danger: 'bg-red-100 text-red-800', warning: 'bg-amber-100 text-amber-800', brand: 'bg-brand-100 text-brand-800',
}
export function Badge({ tone = 'neutral', children }: { tone?: keyof typeof badgeTones; children: ReactNode }) {
  return <span className={cn('inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium', badgeTones[tone])}>{children}</span>
}

export function Spinner({ label = 'Loading…' }: { label?: string }) {
  return (
    <div className="flex items-center justify-center gap-2 py-12 text-sm text-slate-500" role="status">
      <Loader2 className="h-5 w-5 animate-spin" aria-hidden /> {label}
    </div>
  )
}

export function EmptyState({ title, description, icon: Icon = Inbox, action }: { title: string; description?: string; icon?: React.ComponentType<{ className?: string }>; action?: ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center px-6 py-14 text-center">
      <Icon className="mb-3 h-10 w-10 text-slate-300" />
      <p className="font-medium text-slate-800">{title}</p>
      {description && <p className="mt-1 max-w-md text-sm text-slate-500">{description}</p>}
      {action && <div className="mt-4">{action}</div>}
    </div>
  )
}

export function ErrorState({ message, onRetry }: { message: string; onRetry?: () => void }) {
  return (
    <div className="p-5">
      <Alert variant="error" title="Unable to load">
        <p>{message}</p>
        {onRetry && <Button variant="secondary" size="sm" className="mt-2" onClick={onRetry}>Try again</Button>}
      </Alert>
    </div>
  )
}

/* ---------- Modal (Radix Dialog: focus trap, Esc, aria) ---------- */
export function Modal({ open, onOpenChange, title, description, children, wide }: {
  open: boolean; onOpenChange: (o: boolean) => void; title: string; description?: string; children: ReactNode; wide?: boolean
}) {
  return (
    <Dialog.Root open={open} onOpenChange={onOpenChange}>
      <Dialog.Portal>
        <Dialog.Overlay className="fixed inset-0 z-40 bg-slate-900/40" />
        <Dialog.Content className={cn('fixed left-1/2 top-1/2 z-50 max-h-[90vh] w-[95vw] -translate-x-1/2 -translate-y-1/2 overflow-y-auto rounded-lg bg-white p-6 shadow-xl', wide ? 'max-w-4xl' : 'max-w-lg')}>
          <div className="mb-4 flex items-start justify-between gap-4">
            <div>
              <Dialog.Title className="text-lg font-semibold text-slate-900">{title}</Dialog.Title>
              {description ? <Dialog.Description className="text-sm text-slate-500">{description}</Dialog.Description> : <Dialog.Description className="sr-only">{title}</Dialog.Description>}
            </div>
            <Dialog.Close asChild><Button variant="ghost" size="icon" aria-label="Close"><X className="h-4 w-4" /></Button></Dialog.Close>
          </div>
          {children}
        </Dialog.Content>
      </Dialog.Portal>
    </Dialog.Root>
  )
}

export function ConfirmDialog({ open, onClose, onConfirm, title, description, confirmLabel = 'Confirm', variant = 'danger', loading }: {
  open: boolean; onClose: () => void; onConfirm: () => void; title: string; description: string; confirmLabel?: string; variant?: 'primary' | 'danger'; loading?: boolean
}) {
  return (
    <Modal open={open} onOpenChange={(o) => !o && onClose()} title={title} description={description}>
      <div className="mt-4 flex justify-end gap-2">
        <Button type="button" variant="secondary" onClick={onClose}>Cancel</Button>
        <Button type="button" variant={variant} loading={loading} onClick={onConfirm}>{confirmLabel}</Button>
      </div>
    </Modal>
  )
}

/* ---------- Data table ---------- */
export type Column<T> = { key: string; header: string; cell: (row: T) => ReactNode; className?: string }
export function DataTable<T>({ columns, rows, rowKey, isLoading, error, onRetry, empty, page, lastPage, onPage }: {
  columns: Column<T>[]; rows: T[] | undefined; rowKey: (r: T) => string | number
  isLoading?: boolean; error?: string | null; onRetry?: () => void; empty?: ReactNode
  page?: number; lastPage?: number; onPage?: (p: number) => void
}) {
  if (isLoading) return <Spinner />
  if (error) return <ErrorState message={error} onRetry={onRetry} />
  if (!rows?.length) return <>{empty ?? <EmptyState title="Nothing here yet" />}</>
  return (
    <div>
      <div className="overflow-x-auto">
        <table className="min-w-full divide-y divide-slate-200 text-sm">
          <thead className="bg-slate-50">
            <tr>{columns.map((c) => <th key={c.key} scope="col" className={cn('px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide text-slate-500', c.className)}>{c.header}</th>)}</tr>
          </thead>
          <tbody className="divide-y divide-slate-100">
            {rows.map((r) => (
              <tr key={rowKey(r)} className="hover:bg-slate-50">
                {columns.map((c) => <td key={c.key} className={cn('px-4 py-3 align-top text-slate-700', c.className)}>{c.cell(r)}</td>)}
              </tr>
            ))}
          </tbody>
        </table>
      </div>
      {onPage && page && lastPage && lastPage > 1 && (
        <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3 text-sm text-slate-600">
          <span>Page {page} of {lastPage}</span>
          <div className="flex gap-2">
            <Button variant="secondary" size="sm" disabled={page <= 1} onClick={() => onPage(page - 1)}>Previous</Button>
            <Button variant="secondary" size="sm" disabled={page >= lastPage} onClick={() => onPage(page + 1)}>Next</Button>
          </div>
        </div>
      )}
    </div>
  )
}
