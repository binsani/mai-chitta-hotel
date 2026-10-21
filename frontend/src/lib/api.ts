import axios, { AxiosError } from 'axios'

/**
 * Same-origin Sanctum SPA client. Auth uses the HttpOnly session cookie;
 * nothing sensitive is kept in localStorage. Axios automatically copies the
 * XSRF-TOKEN cookie into the X-XSRF-TOKEN header.
 */
export const api = axios.create({
  baseURL: '/api/v1',
  withCredentials: true,
  withXSRFToken: true,
  headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
})

export const csrf = () => axios.get('/sanctum/csrf-cookie', { withCredentials: true })

// Retry once on CSRF token mismatch (419), e.g. after session expiry.
api.interceptors.response.use(undefined, async (error: AxiosError) => {
  const cfg = error.config as (typeof error.config & { _retried?: boolean }) | undefined
  if (error.response?.status === 419 && cfg && !cfg._retried) {
    cfg._retried = true
    await csrf()
    return api(cfg)
  }
  if (error.response?.status === 401) {
    window.dispatchEvent(new Event('auth:unauthenticated'))
  }
  return Promise.reject(error)
})

export type ApiValidationError = { message: string; errors?: Record<string, string[]> }

export function errorMessage(err: unknown, fallback = 'Something went wrong. Please try again.'): string {
  if (err instanceof AxiosError) {
    const data = err.response?.data as ApiValidationError | undefined
    if (err.response?.status === 403) return 'You do not have permission to perform this action.'
    if (err.response?.status === 429) return 'Too many attempts. Please wait a minute and try again.'
    if (data?.errors) return Object.values(data.errors).flat()[0] ?? data.message
    if (data?.message) return data.message
    if (!err.response) return 'Cannot reach the server. Check your connection.'
  }
  return fallback
}

export function fieldErrors(err: unknown): Record<string, string> {
  if (err instanceof AxiosError) {
    const data = err.response?.data as ApiValidationError | undefined
    if (data?.errors) return Object.fromEntries(Object.entries(data.errors).map(([k, v]) => [k, v[0] ?? '']))
  }
  return {}
}

export type Paginated<T> = {
  data: T[]
  meta?: { current_page: number; last_page: number; total: number; per_page: number }
  current_page?: number
  last_page?: number
  total?: number
}
