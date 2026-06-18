import { ref, watch, computed } from 'vue'
import type { ThemeMode, ThemeConfig } from '../index'

const THEME_STORAGE_KEY = 'multi-tenant-saas-theme'

const defaultTheme: ThemeConfig = {
  mode: 'light',
  primaryColor: '#409eff',
  borderRadius: 4,
}

export const currentTheme = ref<ThemeConfig>({ ...defaultTheme })

export const isDarkMode = computed(() => {
  if (currentTheme.value.mode === 'auto') {
    return window.matchMedia('(prefers-color-scheme: dark)').matches
  }
  return currentTheme.value.mode === 'dark'
})

export function initTheme() {
  const saved = localStorage.getItem(THEME_STORAGE_KEY)
  if (saved) {
    try {
      const parsed = JSON.parse(saved)
      currentTheme.value = { ...defaultTheme, ...parsed }
    } catch {
      // ignore
    }
  }
  applyTheme()
}

export function setThemeMode(mode: ThemeMode) {
  currentTheme.value.mode = mode
  saveTheme()
  applyTheme()
}

export function setPrimaryColor(color: string) {
  currentTheme.value.primaryColor = color
  saveTheme()
  applyTheme()
}

export function setBorderRadius(radius: number) {
  currentTheme.value.borderRadius = radius
  saveTheme()
  applyTheme()
}

export function toggleDarkMode() {
  setThemeMode(isDarkMode.value ? 'light' : 'dark')
}

function saveTheme() {
  localStorage.setItem(THEME_STORAGE_KEY, JSON.stringify(currentTheme.value))
}

function applyTheme() {
  const root = document.documentElement
  
  // 应用暗色模式
  if (isDarkMode.value) {
    root.classList.add('dark')
    root.classList.remove('light')
  } else {
    root.classList.add('light')
    root.classList.remove('dark')
  }
  
  // 应用主色调
  root.style.setProperty('--primary-color', currentTheme.value.primaryColor)
  
  // 应用圆角
  root.style.setProperty('--border-radius', `${currentTheme.value.borderRadius}px`)
  
  // 应用自定义变量
  if (currentTheme.value.customVariables) {
    Object.entries(currentTheme.value.customVariables).forEach(([key, value]) => {
      root.style.setProperty(key, value)
    })
  }
}

// 监听系统主题变化
if (typeof window !== 'undefined') {
  window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
    if (currentTheme.value.mode === 'auto') {
      applyTheme()
    }
  })
}

export function useTheme() {
  return {
    theme: currentTheme,
    isDarkMode,
    setThemeMode,
    setPrimaryColor,
    setBorderRadius,
    toggleDarkMode,
  }
}
