export * from './adapters'
export * from './themes'
export * from './components'

export type UIFramework = 'element-plus' | 'ant-design' | 'naive-ui'
export type ThemeMode = 'light' | 'dark' | 'auto'

export interface ThemeConfig {
  mode: ThemeMode
  primaryColor: string
  borderRadius: number
  customVariables?: Record<string, string>
}

export interface UIFrameworkConfig {
  framework: UIFramework
  theme: ThemeConfig
}
