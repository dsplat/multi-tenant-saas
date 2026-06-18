import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { initTheme, getAdapter } from '@multi-tenant-saas/ui-core'
import type { UIFramework } from '@multi-tenant-saas/ui-core'
import App from './App.vue'
import router from './router'

// 导入主题样式
import '@multi-tenant-saas/ui-core/themes/variables.css'

// 获取 UI 框架配置
const framework: UIFramework = 
  localStorage.getItem('multi-tenant-saas-ui-framework') as UIFramework ||
  import.meta.env.VITE_UI_FRAMEWORK ||
  'element-plus'

async function bootstrap() {
  const app = createApp(App)
  
  // 初始化主题
  initTheme()
  
  // 安装 UI 框架
  const adapter = await getAdapter(framework)
  if (adapter) {
    await adapter.install(app)
    
    // 导入深色主题样式（如果是 Element Plus）
    if (framework === 'element-plus') {
      await import('@multi-tenant-saas/ui-core/themes/element-plus-dark.css')
    }
  } else {
    console.warn(`UI framework "${framework}" not found, falling back to element-plus`)
    const fallbackAdapter = await getAdapter('element-plus')
    if (fallbackAdapter) {
      await fallbackAdapter.install(app)
    }
  }
  
  app.use(createPinia())
  app.use(router)
  
  app.mount('#app')
}

bootstrap()
