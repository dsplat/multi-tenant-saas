/**
 * 主题管理器
 * 
 * 支持多主题、暗色模式、自定义变量
 */

import { ref, computed, watch, readonly } from 'vue'

export type ThemeMode = 'light' | 'dark' | 'auto'

export interface ThemeConfig {
  mode: ThemeMode
  primaryColor: string
  borderRadius: number
  customVariables: Record<string, string>
}

export interface ThemePreset {
  name: string
  label: string
  config: Partial<ThemeConfig>
}

const STORAGE_KEY = 'multi-tenant-saas-theme'

const defaultConfig: ThemeConfig = {
  mode: 'light',
  primaryColor: '#409eff',
  borderRadius: 4,
  customVariables: {},
}

// 预设主题
export const themePresets: ThemePreset[] = [
  {
    name: 'default',
    label: '默认蓝',
    config: { primaryColor: '#409eff' },
  },
  {
    name: 'green',
    label: '清新绿',
    config: { primaryColor: '#67c23a' },
  },
  {
    name: 'orange',
    label: '活力橙',
    config: { primaryColor: '#e6a23c' },
  },
  {
    name: 'red',
    label: '热情红',
    config: { primaryColor: '#f56c6c' },
  },
  {
    name: 'purple',
    label: '优雅紫',
    config: { primaryColor: '#b388ff' },
  },
  {
    name: 'cyan',
    label: '科技青',
    config: { primaryColor: '#00d1b2' },
  },
]

class ThemeManager {
  private config = ref<ThemeConfig>({ ...defaultConfig })
  private isInitialized = false
  
  get current() {
    return readonly(this.config)
  }
  
  get isDark() {
    return computed(() => {
      if (this.config.value.mode === 'auto') {
        return this.getSystemDarkMode()
      }
      return this.config.value.mode === 'dark'
    })
  }
  
  /**
   * 初始化主题
   */
  init(): void {
    if (this.isInitialized) return
    
    // 从本地存储加载
    const saved = this.loadFromStorage()
    if (saved) {
      this.config.value = { ...defaultConfig, ...saved }
    }
    
    // 应用主题
    this.applyTheme()
    
    // 监听系统主题变化
    this.watchSystemTheme()
    
    this.isInitialized = true
  }
  
  /**
   * 设置主题模式
   */
  setMode(mode: ThemeMode): void {
    this.config.value.mode = mode
    this.saveToStorage()
    this.applyTheme()
  }
  
  /**
   * 设置主色调
   */
  setPrimaryColor(color: string): void {
    this.config.value.primaryColor = color
    this.saveToStorage()
    this.applyTheme()
  }
  
  /**
   * 设置圆角
   */
  setBorderRadius(radius: number): void {
    this.config.value.borderRadius = Math.max(0, Math.min(20, radius))
    this.saveToStorage()
    this.applyTheme()
  }
  
  /**
   * 设置自定义变量
   */
  setCustomVariable(key: string, value: string): void {
    this.config.value.customVariables[key] = value
    this.saveToStorage()
    this.applyTheme()
  }
  
  /**
   * 批量设置自定义变量
   */
  setCustomVariables(variables: Record<string, string>): void {
    Object.assign(this.config.value.customVariables, variables)
    this.saveToStorage()
    this.applyTheme()
  }
  
  /**
   * 应用预设主题
   */
  applyPreset(presetName: string): void {
    const preset = themePresets.find(p => p.name === presetName)
    if (preset) {
      Object.assign(this.config.value, preset.config)
      this.saveToStorage()
      this.applyTheme()
    }
  }
  
  /**
   * 切换暗色模式
   */
  toggleDark(): void {
    this.setMode(this.isDark.value ? 'light' : 'dark')
  }
  
  /**
   * 重置为默认配置
   */
  reset(): void {
    this.config.value = { ...defaultConfig, customVariables: {} }
    this.saveToStorage()
    this.applyTheme()
  }
  
  /**
   * 获取系统暗色模式状态
   */
  private getSystemDarkMode(): boolean {
    if (typeof window === 'undefined') return false
    return window.matchMedia('(prefers-color-scheme: dark)').matches
  }
  
  /**
   * 监听系统主题变化
   */
  private watchSystemTheme(): void {
    if (typeof window === 'undefined') return
    
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
      if (this.config.value.mode === 'auto') {
        this.applyTheme()
      }
    })
  }
  
  /**
   * 应用主题到 DOM
   */
  private applyTheme(): void {
    if (typeof document === 'undefined') return
    
    const root = document.documentElement
    
    // 应用暗色模式类
    if (this.isDark.value) {
      root.classList.add('dark')
      root.classList.remove('light')
    } else {
      root.classList.add('light')
      root.classList.remove('dark')
    }
    
    // 应用 CSS 变量
    root.style.setProperty('--primary-color', this.config.value.primaryColor)
    root.style.setProperty('--border-radius', `${this.config.value.borderRadius}px`)
    
    // 应用自定义变量
    Object.entries(this.config.value.customVariables).forEach(([key, value]) => {
      root.style.setProperty(key, value)
    })
  }
  
  /**
   * 从本地存储加载
   */
  private loadFromStorage(): Partial<ThemeConfig> | null {
    try {
      const saved = localStorage.getItem(STORAGE_KEY)
      return saved ? JSON.parse(saved) : null
    } catch {
      return null
    }
  }
  
  /**
   * 保存到本地存储
   */
  private saveToStorage(): void {
    try {
      localStorage.setItem(STORAGE_KEY, JSON.stringify(this.config.value))
    } catch {
      // ignore
    }
  }
}

// 全局单例
export const themeManager = new ThemeManager()

/**
 * 使用主题管理器
 */
export function useTheme() {
  return {
    config: themeManager.current,
    isDark: themeManager.isDark,
    presets: themePresets,
    
    setMode: themeManager.setMode.bind(themeManager),
    setPrimaryColor: themeManager.setPrimaryColor.bind(themeManager),
    setBorderRadius: themeManager.setBorderRadius.bind(themeManager),
    setCustomVariable: themeManager.setCustomVariable.bind(themeManager),
    setCustomVariables: themeManager.setCustomVariables.bind(themeManager),
    applyPreset: themeManager.applyPreset.bind(themeManager),
    toggleDark: themeManager.toggleDark.bind(themeManager),
    reset: themeManager.reset.bind(themeManager),
  }
}
