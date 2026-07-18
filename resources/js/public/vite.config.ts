import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'
import { existsSync, copyFileSync, rmSync } from 'fs'

export default defineConfig({
  plugins: [
    vue(),
    {
      name: 'flatten-index-html',
      closeBundle() {
        const outDir = resolve(__dirname, '../../../public/public')
        const nested = resolve(outDir, 'resources/pages/public/index.html')
        const flat = resolve(outDir, 'index.html')
        if (existsSync(nested)) {
          copyFileSync(nested, flat)
          rmSync(resolve(outDir, 'resources'), { recursive: true, force: true })
        }
      },
    },
  ],
  root: resolve(__dirname, '../../..'),
  base: '/public/',
  build: {
    outDir: resolve(__dirname, '../../../public/public'),
    emptyOutDir: true,
    rollupOptions: {
      input: resolve(__dirname, '../../pages/public/index.html'),
    },
  },
  resolve: {
    alias: {
      'vue': resolve(__dirname, 'node_modules/vue'),
      'vue-router': resolve(__dirname, 'node_modules/vue-router'),
      'axios': resolve(__dirname, 'node_modules/axios'),
    },
  },
  server: {
    proxy: {
      '/api': {
        target: 'http://localhost:8000',
        changeOrigin: true,
      },
    },
  },
})
