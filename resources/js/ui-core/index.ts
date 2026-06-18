/**
 * Multi-Tenant SaaS UI Core
 */

// 注册表
export { uiRegistry, useUIRegistry } from './registry'
export type { UIFrameworkName, UIFrameworkMetadata, UIFrameworkAdapter } from './registry'

// 主题管理
export { themeManager, useTheme, themePresets } from './theme-manager'
export type { ThemeMode, ThemeConfig, ThemePreset } from './theme-manager'

// 适配器工厂
export { createElementPlusAdapter, createBootstrapAdapter } from './adapters/index'

// 组件
export { default as ThemeSwitcher } from './components/ThemeSwitcher.vue'
export { default as ColorPicker } from './components/ColorPicker.vue'
export { default as ThemeSettings } from './components/ThemeSettings.vue'
export { default as UIFrameworkSelector } from './components/UIFrameworkSelector.vue'

/**
 * 初始化 UI 核心库
 */
export async function initUICore() {
  const { themeManager } = await import('./theme-manager')
  themeManager.init()
}
