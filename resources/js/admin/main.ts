import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { initUICore, uiRegistry } from '@multi-tenant-saas/ui-core'
import { createBootstrapAdapter } from '@multi-tenant-saas/ui-core/adapters/index'
import { useUserStore } from './stores/user'
import App from './App.vue'
import router from './router'

const fw = localStorage.getItem('multi-tenant-saas-ui-framework')
  || (import.meta.env.VITE_UI_FRAMEWORK as string)
  || 'element-plus'

// 动态加载依赖，避免 Vite 静态扫描
async function dynamicImport(specifier: string) {
  return new Function('s', 'return import(s)')(specifier) as Promise<any>
}

async function loadFramework(name: string) {
  if (name === 'element-plus') {
    const mod = await dynamicImport('element-plus')
    uiRegistry.register({
      name: 'element-plus',
      metadata: { name: 'element-plus', label: 'Element Plus', description: '', version: '', website: '', icon: '', features: [], installCommand: 'npm install element-plus' },
      async install(app) { app.use(mod.default) },
      getComponentMap() { return {} },
      getThemeVariables() { return {} },
    })
    uiRegistry.setActive('element-plus')
    return
  }

  if (name === 'bootstrap' || name === 'vali-admin') {
    await import('bootstrap/dist/css/bootstrap.min.css')
    await import('bootstrap/dist/js/bootstrap.bundle.min.js')
    uiRegistry.register(createBootstrapAdapter(name as any))
    uiRegistry.setActive(name as any)
    return
  }

  console.warn(`Unknown framework: ${name}`)
}

async function main() {
  await initUICore()
  await loadFramework(fw)

  const app = createApp(App)
  const active = uiRegistry.getActive()
  if (active) await active.install(app)

  app.use(createPinia())

  const userStore = useUserStore()
  await userStore.init()

  app.use(router)
  app.mount('#app')
}

main().catch(console.error)
