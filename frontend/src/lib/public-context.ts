import { createContext, useContext } from 'react'
import type { PublicBootstrap } from '@/lib/cms'

export const BootstrapCtx = createContext<PublicBootstrap | null>(null)

export function usePublicBootstrap() {
  return useContext(BootstrapCtx)
}
