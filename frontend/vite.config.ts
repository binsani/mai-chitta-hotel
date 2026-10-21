import fs from 'node:fs'
import path from 'node:path'
import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig, type Plugin } from 'vite'

/**
 * Post-build plugin that moves the compiled `index.html` to `spa.html` in
 * `backend/public/` so Apache's DirectoryIndex always invokes `public/index.php`
 * (allowing Laravel's SpaController to serve `/` and `/admin/*` with SEO tags)
 * while static hashed bundles in `public/assets/` are served directly by Apache.
 */
function laravelSpaShell(): Plugin {
  return {
    name: 'laravel-spa-shell',
    closeBundle() {
      const outDir = path.resolve(import.meta.dirname, '../backend/public')
      const indexHtml = path.join(outDir, 'index.html')
      const spaHtml = path.join(outDir, 'spa.html')
      if (fs.existsSync(indexHtml)) {
        fs.copyFileSync(indexHtml, spaHtml)
        fs.unlinkSync(indexHtml)
      }
      // Remove legacy public/admin directory so Apache mod_dir never intercepts /admin
      const legacyAdminDir = path.join(outDir, 'admin')
      if (fs.existsSync(legacyAdminDir)) {
        fs.rmSync(legacyAdminDir, { recursive: true, force: true })
      }
    },
  }
}

export default defineConfig({
  base: '/',
  plugins: [react(), tailwindcss(), laravelSpaShell()],
  resolve: { alias: { '@': path.resolve(import.meta.dirname, 'src') } },
  build: {
    outDir: '../backend/public',
    emptyOutDir: false,
    assetsDir: 'assets',
  },
  server: {
    port: 5173,
    proxy: {
      '/api': 'http://localhost:8000',
      '/sanctum': 'http://localhost:8000',
      '/storage': 'http://localhost:8000',
    },
  },
})
