/**
 * UI 框架适配器注册
 * 
 * 这个文件不导入任何第三方框架依赖
 * 框架注册在 main.ts 中按需完成
 */

import { uiRegistry } from '../registry'
import type { UIFrameworkAdapter } from '../registry'

/**
 * 创建 Element Plus 适配器（调用方负责导入 element-plus）
 */
export function createElementPlusAdapter(ElementPlus: any): UIFrameworkAdapter {
  return {
    name: 'element-plus',
    metadata: {
      name: 'element-plus', label: 'Element Plus',
      description: '饿了么开源的 Vue 3 组件库',
      version: '^2.5.0', website: 'https://element-plus.org', icon: '',
      features: ['70+ 组件', 'TypeScript', '暗色主题', '国际化'],
      installCommand: 'npm install element-plus',
    },
    async install(app) { app.use(ElementPlus) },
    getComponentMap() { return { Button: 'el-button', Input: 'el-input', Select: 'el-select', Table: 'el-table', Card: 'el-card' } },
    getThemeVariables() { return {} },
  }
}

/**
 * 创建 Bootstrap 适配器（调用方负责导入 bootstrap）
 */
export function createBootstrapAdapter(name: 'bootstrap' | 'vali-admin' = 'bootstrap'): UIFrameworkAdapter {
  return {
    name: name as any,
    metadata: {
      name: name as any,
      label: name === 'bootstrap' ? 'Bootstrap' : 'Vali Admin',
      description: name === 'bootstrap' ? '全球最流行的前端框架' : '基于 Bootstrap 5 的管理后台模板',
      version: '5.3.0', website: 'https://getbootstrap.com', icon: '',
      features: ['响应式', '栅格系统'],
      installCommand: 'npm install bootstrap',
    },
    async install() {},
    getComponentMap() { return {} },
    getThemeVariables(mode) {
      if (mode === 'dark') return { '--bs-body-bg': '#212529', '--bs-body-color': '#dee2e6' }
      return {}
    },
  }
}
