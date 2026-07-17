import { createApp, type App as VueApp } from 'vue'
import { createPinia } from 'pinia'
import { initUICore, uiRegistry } from '@multi-tenant-saas/ui-core'
import { createBootstrapAdapter } from '@multi-tenant-saas/ui-core/adapters/index'
import { useUserStore } from './stores/user'
import App from '../../pages/admin/App.vue'
import router from './router'
import 'element-plus/theme-chalk/dark/css-vars.css'

const fw = localStorage.getItem('multi-tenant-saas-ui-framework')
  || (import.meta.env.VITE_UI_FRAMEWORK as string)
  || 'element-plus'

/**
 * 注册所有可用 UI 框架（供选择器展示所有选项）
 * install 方法内延迟导入依赖，注册时不产生副作用
 * 使用 Vite 原生 import() 生成独立 chunk，生产环境可正确加载
 */
function registerAllFrameworks() {
  // Element Plus
  uiRegistry.register({
    name: 'element-plus',
    metadata: {
      name: 'element-plus', label: 'Element Plus',
      description: '饿了么开源的 Vue 3 组件库',
      version: '^2.8.0', website: 'https://element-plus.org', icon: '',
      features: ['70+ 组件', 'TypeScript', '暗色主题', '国际化'],
      installCommand: 'npm install element-plus',
    },
    async install(app: VueApp) {
      const { default: ElementPlus } = await import('element-plus')
      const zhCn = (await import('element-plus/es/locale/lang/zh-cn')).default
      const icons = await import('@element-plus/icons-vue')
      app.use(ElementPlus, { locale: zhCn })
      Object.entries(icons).forEach(([key, component]) => {
        if (key !== 'default') app.component(key, component)
      })
    },
    getComponentMap() { return {} },
    getThemeVariables() { return {} },
  })

  // Bootstrap
  uiRegistry.register(createBootstrapAdapter('bootstrap'))
}

/**
 * 加载框架运行时依赖（CSS/JS）并设置活跃框架
 */
async function loadFramework(name: string) {
  if (name === 'element-plus') {
    uiRegistry.setActive('element-plus')
    return
  }

  if (name === 'bootstrap' || name === 'vali-admin') {
    await import('bootstrap/dist/css/bootstrap.min.css')
    await import('bootstrap/dist/js/bootstrap.bundle.min.js')
    uiRegistry.setActive(name as any)
    return
  }

  console.warn(`Unknown framework: ${name}`)
}

async function main() {
  await initUICore()

  // 注册所有可用框架（供选择器展示所有选项）
  registerAllFrameworks()

  try {
    await loadFramework(fw)
  } catch (e) {
    console.warn(`Failed to load "${fw}", falling back to bootstrap:`, e)
    localStorage.removeItem('multi-tenant-saas-ui-framework')
    await loadFramework('bootstrap')
  }

  const app = createApp(App)
  const active = uiRegistry.getActive()
  if (active) {
    try {
      await active.install(app)
    } catch (e) {
      console.warn(`Failed to install "${fw}", falling back to bootstrap:`, e)
      localStorage.removeItem('multi-tenant-saas-ui-framework')
      await loadFramework('bootstrap')
      const fallback = uiRegistry.getActive()
      if (fallback) await fallback.install(app)
    }
  }

  app.use(createPinia())

  const userStore = useUserStore()
  await userStore.init()

  app.use(router)
  app.mount('#app')
}

main().catch(console.error)
