import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'
import { existsSync, copyFileSync, rmSync } from 'fs'
import AutoImport from 'unplugin-auto-import/vite'
import Components from 'unplugin-vue-components/vite'
import { ElementPlusResolver } from 'unplugin-vue-components/resolvers'

export default defineConfig({
  plugins: [
    vue(),
    AutoImport({ resolvers: [ElementPlusResolver()] }),
    Components({ resolvers: [ElementPlusResolver()] }),
    // Vite preserves input's relative-to-root path in output.
    // Since root=project-root and input=resources/pages/admin/index.html,
    // output lands at public/admin/resources/pages/admin/index.html.
    // This plugin flattens it to public/admin/index.html (where SpaController expects it).
    {
      name: 'flatten-index-html',
      closeBundle() {
        const outDir = resolve(__dirname, '../../../public/admin')
        const nested = resolve(outDir, 'resources/pages/admin/index.html')
        const flat = resolve(outDir, 'index.html')
        if (existsSync(nested)) {
          copyFileSync(nested, flat)
          rmSync(resolve(outDir, 'resources'), { recursive: true, force: true })
        }
      },
    },
  ],
  root: resolve(__dirname, '../../..'),
  base: '/admin/',
  build: {
    outDir: resolve(__dirname, '../../../public/admin'),
    emptyOutDir: true,
    copyPublicDir: false, // 禁止复制 root/public/ 到 outDir，否则会产生递归嵌套
    rollupOptions: {
      input: resolve(__dirname, '../../pages/admin/index.html'),
    },
  },
  resolve: {
    alias: {
      '@': resolve(__dirname, '..'),
      '@stores': resolve(__dirname, 'stores'),
      '@multi-tenant-saas/ui-core/components': resolve(__dirname, '../../pages/ui-core/components'),
      '@multi-tenant-saas/ui-core': resolve(__dirname, '../ui-core'),
      'vue': resolve(__dirname, 'node_modules/vue'),
      'vue-router': resolve(__dirname, 'node_modules/vue-router'),
      'pinia': resolve(__dirname, 'node_modules/pinia'),
      'axios': resolve(__dirname, 'node_modules/axios'),
      'element-plus': resolve(__dirname, 'node_modules/element-plus'),
      '@element-plus/icons-vue': resolve(__dirname, 'node_modules/@element-plus/icons-vue'),
    },
  },
  optimizeDeps: {
    exclude: [
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
