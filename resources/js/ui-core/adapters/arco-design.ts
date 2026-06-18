/**
 * Arco Design Vue 适配器
 */

import type { UIFrameworkAdapter, UIFrameworkMetadata } from '../registry'

export const arcoDesignMetadata: UIFrameworkMetadata = {
  name: 'arco-design',
  label: 'Arco Design',
  description: '字节跳动开源的企业级 UI 组件库，设计现代、组件丰富',
  version: '^2.5.0',
  website: 'https://arco.design',
  icon: 'arco-design:arco-design',
  features: [
    '70+ 高质量组件',
    '现代设计风格',
    '完整的 TypeScript 支持',
    '暗色主题支持',
    '按需导入',
  ],
  installCommand: 'npm install @arco-design/web-vue',
}

export const arcoDesignAdapter: UIFrameworkAdapter = {
  name: 'arco-design',
  metadata: arcoDesignMetadata,
  
  async install(app) {
    const ArcoVue = await import('@arco-design/web-vue')
    const icons = await import('@arco-design/web-vue/es/icon')
    
    app.use(ArcoVue.default)
    
    // 注册图标
    Object.entries(icons).forEach(([key, component]) => {
      if (key.startsWith('Icon')) {
        app.component(key, component)
      }
    })
  },
  
  getComponentMap() {
    return {
      // 基础
      Button: 'a-button',
      Link: 'a-link',
      Text: 'a-typography-text',
      
      // 表单
      Input: 'a-input',
      InputNumber: 'a-input-number',
      Select: 'a-select',
      Option: 'a-option',
      Radio: 'a-radio',
      RadioGroup: 'a-radio-group',
      RadioButton: 'a-radio-button',
      Checkbox: 'a-checkbox',
      Switch: 'a-switch',
      Slider: 'a-slider',
      TimePicker: 'a-time-picker',
      DatePicker: 'a-date-picker',
      Upload: 'a-upload',
      Transfer: 'a-transfer',
      ColorPicker: 'a-color-picker',
      Rate: 'a-rate',
      
      // 数据展示
      Table: 'a-table',
      Tag: 'a-tag',
      Progress: 'a-progress',
      Tree: 'a-tree',
      Pagination: 'a-pagination',
      Badge: 'a-badge',
      Avatar: 'a-avatar',
      Skeleton: 'a-skeleton',
      Empty: 'a-empty',
      Descriptions: 'a-descriptions',
      DescriptionsItem: 'a-descriptions-item',
      Statistic: 'a-statistic',
      
      // 导航
      Menu: 'a-menu',
      MenuItem: 'a-menu-item',
      SubMenu: 'a-sub-menu',
      Tabs: 'a-tabs',
      TabPane: 'a-tab-pane',
      Breadcrumb: 'a-breadcrumb',
      BreadcrumbItem: 'a-breadcrumb-item',
      Dropdown: 'a-dropdown',
      Steps: 'a-steps',
      Step: 'a-step',
      
      // 反馈
      Modal: 'a-modal',
      Drawer: 'a-drawer',
      Tooltip: 'a-tooltip',
      Popover: 'a-popover',
      Popconfirm: 'a-popconfirm',
      Message: 'Message',
      Notification: 'Notification',
      
      // 布局
      Layout: 'a-layout',
      Header: 'a-layout-header',
      Sider: 'a-layout-sider',
      Content: 'a-layout-content',
      Footer: 'a-layout-footer',
      Row: 'a-row',
      Col: 'a-col',
      Divider: 'a-divider',
      Card: 'a-card',
      Collapse: 'a-collapse',
      CollapseItem: 'a-collapse-item',
      Space: 'a-space',
    }
  },
  
  getThemeVariables(mode) {
    if (mode === 'dark') {
      return {
        '--color-bg-1': '#17171a',
        '--color-bg-2': '#232324',
        '--color-bg-3': '#2a2a2b',
        '--color-bg-4': '#313132',
        '--color-text-1': 'rgba(255, 255, 255, 0.9)',
        '--color-text-2': 'rgba(255, 255, 255, 0.7)',
        '--color-text-3': 'rgba(255, 255, 255, 0.5)',
        '--color-border': 'rgba(255, 255, 255, 0.15)',
      }
    }
    return {}
  },
}
