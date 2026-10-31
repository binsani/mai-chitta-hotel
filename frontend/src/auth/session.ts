import type { QueryClient } from '@tanstack/react-query'
import type { User } from './AuthProvider'

export const AUTH_USER_QUERY_KEY = ['auth', 'user'] as const

export async function replaceAuthSession(client: QueryClient, user: User | null) {
  await client.cancelQueries()
  client.removeQueries({ predicate: query => query.queryKey[0] !== 'auth' })
  client.getMutationCache().clear()
  client.setQueryData(AUTH_USER_QUERY_KEY, user)
}
