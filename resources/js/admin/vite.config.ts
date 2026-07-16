import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'

const framework = process.env.VITE_UI_FRAMEWORK || 'bootstrap'

export default defineConfig({
  plugins: [vue()],
  root: resolve(__dirname),
  base: '/admin/',
  build: {
    outDir: resolve(__dirname, '../../../public/admin'),
    emptyOutDir: true,
    rollupOptions: {
      input: resolve(__dirname, 'index.html'),
    },
  },
  resolve: {
    alias: {
      '@': resolve(__dirname, '.'),
      '@multi-tenant-saas/ui-core': resolve(__dirname, '../ui-core'),
      'axios': resolve(__dirname, 'node_modules/axios'),
    },
  },
  optimizeDeps: {
    exclude: [
      'element-plus',
      'ant-design-vue',
      'naive-ui',
      '@arco-design/web-vue',
      '@varlet/ui',
      'tdesign-vue-next',
    ],
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
