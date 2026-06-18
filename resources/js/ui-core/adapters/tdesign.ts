/**
 * TDesign Vue 适配器
 */

import type { UIFrameworkAdapter, UIFrameworkMetadata } from '../registry'

export const tdesignMetadata: UIFrameworkMetadata = {
  name: 'tdesign',
  label: 'TDesign',
  description: '腾讯开源的企业级 UI 组件库，设计统一、组件丰富',
  version: '^1.9.0',
  website: 'https://tdesign.tencent.com',
  icon: 'tdesign:tdesign',
  features: [
    '70+ 高质量组件',
    '腾讯设计规范',
    '完整的 TypeScript 支持',
    '暗色主题支持',
    '按需导入',
  ],
  installCommand: 'npm install tdesign-vue-next',
}

export const tdesignAdapter: UIFrameworkAdapter = {
  name: 'tdesign',
  metadata: tdesignMetadata,
  
  async install(app) {
    const TDesign = await import('tdesign-vue-next')
    const icons = await import('tdesign-icons-vue-next')
    
    app.use(TDesign.default)
    
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
      Button: 't-button',
      Link: 't-link',
      Text: 't-typography-text',
      
      // 表单
      Input: 't-input',
      InputNumber: 't-input-number',
      Select: 't-select',
      Option: 't-option',
      Radio: 't-radio',
      RadioGroup: 't-radio-group',
      RadioButton: 't-radio-button',
      Checkbox: 't-checkbox',
      Switch: 't-switch',
      Slider: 't-slider',
      TimePicker: 't-time-picker',
      DatePicker: 't-date-picker',
      Upload: 't-upload',
      Transfer: 't-transfer',
      ColorPicker: 't-color-picker',
      Rate: 't-rate',
      
      // 数据展示
      Table: 't-table',
      Tag: 't-tag',
      Progress: 't-progress',
      Tree: 't-tree',
      Pagination: 't-pagination',
      Badge: 't-badge',
      Avatar: 't-avatar',
      Skeleton: 't-skeleton',
      Empty: 't-empty',
      Descriptions: 't-descriptions',
      DescriptionsItem: 't-descriptions-item',
      Statistic: 't-statistic',
      
      // 导航
      Menu: 't-menu',
      MenuItem: 't-menu-item',
      SubMenu: 't-submenu',
      Tabs: 't-tabs',
      TabPanel: 't-tab-panel',
      Breadcrumb: 't-breadcrumb',
      BreadcrumbItem: 't-breadcrumb-item',
      Dropdown: 't-dropdown',
      Steps: 't-steps',
      Step: 't-step',
      
      // 反馈
      Dialog: 't-dialog',
      Drawer: 't-drawer',
      Tooltip: 't-tooltip',
      Popup: 't-popup',
      Popconfirm: 't-popconfirm',
      MessagePlugin: 'MessagePlugin',
      NotificationPlugin: 'NotificationPlugin',
      
      // 布局
      Layout: 't-layout',
      Header: 't-layout-header',
      Aside: 't-layout-aside',
      Content: 't-layout-content',
      Footer: 't-layout-footer',
      Row: 't-row',
      Col: 't-col',
      Divider: 't-divider',
      Card: 't-card',
      Collapse: 't-collapse',
      CollapsePanel: 't-collapse-panel',
      Space: 't-space',
    }
  },
  
  getThemeVariables(mode) {
    if (mode === 'dark') {
      return {
        '--td-bg-color-page': '#181818',
        '--td-bg-color-container': '#242424',
        '--td-bg-color-container-hover': '#2d2d2d',
        '--td-text-color-primary': 'rgba(255, 255, 255, 0.9)',
        '--td-text-color-secondary': 'rgba(255, 255, 255, 0.6)',
        '--td-text-color-disabled': 'rgba(255, 255, 255, 0.3)',
        '--td-border-level-1-color': 'rgba(255, 255, 255, 0.1)',
      }
    }
    return {}
  },
}
