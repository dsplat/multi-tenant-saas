/**
 * Element Plus 适配器
 */

import type { UIFrameworkAdapter, UIFrameworkMetadata } from '../registry'

export const elementPlusMetadata: UIFrameworkMetadata = {
  name: 'element-plus',
  label: 'Element Plus',
  description: '饿了么开源的 Vue 3 组件库，组件丰富、文档完善、中文友好',
  version: '^2.5.0',
  website: 'https://element-plus.org',
  icon: 'ep:element-plus',
  features: [
    '70+ 高质量组件',
    '完整的 TypeScript 支持',
    '暗色主题支持',
    '国际化支持',
    '按需导入',
  ],
  installCommand: 'npm install element-plus @element-plus/icons-vue',
}

export const elementPlusAdapter: UIFrameworkAdapter = {
  name: 'element-plus',
  metadata: elementPlusMetadata,
  
  async install(app) {
    const { default: ElementPlus } = await import('element-plus')
    const zhCn = await import('element-plus/es/locale/lang/zh-cn')
    
    app.use(ElementPlus, {
      locale: zhCn.default,
    })
    
    // 注册图标
    const icons = await import('@element-plus/icons-vue')
    Object.entries(icons).forEach(([key, component]) => {
      if (key !== 'default') {
        app.component(key, component)
      }
    })
  },
  
  getComponentMap() {
    return {
      // 基础
      Button: 'el-button',
      Link: 'el-link',
      Text: 'el-text',
      
      // 表单
      Input: 'el-input',
      InputNumber: 'el-input-number',
      Select: 'el-select',
      Option: 'el-option',
      Radio: 'el-radio',
      RadioGroup: 'el-radio-group',
      RadioButton: 'el-radio-button',
      Checkbox: 'el-checkbox',
      Switch: 'el-switch',
      Slider: 'el-slider',
      TimePicker: 'el-time-picker',
      DatePicker: 'el-date-picker',
      DateTimePicker: 'el-date-time-picker',
      Upload: 'el-upload',
      Transfer: 'el-transfer',
      ColorPicker: 'el-color-picker',
      Rate: 'el-rate',
      
      // 数据展示
      Table: 'el-table',
      TableColumn: 'el-table-column',
      Tag: 'el-tag',
      Progress: 'el-progress',
      Tree: 'el-tree',
      Pagination: 'el-pagination',
      Badge: 'el-badge',
      Avatar: 'el-avatar',
      Skeleton: 'el-skeleton',
      Empty: 'el-empty',
      Descriptions: 'el-descriptions',
      DescriptionsItem: 'el-descriptions-item',
      Statistic: 'el-statistic',
      
      // 导航
      Menu: 'el-menu',
      MenuItem: 'el-menu-item',
      SubMenu: 'el-sub-menu',
      Tabs: 'el-tabs',
      TabPane: 'el-tab-pane',
      Breadcrumb: 'el-breadcrumb',
      BreadcrumbItem: 'el-breadcrumb-item',
      Dropdown: 'el-dropdown',
      DropdownMenu: 'el-dropdown-menu',
      DropdownItem: 'el-dropdown-item',
      Steps: 'el-steps',
      Step: 'el-step',
      
      // 反馈
      Dialog: 'el-dialog',
      Drawer: 'el-drawer',
      Tooltip: 'el-tooltip',
      Popover: 'el-popover',
      Popconfirm: 'el-popconfirm',
      MessageBox: 'ElMessageBox',
      Message: 'ElMessage',
      Notification: 'ElNotification',
      Loading: 'v-loading',
      
      // 布局
      Layout: 'el-container',
      Header: 'el-header',
      Aside: 'el-aside',
      Main: 'el-main',
      Footer: 'el-footer',
      Row: 'el-row',
      Col: 'el-col',
      Divider: 'el-divider',
      Card: 'el-card',
      Collapse: 'el-collapse',
      CollapseItem: 'el-collapse-item',
      Space: 'el-space',
    }
  },
  
  getThemeVariables(mode) {
    if (mode === 'dark') {
      return {
        '--el-bg-color': '#141414',
        '--el-bg-color-overlay': '#1d1e1f',
        '--el-bg-color-page': '#0a0a0a',
        '--el-text-color-primary': '#e5eaf3',
        '--el-text-color-regular': '#cfd3dc',
        '--el-text-color-secondary': '#a3a6ad',
        '--el-border-color': '#4c4d4f',
        '--el-border-color-light': '#414243',
        '--el-fill-color': '#303030',
        '--el-fill-color-light': '#262727',
        '--el-fill-color-lighter': '#1d1d1d',
        '--el-mask-color': 'rgba(0, 0, 0, 0.5)',
      }
    }
    return {}
  },
}
