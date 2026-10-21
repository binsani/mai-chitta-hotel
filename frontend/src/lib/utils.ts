import { clsx, type ClassValue } from 'clsx'
import { twMerge } from 'tailwind-merge'
import type { FieldErrors, FieldValues, Resolver } from 'react-hook-form'
import type { ZodType } from 'zod'

export function cn(...inputs: ClassValue[]) {
  return twMerge(clsx(inputs))
}

/** Minimal Zod → react-hook-form resolver (avoids a peer-dependency conflict in @hookform/resolvers). */
export function zodResolver<T extends FieldValues>(schema: ZodType<T>): Resolver<T> {
  return async (values) => {
    const result = await schema.safeParseAsync(values)
    if (result.success) return { values: result.data, errors: {} }
    const errors: Record<string, { type: string; message: string }> = {}
    for (const issue of result.error.issues) {
      const key = issue.path.join('.')
      if (!errors[key]) errors[key] = { type: issue.code, message: issue.message }
    }
    // Nest dotted keys so RHF can find e.g. errors.hotel.name
    const nested: Record<string, unknown> = {}
    for (const [k, v] of Object.entries(errors)) {
      const parts = k.split('.')
      let cur = nested
      parts.forEach((p, i) => {
        if (i === parts.length - 1) cur[p] = v
        else cur = (cur[p] ??= {}) as Record<string, unknown>
      })
    }
    return { values: {}, errors: nested as FieldErrors<T> }
  }
}

export function formatDateTime(iso?: string | null, tz = 'Africa/Lagos') {
  if (!iso) return '—'
  return new Intl.DateTimeFormat('en-NG', { dateStyle: 'medium', timeStyle: 'short', timeZone: tz }).format(new Date(iso))
}
