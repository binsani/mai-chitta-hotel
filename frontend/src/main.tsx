import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { QueryClient, QueryClientProvider } from '@tanstack/react-query'
import { AuthProvider } from '@/auth/AuthProvider'
import { api } from '@/lib/api'
import App from './App'
import './index.css'

const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      staleTime: 30_000,
      refetchOnWindowFocus: false,
      retry: (count, err) => {
        const status = (err as { response?: { status?: number } }).response?.status
        return status !== undefined && status < 500 ? false : count < 2
      },
    },
  },
})

// Start public data requests before lazy route modules arrive. The route hooks
// share these keys, so React Query reuses each in-flight request.
const initialPath = window.location.pathname.replace(/\/$/, '') || '/'
if (!initialPath.startsWith('/admin')) {
  void queryClient.prefetchQuery({
    queryKey: ['public', 'bootstrap'],
    queryFn: async () => (await api.get('/public/bootstrap')).data.data,
  })
  const pageSlug = initialPath === '/' ? 'home' : initialPath.startsWith('/p/') ? initialPath.slice(3) : initialPath.slice(1)
  if (/^[a-z0-9-]+$/.test(pageSlug)) {
    void queryClient.prefetchQuery({
      queryKey: ['public', 'page', pageSlug],
      queryFn: async () => (await api.get(`/pages/${pageSlug}`)).data.data,
    })
  }
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <QueryClientProvider client={queryClient}>
      <AuthProvider>
        <App />
      </AuthProvider>
    </QueryClientProvider>
  </StrictMode>,
)
