import { test } from 'node:test'
import assert from 'node:assert/strict'
import { QueryClient, QueryObserver } from '@tanstack/react-query'
import { AUTH_USER_QUERY_KEY, replaceAuthSession } from '../src/auth/session.ts'

test('sign-in and sign-out notify the mounted auth observer and clear prior private data', async () => {
  const client = new QueryClient()
  client.setQueryData(AUTH_USER_QUERY_KEY, null)
  client.setQueryData(['employees'], ['private staff data'])
  const observer = new QueryObserver(client, { queryKey: AUTH_USER_QUERY_KEY, enabled: false })
  const users: unknown[] = []
  const unsubscribe = observer.subscribe(result => users.push(result.data))
  const user = { id: 2, name: 'Front Desk', email: 'frontdesk@example.test', phone: null, is_active: true, last_login_at: null, created_at: null }

  await replaceAuthSession(client, user)

  assert.deepEqual(users.at(-1), user)
  assert.equal(client.getQueryData(['employees']), undefined)
  client.setQueryData(['reservations'], ['private reservations'])

  await replaceAuthSession(client, null)

  assert.equal(users.at(-1), null)
  assert.equal(client.getQueryData(['reservations']), undefined)
  unsubscribe()
  client.clear()
})

test('an outdated anonymous session check cannot replace a successful sign-in', async () => {
  const client = new QueryClient({ defaultOptions: { queries: { retry: false } } })
  let finishCheck: (value: null) => void = () => {}
  const pendingCheck = client.fetchQuery({ queryKey: AUTH_USER_QUERY_KEY, queryFn: () => new Promise<null>(resolve => { finishCheck = resolve }) }).catch(() => {})
  const user = { id: 3, name: 'Accountant', email: 'accountant@example.test', phone: null, is_active: true, last_login_at: null, created_at: null }

  await replaceAuthSession(client, user)
  finishCheck(null)
  await pendingCheck

  assert.deepEqual(client.getQueryData(AUTH_USER_QUERY_KEY), user)
  client.clear()
})
