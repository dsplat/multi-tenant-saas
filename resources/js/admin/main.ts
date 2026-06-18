import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { initUICore, uiRegistry, themeManager } from '@multi-tenant-saas/ui-core'
import type { UIFrameworkName } from '@multi-tenant-saas/ui-core'
import App from './App.vue'
import router from './router'

// 导入主题样式
import '@multi-tenant-saas/ui-core/themes/variables.css'

async function bootstrap() {
  // 初始化 UI 核心库（注册适配器、初始化主题）
  await initUICore()
  
  // 获取保存的 UI 框架或默认使用 element-plus
  const savedFramework = localStorage.getItem('multi-tenant-saas-ui-framework') as UIFrameworkName
  const defaultFramework: UIFrameworkName = 'element-plus'
  const framework = savedFramework || import.meta.env.VITE_UI_FRAMEWORK || defaultFramework
  
  // 设置活跃框架
  if (uiRegistry.has(framework)) {
    uiRegistry.setActive(framework)
  } else {
    console.warn(`Framework "${framework}" not found, using default`)
    uiRegistry.setActive(defaultFramework)
  }
  
  const app = createApp(App)
  
  // 安装活跃框架
  await uiRegistry.installActive(app)
  
  app.use(createPinia())
  app.use(router)
  
  app.mount('#app')
}

bootstrap().catch(console.error)
