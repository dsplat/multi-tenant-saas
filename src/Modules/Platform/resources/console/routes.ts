const routes = [
  {
    path: 'apply',
    name: 'platform-apply',
    component: () => import('./ui/element-plus/views/ApplyTenant.vue'),
    meta: { title: 'Apply Tenant', requiresAuth: true, module: 'platform' },
  },
  {
    path: 'my-applications',
    name: 'platform-my-applications',
    component: () => import('./ui/element-plus/views/MyApplications.vue'),
    meta: { title: 'My Applications', requiresAuth: true, module: 'platform' },
  },
  // 本模块已有自定义 routes.ts，module-loader 视图自动发现已关闭，
  // 模块内页面必须在此显式注册（否则侧边栏/小助手带路均死链）
  {
    path: 'tenant-settings',
    name: 'platform-tenant-settings',
    component: () => import('./ui/element-plus/views/TenantSettings.vue'),
    meta: { title: '租户设置', requiresAuth: true, module: 'platform' },
  },
]

export default routes
