# 自定义 UI 框架开发指南

本文档介绍如何为 Multi-Tenant SaaS 框架开发自定义 UI 框架适配器。

---

## 目录

- [架构概览](#架构概览)
- [快速开始](#快速开始)
- [适配器接口](#适配器接口)
- [组件映射](#组件映射)
- [主题变量](#主题变量)
- [注册适配器](#注册适配器)
- [发布适配器](#发布适配器)
- [示例：适配 Vuetify](#示例适配-vuetify)

---

## 架构概览

```
┌─────────────────────────────────────────────────────────────┐
│                    UI Core                                  │
├─────────────────────────────────────────────────────────────┤
│  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐         │
│  │   Registry  │  │   Theme     │  │  Components │         │
│  │  (注册表)    │  │  Manager    │  │  (组件)      │         │
│  └─────────────┘  └─────────────┘  └─────────────┘         │
├─────────────────────────────────────────────────────────────┤
│                    Adapters                                 │
├─────────────────────────────────────────────────────────────┤
│  ┌──────────┐ ┌──────────┐ ┌──────────┐ ┌──────────┐       │
│  │ Element  │ │  Ant     │ │  Naive   │ │ Custom   │       │
│  │  Plus    │ │  Design  │ │   UI     │ │ Adapter  │       │
│  └──────────┘ └──────────┘ └──────────┘ └──────────┘       │
└─────────────────────────────────────────────────────────────┘
```

---

## 快速开始

### 1. 创建适配器文件

```typescript
// my-ui-adapter.ts
import type { UIFrameworkAdapter, UIFrameworkMetadata } from '@multi-tenant-saas/ui-core'

export const myUIMetadata: UIFrameworkMetadata = {
  name: 'my-ui',  // 必须唯一
  label: 'My UI',
  description: '我的自定义 UI 框架',
  version: '1.0.0',
  website: 'https://example.com',
  icon: 'my-ui:logo',
  features: ['特性1', '特性2', '特性3'],
  installCommand: 'npm install my-ui',
}

export const myUIAdapter: UIFrameworkAdapter = {
  name: 'my-ui',
  metadata: myUIMetadata,
  
  async install(app) {
    // 安装你的 UI 框架
    const MyUI = await import('my-ui')
    app.use(MyUI.default)
  },
  
  getComponentMap() {
    return {
      Button: 'my-button',
      Input: 'my-input',
      // ... 其他组件映射
    }
  },
  
  getThemeVariables(mode) {
    if (mode === 'dark') {
      return {
        '--my-bg-color': '#1e1e1e',
        '--my-text-color': '#ffffff',
        // ... 其他暗色主题变量
      }
    }
    return {}
  },
}
```

### 2. 注册适配器

```typescript
// main.ts
import { uiRegistry } from '@multi-tenant-saas/ui-core'
import { myUIAdapter } from './my-ui-adapter'

// 注册适配器
uiRegistry.register(myUIAdapter)

// 设置为活跃框架
uiRegistry.setActive('my-ui')
```

### 3. 使用适配器

```typescript
// app.ts
import { uiRegistry } from '@multi-tenant-saas/ui-core'

const app = createApp(App)

// 安装活跃框架
await uiRegistry.installActive(app)
```

---

## 适配器接口

### UIFrameworkAdapter

```typescript
interface UIFrameworkAdapter {
  // 框架名称（唯一标识）
  name: UIFrameworkName
  
  // 框架元数据
  metadata: UIFrameworkMetadata
  
  // 安装框架到 Vue 应用
  install(app: App): Promise<void>
  
  // 获取组件映射
  getComponentMap(): Record<string, string>
  
  // 获取主题变量
  getThemeVariables(mode: 'light' | 'dark'): Record<string, string>
  
  // 清理资源（可选）
  uninstall?(): void
}
```

### UIFrameworkMetadata

```typescript
interface UIFrameworkMetadata {
  // 框架名称
  name: UIFrameworkName
  
  // 显示标签
  label: string
  
  // 描述
  description: string
  
  // 版本
  version: string
  
  // 官网
  website: string
  
  // 图标
  icon: string
  
  // 特性列表
  features: string[]
  
  // 安装命令
  installCommand: string
}
```

---

## 组件映射

组件映射用于将通用组件名映射到具体 UI 框架的组件名。

### 必需组件

以下组件是框架核心使用的，必须提供映射：

```typescript
{
  // 基础
  Button: '组件名',
  
  // 表单
  Input: '组件名',
  Select: '组件名',
  Option: '组件名',
  Radio: '组件名',
  RadioGroup: '组件名',
  Checkbox: '组件名',
  Switch: '组件名',
  DatePicker: '组件名',
  
  // 数据展示
  Table: '组件名',
  Tag: '组件名',
  Pagination: '组件名',
  Empty: '组件名',
  
  // 导航
  Menu: '组件名',
  MenuItem: '组件名',
  Tabs: '组件名',
  Breadcrumb: '组件名',
  BreadcrumbItem: '组件名',
  Dropdown: '组件名',
  
  // 反馈
  Dialog: '组件名',
  Drawer: '组件名',
  Tooltip: '组件名',
  Message: '组件名或函数',
  Notification: '组件名或函数',
  
  // 布局
  Layout: '组件名',
  Header: '组件名',
  Sider: '组件名',
  Content: '组件名',
  Row: '组件名',
  Col: '组件名',
  Card: '组件名',
}
```

### 可选组件

以下组件是可选的，根据框架支持情况提供：

```typescript
{
  // 表单
  InputNumber: '组件名',
  TimePicker: '组件名',
  DateTimePicker: '组件名',
  Upload: '组件名',
  Transfer: '组件名',
  ColorPicker: '组件名',
  Rate: '组件名',
  
  // 数据展示
  Progress: '组件名',
  Tree: '组件名',
  Badge: '组件名',
  Avatar: '组件名',
  Skeleton: '组件名',
  Descriptions: '组件名',
  Statistic: '组件名',
  
  // 导航
  Steps: '组件名',
  
  // 反馈
  Popconfirm: '组件名',
  
  // 布局
  Footer: '组件名',
  Divider: '组件名',
  Collapse: '组件名',
  Space: '组件名',
}
```

---

## 主题变量

主题变量用于在浅色/深色模式下设置 UI 框架的颜色。

### 常用变量

```typescript
{
  // 背景色
  '--bg-color': '#ffffff',
  '--bg-color-page': '#f5f7fa',
  '--bg-color-container': '#ffffff',
  
  // 文本色
  '--text-color-primary': '#303133',
  '--text-color-secondary': '#606266',
  '--text-color-disabled': '#c0c4cc',
  
  // 边框色
  '--border-color': '#dcdfe6',
  '--border-color-light': '#e4e7ed',
  
  // 填充色
  '--fill-color': '#f0f2f5',
  '--fill-color-light': '#f5f7fa',
}
```

### 深色模式变量

```typescript
{
  // 背景色
  '--bg-color': '#141414',
  '--bg-color-page': '#0a0a0a',
  '--bg-color-container': '#1d1e1f',
  
  // 文本色
  '--text-color-primary': '#e5eaf3',
  '--text-color-secondary': '#a3a6ad',
  '--text-color-disabled': '#6c6e72',
  
  // 边框色
  '--border-color': '#4c4d4f',
  '--border-color-light': '#414243',
  
  // 填充色
  '--fill-color': '#303030',
  '--fill-color-light': '#262727',
}
```

---

## 注册适配器

### 方式一：全局注册

```typescript
// main.ts
import { uiRegistry } from '@multi-tenant-saas/ui-core'
import { myUIAdapter } from './my-ui-adapter'

uiRegistry.register(myUIAdapter)
```

### 方式二：动态注册

```typescript
// 动态导入适配器
const { myUIAdapter } = await import('./my-ui-adapter')
uiRegistry.register(myUIAdapter)
```

### 方式三：插件注册

```typescript
// my-ui-plugin.ts
import type { Plugin } from 'vue'
import { uiRegistry } from '@multi-tenant-saas/ui-core'
import { myUIAdapter } from './my-ui-adapter'

export const MyUIPlugin: Plugin = {
  install(app) {
    uiRegistry.register(myUIAdapter)
  },
}

// main.ts
app.use(MyUIPlugin)
```

---

## 发布适配器

### 1. 创建 npm 包

```json
{
  "name": "@my-org/ui-adapter-my-ui",
  "version": "1.0.0",
  "main": "dist/index.js",
  "types": "dist/index.d.ts",
  "peerDependencies": {
    "@multi-tenant-saas/ui-core": "^1.0.0",
    "my-ui": "^1.0.0"
  }
}
```

### 2. 导出适配器

```typescript
// src/index.ts
export { myUIAdapter, myUIMetadata } from './adapter'
```

### 3. 使用适配器

```typescript
// 安装
npm install @my-org/ui-adapter-my-ui

// 使用
import { myUIAdapter } from '@my-org/ui-adapter-my-ui'
uiRegistry.register(myUIAdapter)
```

---

## 示例：适配 Vuetify

以下是一个完整的 Vuetify 3 适配器示例：

```typescript
// vuetify-adapter.ts
import type { UIFrameworkAdapter, UIFrameworkMetadata } from '@multi-tenant-saas/ui-core'

export const vuetifyMetadata: UIFrameworkMetadata = {
  name: 'vuetify',
  label: 'Vuetify',
  description: 'Material Design 组件框架，Vue 3 官方推荐',
  version: '^3.4.0',
  website: 'https://vuetifyjs.com',
  icon: 'vuetify:vuetify',
  features: [
    'Material Design 3',
    '100+ 组件',
    '完整的 TypeScript 支持',
    '强大的主题系统',
    '国际化支持',
  ],
  installCommand: 'npm install vuetify @mdi/font',
}

export const vuetifyAdapter: UIFrameworkAdapter = {
  name: 'vuetify',
  metadata: vuetifyMetadata,
  
  async install(app) {
    const { createVuetify } = await import('vuetify')
    const { aliases, mdi } = await import('vuetify/iconsets/mdi')
    
    const vuetify = createVuetify({
      icons: {
        defaultSet: 'mdi',
        aliases,
        sets: { mdi },
      },
      theme: {
        defaultTheme: 'light',
        themes: {
          light: {
            colors: {
              primary: '#1867C0',
              secondary: '#5CBBF6',
            },
          },
          dark: {
            colors: {
              primary: '#2196F3',
              secondary: '#424242',
            },
          },
        },
      },
    })
    
    app.use(vuetify)
  },
  
  getComponentMap() {
    return {
      // 基础
      Button: 'v-btn',
      Link: 'a',
      Text: 'span',
      
      // 表单
      Input: 'v-text-field',
      InputNumber: 'v-text-field',
      Select: 'v-select',
      Option: 'v-list-item',
      Radio: 'v-radio',
      RadioGroup: 'v-radio-group',
      Checkbox: 'v-checkbox',
      Switch: 'v-switch',
      Slider: 'v-slider',
      TimePicker: 'v-time-picker',
      DatePicker: 'v-date-picker',
      Upload: 'v-file-input',
      
      // 数据展示
      Table: 'v-table',
      Tag: 'v-chip',
      Progress: 'v-progress-linear',
      Tree: 'v-treeview',
      Pagination: 'v-pagination',
      Badge: 'v-badge',
      Avatar: 'v-avatar',
      Skeleton: 'v-skeleton-loader',
      Empty: 'v-card',
      
      // 导航
      Menu: 'v-list',
      MenuItem: 'v-list-item',
      SubMenu: 'v-list-group',
      Tabs: 'v-tabs',
      TabPane: 'v-tab',
      Breadcrumb: 'v-breadcrumbs',
      BreadcrumbItem: 'v-breadcrumbs-item',
      Dropdown: 'v-menu',
      Steps: 'v-stepper',
      Step: 'v-stepper-window-item',
      
      // 反馈
      Dialog: 'v-dialog',
      Drawer: 'v-navigation-drawer',
      Tooltip: 'v-tooltip',
      Popover: 'v-menu',
      Popconfirm: 'v-dialog',
      Message: 'v-snackbar',
      Notification: 'v-snackbar',
      
      // 布局
      Layout: 'v-app',
      Header: 'v-app-bar',
      Sider: 'v-navigation-drawer',
      Content: 'v-main',
      Footer: 'v-footer',
      Row: 'v-row',
      Col: 'v-col',
      Divider: 'v-divider',
      Card: 'v-card',
      Collapse: 'v-expansion-panels',
      CollapseItem: 'v-expansion-panel',
      Space: 'div',
    }
  },
  
  getThemeVariables(mode) {
    if (mode === 'dark') {
      return {
        '--v-theme-background': '#121212',
        '--v-theme-surface': '#1E1E1E',
        '--v-theme-on-background': '#ffffff',
        '--v-theme-on-surface': '#ffffff',
      }
    }
    return {}
  },
}
```

---

## 最佳实践

1. **组件映射完整性**
   - 确保所有必需组件都有映射
   - 可选组件根据框架支持情况提供

2. **主题变量一致性**
   - 浅色和深色模式都要提供
   - 变量名要与框架文档一致

3. **按需导入**
   - 使用动态导入减少打包体积
   - 只导入使用的组件

4. **错误处理**
   - 在 install 方法中处理导入错误
   - 提供友好的错误提示

5. **文档完善**
   - 提供清晰的使用说明
   - 列出框架特性和限制
