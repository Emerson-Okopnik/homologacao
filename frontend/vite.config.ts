/// <reference types="vitest/config" />
import { fileURLToPath, URL } from 'node:url'
import { defineConfig, loadEnv } from 'vite'
import vue from '@vitejs/plugin-vue'
import tailwindcss from '@tailwindcss/vite'

export default defineConfig(({ mode }) => {
  const env = loadEnv(mode, process.cwd(), '')
  const backend = env.VITE_BACKEND_URL || 'http://localhost:8080'

  return {
    plugins: [vue(), tailwindcss()],
    // Tailwind usa o plugin Vite; não herdar o PostCSS do Next.js na raiz.
    css: {
      postcss: { plugins: [] },
    },
    optimizeDeps: {
      entries: ['index.html'],
    },
    resolve: {
      alias: { '@': fileURLToPath(new URL('./src', import.meta.url)) },
    },
    server: {
      port: 5173,
      strictPort: true,
      host: true,
      allowedHosts: ['frontend', 'nginx'],
      watch: {
        usePolling: env.VITE_USE_POLLING === 'true',
        interval: 1000,
        ignored: ['**/.pnpm-store/**', '**/dist/**', '**/coverage/**', '**/test-results/**', '**/playwright-report/**'],
      },
      // Mesmo origin para a SPA e a API: os cookies de sessão do Sanctum ficam first-party.
      proxy: {
        '^/(api|sanctum|up)(/|$)': { target: backend, changeOrigin: false },
      },
    },
    test: {
      environment: 'jsdom',
      include: ['src/**/*.spec.ts'],
    },
  }
})
