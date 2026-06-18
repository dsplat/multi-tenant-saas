import type { UIAdapter } from '../index'

export const antDesignAdapter: UIAdapter = {
  name: 'ant-design',
  
  async install(app: any) {
    const { default: Antd } = await import('ant-design-vue')
    app.use(Antd)
  },
  
  getComponents() {
    return {
      Button: 'a-button',
      Input: 'a-input',
      Select: 'a-select',
      Table: 'a-table',
      Form: 'a-form',
      FormItem: 'a-form-item',
      Card: 'a-card',
      Dialog: 'a-modal',
      Drawer: 'a-drawer',
      Menu: 'a-menu',
      MenuItem: 'a-menu-item',
      SubMenu: 'a-sub-menu',
      Layout: 'a-layout',
      Header: 'a-layout-header',
      Sider: 'a-layout-sider',
      Content: 'a-layout-content',
      Pagination: 'a-pagination',
      Tag: 'a-tag',
      Dropdown: 'a-dropdown',
      Breadcrumb: 'a-breadcrumb',
      BreadcrumbItem: 'a-breadcrumb-item',
      Divider: 'a-divider',
      Popover: 'a-popover',
      Popconfirm: 'a-popconfirm',
      Radio: 'a-radio',
      RadioGroup: 'a-radio-group',
      RadioButton: 'a-radio-button',
      Slider: 'a-slider',
      ColorPicker: 'a-color-picker',
      Loading: 'v-loading',
      Message: 'message',
      Notification: 'notification',
      Modal: 'Modal',
    }
  },
}
