/**
 * Naive UI 适配器
 */

import type { UIFrameworkAdapter, UIFrameworkMetadata } from '../registry'

export const naiveUIMetadata: UIFrameworkMetadata = {
  name: 'naive-ui',
  label: 'Naive UI',
  description: 'Vue 3 原生组件库，轻量、TypeScript 友好、性能优秀',
  version: '^2.38.0',
  website: 'https://www.naiveui.com',
  icon: 'naive-ui:naive-ui',
  features: [
    '90+ 高质量组件',
    '完整的 TypeScript 支持',
    '暗色主题支持',
    '按需导入',
    '主题定制能力强',
  ],
  installCommand: 'npm install naive-ui',
}

export const naiveUIAdapter: UIFrameworkAdapter = {
  name: 'naive-ui',
  metadata: naiveUIMetadata,
  
  async install(app) {
    // Naive UI 使用按需导入，不需要全局安装
    // 开发者在组件中手动导入即可
  },
  
  getComponentMap() {
    return {
      // 基础
      Button: 'n-button',
      Link: 'n-a',
      Text: 'n-text',
      
      // 表单
      Input: 'n-input',
      InputNumber: 'n-input-number',
      Select: 'n-select',
      Radio: 'n-radio',
      RadioGroup: 'n-radio-group',
      RadioButton: 'n-radio-button',
      Checkbox: 'n-checkbox',
      Switch: 'n-switch',
      Slider: 'n-slider',
      TimePicker: 'n-time-picker',
      DatePicker: 'n-date-picker',
      Upload: 'n-upload',
      Transfer: 'n-transfer',
      ColorPicker: 'n-color-picker',
      Rate: 'n-rate',
      
      // 数据展示
      DataTable: 'n-data-table',
      Tag: 'n-tag',
      Progress: 'n-progress',
      Tree: 'n-tree',
      Pagination: 'n-pagination',
      Badge: 'n-badge',
      Avatar: 'n-avatar',
      Skeleton: 'n-skeleton',
      Empty: 'n-empty',
      Descriptions: 'n-descriptions',
      DescriptionsItem: 'n-descriptions-item',
      Statistic: 'n-statistic',
      
      // 导航
      Menu: 'n-menu',
      Tabs: 'n-tabs',
      TabPane: 'n-tab',
      Breadcrumb: 'n-breadcrumb',
      BreadcrumbItem: 'n-breadcrumb-item',
      Dropdown: 'n-dropdown',
      Steps: 'n-steps',
      Step: 'n-step',
      
      // 反馈
      Modal: 'n-modal',
      Drawer: 'n-drawer',
      Tooltip: 'n-tooltip',
      Popover: 'n-popover',
      Popconfirm: 'n-popconfirm',
      Message: 'useMessage',
      Notification: 'useNotification',
      Dialog: 'useDialog',
      
      // 布局
      Layout: 'n-layout',
      Header: 'n-layout-header',
      Sider: 'n-layout-sider',
      Content: 'n-layout-content',
      Footer: 'n-layout-footer',
      Row: 'n-row',
      Col: 'n-col',
      Divider: 'n-divider',
      Card: 'n-card',
      Collapse: 'n-collapse',
      CollapseItem: 'n-collapse-item',
      Space: 'n-space',
    }
  },
  
  getThemeVariables(mode) {
    if (mode === 'dark') {
      return {
        '--n-color': '#1e1e1e',
        '--n-color-modal': '#252525',
        '--n-color-popover': '#252525',
        '--n-text-color': 'rgba(255, 255, 255, 0.82)',
        '--n-text-color-2': 'rgba(255, 255, 255, 0.52)',
        '--n-text-color-3': 'rgba(255, 255, 255, 0.28)',
        '--n-border-color': 'rgba(255, 255, 255, 0.09)',
      }
    }
    return {}
  },
}
