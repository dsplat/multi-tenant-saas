/**
 * Multi-Tenant SaaS UI Core
 * 
 * 核心库，提供主题切换、UI 框架适配等功能
 */

// 注册表
export { uiRegistry, useUIRegistry } from './registry'
export type { UIFrameworkName, UIFrameworkMetadata, UIFrameworkAdapter } from './registry'

// 主题管理
export { themeManager, useTheme, themePresets } from './theme-manager'
export type { ThemeMode, ThemeConfig, ThemePreset } from './theme-manager'

// 适配器
export {
  elementPlusAdapter,
  antDesignAdapter,
  naiveUIAdapter,
  arcoDesignAdapter,
  tdesignAdapter,
  varletAdapter,
  registerBuiltinAdapters,
} from './adapters'

// 组件
export { default as ThemeSwitcher } from './components/ThemeSwitcher.vue'
export { default as ColorPicker } from './components/ColorPicker.vue'
export { default as ThemeSettings } from './components/ThemeSettings.vue'
export { default as UIFrameworkSelector } from './components/UIFrameworkSelector.vue'

/**
 * 初始化 UI 核心库
 */
export async function initUICore() {
  // 注册内置适配器
  const { registerBuiltinAdapters } = await import('./adapters')
  registerBuiltinAdapters()
  
  // 初始化主题
  const { themeManager } = await import('./theme-manager')
  themeManager.init()
  
  // 从本地存储加载 UI 框架设置
  const savedFramework = localStorage.getItem('multi-tenant-saas-ui-framework')
  if (savedFramework && uiRegistry.has(savedFramework as any)) {
    uiRegistry.setActive(savedFramework as any)
  }
}
