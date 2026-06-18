import type { UIAdapter } from '../index'

export const naiveUIAdapter: UIAdapter = {
  name: 'naive-ui',
  
  async install(app: any) {
    // Naive UI 使用按需导入，不需要全局安装
    // 开发者需要在组件中手动导入
  },
  
  getComponents() {
    return {
      Button: 'n-button',
      Input: 'n-input',
      Select: 'n-select',
      Table: 'n-data-table',
      Form: 'n-form',
      FormItem: 'n-form-item',
      Card: 'n-card',
      Dialog: 'n-modal',
      Drawer: 'n-drawer',
      Menu: 'n-menu',
      MenuItem: 'n-menu-item',
      Layout: 'n-layout',
      Header: 'n-layout-header',
      Sider: 'n-layout-sider',
      Content: 'n-layout-content',
      Pagination: 'n-pagination',
      Tag: 'n-tag',
      Dropdown: 'n-dropdown',
      Breadcrumb: 'n-breadcrumb',
      Divider: 'n-divider',
      Popover: 'n-popover',
      Popconfirm: 'n-popconfirm',
      Radio: 'n-radio',
      RadioGroup: 'n-radio-group',
      RadioButton: 'n-radio-button',
      Slider: 'n-slider',
      ColorPicker: 'n-color-picker',
      Loading: 'v-loading',
      Message: 'useMessage',
      Notification: 'useNotification',
      Dialog: 'useDialog',
    }
  },
}
