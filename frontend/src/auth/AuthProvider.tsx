import { createContext, useCallback, useContext, useEffect, type ReactNode } from 'react'
import { useQuery, useQueryClient } from '@tanstack/react-query'
import { api, csrf } from '@/lib/api'
import { AUTH_USER_QUERY_KEY as ME, replaceAuthSession } from './session'

export type Role = { id: number; slug: string; name: string }
export type User = {
  id: number
  name: string
  email: string
  phone: string | null
  is_active: boolean
  last_login_at: string | null
  created_at: string | null
  roles?: Role[]
  permissions?: string[]
}

type AuthContextValue = {
  user: User | null
  isLoading: boolean
  login: (email: string, password: string, remember: boolean) => Promise<void>
  logout: () => Promise<void>
  can: (...permissions: string[]) => boolean
}

const AuthContext = createContext<AuthContextValue | null>(null)
export function AuthProvider({ children }: { children: ReactNode }) {
  const qc = useQueryClient()
  const { data: user = null, isLoading } = useQuery({
    queryKey: ME,
    queryFn: async ({ signal }) => {
      try {
        return (await api.get<{ data: User }>('/auth/user', { signal })).data.data
      } catch (e: unknown) {
        if ((e as { response?: { status?: number } }).response?.status === 401) return null
        throw e
      }
    },
    staleTime: 5 * 60_000,
    retry: false,
  })

  // Session expired / revoked anywhere → drop cached user and go to login.
  useEffect(() => {
    const onUnauth = () => qc.setQueryData(ME, null)
    window.addEventListener('auth:unauthenticated', onUnauth)
    return () => window.removeEventListener('auth:unauthenticated', onUnauth)
  }, [qc])

  const login = useCallback(async (email: string, password: string, remember: boolean) => {
    await qc.cancelQueries()
    await csrf()
    const res = await api.post<{ user: { data?: User } & User }>('/auth/login', { email, password, remember })
    const u = res.data.user.data ?? res.data.user
    await replaceAuthSession(qc, u)
  }, [qc])

  const logout = useCallback(async () => {
    try {
      await api.post('/auth/logout')
    } finally {
      await replaceAuthSession(qc, null)
    }
  }, [qc])

  const can = useCallback(
    (...permissions: string[]) => permissions.length === 0 || permissions.some((p) => user?.permissions?.includes(p)),
    [user],
  )

  return <AuthContext.Provider value={{ user, isLoading, login, logout, can }}>{children}</AuthContext.Provider>
}

// eslint-disable-next-line react-refresh/only-export-components
export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used inside AuthProvider')
  return ctx
}
