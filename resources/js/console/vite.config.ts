import { defineConfig, type UserConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { resolve } from 'path'
import { existsSync, copyFileSync, rmSync } from 'fs'
import AutoImport from 'unplugin-auto-import/vite'
import Components from 'unplugin-vue-components/vite'
import { ElementPlusResolver } from 'unplugin-vue-components/resolvers'

// Framework SPA directory — this file lives at resources/js/console/vite.config.ts
const FW_DIR = __dirname
// Framework resources root (resources/)
const FW_RESOURCES = resolve(FW_DIR, '../..')
// Framework console JS root (resources/js/console/)
const FW_CONSOLE = FW_DIR

/**
 * Aliases pointing into the framework SPA.
 * Projects consume these to use framework's stores, layouts, module-loader, etc.
 * Array format — projects append their own entries (last match wins for same prefix).
 */
export const consoleAliases: Array<{ find: string | RegExp; replacement: string }> = [
  { find: '@/', replacement: FW_CONSOLE + '/' },
  { find: '@stores', replacement: resolve(FW_CONSOLE, 'stores') },
  { find: '@multi-tenant-saas/ui-core/components', replacement: resolve(FW_RESOURCES, 'pages/ui-core/components') },
  { find: '@multi-tenant-saas/ui-core', replacement: resolve(FW_CONSOLE, '../ui-core') },
]

/** Node module aliases resolved from the framework's own node_modules. */
export const nodeModuleAliases: Array<{ find: string; replacement: string }> = [
  { find: 'vue', replacement: resolve(FW_CONSOLE, 'node_modules/vue') },
  { find: 'vue-router', replacement: resolve(FW_CONSOLE, 'node_modules/vue-router') },
  { find: 'pinia', replacement: resolve(FW_CONSOLE, 'node_modules/pinia') },
  { find: 'axios', replacement: resolve(FW_CONSOLE, 'node_modules/axios') },
  { find: 'element-plus', replacement: resolve(FW_CONSOLE, 'node_modules/element-plus') },
  { find: '@element-plus/icons-vue', replacement: resolve(FW_CONSOLE, 'node_modules/@element-plus/icons-vue') },
]

/**
 * Create a Vite config for the Console SPA.
 *
 * Framework standalone: createConsoleConfig()
 * Project consuming framework: createConsoleConfig({ projectRoot: resolve(__dirname, '../../..'), extraAliases: [...] })
 */
export function createConsoleConfig(options: {
  projectRoot?: string
  extraAliases?: Array<{ find: string | RegExp; replacement: string }>
  extraProxy?: Record<string, any>
} = {}): UserConfig {
  const root = options.projectRoot || resolve(FW_DIR, '../../..')

  return defineConfig({
    plugins: [
      vue(),
      AutoImport({ resolvers: [ElementPlusResolver()] }),
      Components({ resolvers: [ElementPlusResolver()] }),
      {
        name: 'flatten-index-html',
        closeBundle() {
          const outDir = resolve(root, 'public/console')
          const nested = resolve(outDir, 'resources/pages/console/index.html')
          const flat = resolve(outDir, 'index.html')
          if (existsSync(nested)) {
            copyFileSync(nested, flat)
            rmSync(resolve(outDir, 'resources'), { recursive: true, force: true })
          }
        },
      },
      {
        name: 'spa-fallback',
        configureServer(server) {
          server.middlewares.use((req, _res, next) => {
            const url = req.url || ''
            if (url.includes('/@') || url.includes('/.vite/') || url.includes('/node_modules/') ||
                url.includes('/api/') || url.includes('/vendor/') || url.includes('/resources/') ||
                /\.(js|ts|vue|css|json|png|jpg|svg|ico|woff|woff2|ttf)(\?|$)/.test(url)) {
              return next()
            }
            req.url = '/console/resources/pages/console/index.html'
            next()
          })
        },
      },
    ],
    root,
    base: '/console/',
    build: {
      outDir: resolve(root, 'public/console'),
      emptyOutDir: true,
      rollupOptions: {
        input: resolve(FW_RESOURCES, 'pages/console/index.html'),
      },
    },
    resolve: {
      alias: [
        ...consoleAliases,
        ...(options.extraAliases || []),
        ...nodeModuleAliases,
      ],
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
      port: 5174,
      proxy: {
        '/api': {
          target: 'http://localhost:8000',
          changeOrigin: true,
        },
        '/broadcasting': 'http://localhost:8000',
        ...(options.extraProxy || {}),
      },
    },
  })
}

// Framework standalone entry point
export default createConsoleConfig()
