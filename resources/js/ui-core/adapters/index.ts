import type { UIFramework } from '../index'

export interface UIAdapter {
  name: UIFramework
  install(app: any): void
  getComponents(): Record<string, any>
}

const adapters = new Map<UIF, UIAdapter>()

export function registerAdapter(adapter: UIAdapter) {
  adapters.set(adapter.name, adapter)
}

export function getAdapter(framework: UIFramework): UIAdapter | undefined {
  return adapters.get(framework)
}

export function getAvailableFrameworks(): UIFramework[] {
  return Array.from(adapters.keys())
}

// Element Plus 适配器
export async function createElementPlusAdapter(): Promise<UIAdapter> {
  const { default: ElementPlus } = await import('element-plus')
  const icons = await import('@element-plus/icons-vue')
  
  return {
    name: 'element-plus',
    install(app: any) {
      app.use(ElementPlus)
      // 注册图标
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
        Form: 'el-form',
        Card: 'el-card',
        Dialog: 'el-dialog',
        Menu: 'el-menu',
        Layout: 'el-container',
        Message: 'ElMessage',
        Notification: 'ElNotification',
      }
    },
  }
}

// Ant Design Vue 适配器
export async function createAntDesignAdapter(): Promise<UIAdapter> {
  const { default: Antd } = await import('ant-design-vue')
  
  return {
    name: 'ant-design',
    install(app: any) {
      app.use(Antd)
    },
    getComponents() {
      return {
        Button: 'a-button',
        Input: 'a-input',
        Select: 'a-select',
        Table: 'a-table',
        Form: 'a-form',
        Card: 'a-card',
        Dialog: 'a-modal',
        Menu: 'a-menu',
        Layout: 'a-layout',
        Message: 'message',
        Notification: 'notification',
      }
    },
  }
}

// Naive UI 适配器
export async function createNaiveUIAdapter(): Promise<UIAdapter> {
  const naive = await import('naive-ui')
  
  return {
    name: 'naive-ui',
    install(app: any) {
      // Naive UI 按需导入，不需要全局安装
    },
    getComponents() {
      return {
        Button: 'n-button',
        Input: 'n-input',
        Select: 'n-select',
        Table: 'n-data-table',
        Form: 'n-form',
        Card: 'n-card',
        Dialog: 'n-modal',
        Menu: 'n-menu',
        Layout: 'n-layout',
        Message: 'useMessage',
        Notification: 'useNotification',
      }
    },
  }
}

export * from './element-plus'
export * from './ant-design'
export * from './naive-ui'
