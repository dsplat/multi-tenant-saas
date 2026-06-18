import type { UIAdapter } from '../index'

export const elementPlusAdapter: UIAdapter = {
  name: 'element-plus',
  
  async install(app: any) {
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
  
  getComponents() {
    return {
      Button: 'el-button',
      Input: 'el-input',
      Select: 'el-select',
      Table: 'el-table',
      TableColumn: 'el-table-column',
      Form: 'el-form',
      FormItem: 'el-form-item',
      Card: 'el-card',
      Dialog: 'el-dialog',
      Drawer: 'el-drawer',
      Menu: 'el-menu',
      MenuItem: 'el-menu-item',
      SubMenu: 'el-sub-menu',
      Layout: 'el-container',
      Header: 'el-header',
      Aside: 'el-aside',
      Main: 'el-main',
      Pagination: 'el-pagination',
      Tag: 'el-tag',
      Dropdown: 'el-dropdown',
      DropdownMenu: 'el-dropdown-menu',
      DropdownItem: 'el-dropdown-item',
      Breadcrumb: 'el-breadcrumb',
      BreadcrumbItem: 'el-breadcrumb-item',
      Divider: 'el-divider',
      Popover: 'el-popover',
      Popconfirm: 'el-popconfirm',
      Radio: 'el-radio',
      RadioGroup: 'el-radio-group',
      RadioButton: 'el-radio-button',
      Slider: 'el-slider',
      ColorPicker: 'el-color-picker',
      Loading: 'v-loading',
      Message: 'ElMessage',
      Notification: 'ElNotification',
      MessageBox: 'ElMessageBox',
    }
  },
}
