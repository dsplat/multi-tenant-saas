/**
 * UI 框架注册表
 * 
 * 支持动态注册、安装、卸载 UI 框架
 */

import type { App } from 'vue'

export type UIFrameworkName = 
  | 'element-plus'
  | 'ant-design'
  | 'naive-ui'
  | 'arco-design'
  | 'tdesign'
  | 'varlet'
  | 'vant'
  | 'custom'

export interface UIFrameworkMetadata {
  name: UIFrameworkName
  label: string
  description: string
  version: string
  website: string
  icon: string
  features: string[]
  installCommand: string
}

export interface UIFrameworkAdapter {
  name: UIFrameworkName
  metadata: UIFrameworkMetadata
  
  // 安装框架到 Vue 应用
  install(app: App): Promise<void>
  
  // 获取组件映射
  getComponentMap(): Record<string, string>
  
  // 获取主题变量
  getThemeVariables(mode: 'light' | 'dark'): Record<string, string>
  
  // 清理资源
  uninstall?(): void
}

class UIFrameworkRegistry {
  private frameworks = new Map<UIFrameworkName, UIFrameworkAdapter>()
  private activeFramework: UIFrameworkName | null = null
  
  /**
   * 注册 UI 框架
   */
  register(adapter: UIFrameworkAdapter): void {
    this.frameworks.set(adapter.name, adapter)
    console.log(`[UIRegistry] Registered framework: ${adapter.name}`)
  }
  
  /**
   * 注销 UI 框架
   */
  unregister(name: UIFrameworkName): void {
    const adapter = this.frameworks.get(name)
    if (adapter) {
      adapter.uninstall?.()
      this.frameworks.delete(name)
      console.log(`[UIRegistry] Unregistered framework: ${name}`)
      
      // 如果注销的是当前活跃框架，清除引用
      if (this.activeFramework === name) {
        this.activeFramework = null
      }
    }
  }
  
  /**
   * 获取框架适配器
   */
  get(name: UIFrameworkName): UIFrameworkAdapter | undefined {
    return this.frameworks.get(name)
  }
  
  /**
   * 获取所有已注册框架
   */
  getAll(): UIFrameworkAdapter[] {
    return Array.from(this.frameworks.values())
  }
  
  /**
   * 获取所有框架元数据
   */
  getAllMetadata(): UIFrameworkMetadata[] {
    return this.getAll().map(f => f.metadata)
  }
  
  /**
   * 设置活跃框架
   */
  setActive(name: UIFrameworkName): void {
    if (!this.frameworks.has(name)) {
      throw new Error(`Framework "${name}" is not registered`)
    }
    this.activeFramework = name
  }
  
  /**
   * 获取活跃框架
   */
  getActive(): UIFrameworkAdapter | null {
    if (!this.activeFramework) return null
    return this.frameworks.get(this.activeFramework) || null
  }
  
  /**
   * 获取活跃框架名称
   */
  getActiveName(): UIFrameworkName | null {
    return this.activeFramework
  }
  
  /**
   * 检查框架是否已注册
   */
  has(name: UIFrameworkName): boolean {
    return this.frameworks.has(name)
  }
  
  /**
   * 安装活跃框架到 Vue 应用
   */
  async installActive(app: App): Promise<void> {
    const active = this.getActive()
    if (!active) {
      throw new Error('No active framework set')
    }
    await active.install(app)
  }
}

// 全局单例
export const uiRegistry = new UIFrameworkRegistry()

/**
 * 使用 UI 框架注册表
 */
export function useUIRegistry() {
  return uiRegistry
}
