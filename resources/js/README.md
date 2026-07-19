# 前端模块

框架提供三个独立的前端模块，可选择安装：

- `@multi-tenant-saas/admin` - 系统后台
- `@multi-tenant-saas/console` - 团队后台
- `@multi-tenant-saas/app` - 用户前台

---

## 模块安装

### 系统后台 (admin)

```bash
cd resources/js/admin
npm install
npm run build
```

### 团队后台 (console)

```bash
cd resources/js/console
npm install
npm run build
```

### 用户前台 (app)

```bash
cd resources/js/app
npm install
npm run build
```

---

## 开发模式

```bash
# 启动开发服务器
cd resources/js/admin
npm run dev

# 访问 http://localhost:5173
```

---

## 自定义开发

### 目录结构

```
resources/js/
├── admin/                    # 系统后台
│   ├── components/          # 组件
│   ├── views/              # 视图
│   ├── stores/             # 状态管理
│   ├── router/             # 路由
│   ├── App.vue             # 主组件
│   ├── main.ts             # 入口文件
│   └── package.json        # 依赖配置
├── console/                  # 团队后台
│   └── ...
└── app/                      # 用户前台
    └── ...
```

### 添加新页面

1. 在 `views/` 目录创建新组件
2. 在 `router/index.ts` 添加路由
3. 在 `App.vue` 添加菜单项

### 调用 API

```typescript
import axios from 'axios'

// 获取团队列表
const response = await axios.get('/api/v1/tenants', {
  params: {
    page: 1,
    per_page: 15,
  },
})

// 创建团队
const response = await axios.post('/api/v1/tenants', {
  name: '新企业',
  slug: 'new-company',
})
```

---

## 构建部署

### 构建生产版本

```bash
cd resources/js/admin
npm run build
```

构建产物将输出到 `public/admin/` 目录。

### Nginx 配置

```nginx
# 系统后台
location /admin/ {
    alias /path/to/public/admin/;
    try_files $uri $uri/ /admin/index.html;
}
```

---

## API 文档

完整的 API 文档请参考 [OpenAPI 规范](../api/openapi.yaml)。

---

## 技术栈

- **Vue 3**: 前端框架
- **Vue Router**: 路由管理
- **Pinia**: 状态管理
- **Element Plus**: UI 组件库
- **Axios**: HTTP 客户端
- **Vite**: 构建工具
