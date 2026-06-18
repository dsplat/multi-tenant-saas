/**
 * Varlet 适配器
 */

import type { UIFrameworkAdapter, UIFrameworkMetadata } from '../registry'

export const varletMetadata: UIFrameworkMetadata = {
  name: 'varlet',
  label: 'Varlet',
  description: 'Material Design 风格的 Vue 3 组件库，轻量、现代',
  version: '^3.0.0',
  website: 'https://varlet.gitee.io',
  icon: 'varlet:varlet',
  features: [
    'Material Design 风格',
    '轻量级',
    '完整的 TypeScript 支持',
    '暗色主题支持',
    '按需导入',
  ],
  installCommand: 'npm install @varlet/ui',
}

export const varletAdapter: UIFrameworkAdapter = {
  name: 'varlet',
  metadata: varletMetadata,
  
  async install(app) {
    const Varlet = await import('@varlet/ui')
    app.use(Varlet.default)
  },
  
  getComponentMap() {
    return {
      // 基础
      Button: 'var-button',
      
      // 表单
      Input: 'var-input',
      Select: 'var-select',
      Option: 'var-option',
      Radio: 'var-radio',
      RadioGroup: 'var-radio-group',
      Checkbox: 'var-checkbox',
      Switch: 'var-switch',
      Slider: 'var-slider',
      TimePicker: 'var-time-picker',
      DatePicker: 'var-date-picker',
      Upload: 'var-uploader',
      
      // 数据展示
      Table: 'var-table',
      Tag: 'var-tag',
      Progress: 'var-progress',
      Pagination: 'var-pagination',
      Badge: 'var-badge',
      Avatar: 'var-avatar',
      Skeleton: 'var-skeleton',
      Empty: 'var-empty',
      
      // 导航
      Menu: 'var-menu',
      Tabs: 'var-tabs',
      Tab: 'var-tab',
      Breadcrumb: 'var-breadcrumb',
      Dropdown: 'var-menu',
      Steps: 'var-steps',
      Step: 'var-step',
      
      // 反馈
      Dialog: 'var-dialog',
      Drawer: 'var-drawer',
      Tooltip: 'var-tooltip',
      Popover: 'var-popover',
      Popconfirm: 'var-popconfirm',
      Snackbar: 'Snackbar',
      
      // 布局
      Row: 'var-row',
      Col: 'var-col',
      Divider: 'var-divider',
      Card: 'var-card',
      Collapse: 'var-collapse',
      CollapseItem: 'var-collapse-item',
      Space: 'var-space',
    }
  },
  
  getThemeVariables(mode) {
    if (mode === 'dark') {
      return {
        '--color-body': '#1e1e1e',
        '--color-text': '#ffffff',
        '--color-text-2': 'rgba(255, 255, 255, 0.7)',
        '--color-text-3': 'rgba(255, 255, 255, 0.5)',
        '--color-border': 'rgba(255, 255, 255, 0.12)',
      }
    }
    return {}
  },
}
