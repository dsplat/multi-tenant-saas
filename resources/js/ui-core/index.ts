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

// 组件已迁移至 resources/pages/ui-core/components/
// 通过 @multi-tenant-saas/ui-core/components 别名导入

/**
 * 初始化 UI 核心库
 */
export async function initUICore() {
  const { themeManager } = await import('./theme-manager')
  themeManager.init()
}
