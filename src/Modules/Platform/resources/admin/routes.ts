const routes = [
  {
    path: 'tenant-applications',
    name: 'platform-tenant-applications',
    component: () => import('./ui/element-plus/views/TenantApplications.vue'),
    meta: {
      title: 'Tenant Applications', requiresAuth: true, module: 'platform',
      menu: { section: '平台运营', label: '租户应用', icon: 'FolderOpened', perm: 'tenant.view' },
    },
  },
  {
    path: 'tenant-applications/:id',
    name: 'platform-tenant-application-detail',
    component: () => import('./ui/element-plus/views/TenantApplicationDetail.vue'),
    meta: { title: 'Application Detail', requiresAuth: true, module: 'platform' },
  },
  {
    path: 'apply-field-config',
    name: 'platform-apply-field-config',
    component: () => import('./ui/element-plus/views/ApplyFieldConfig.vue'),
    meta: {
      title: 'Apply Field Config', requiresAuth: true, module: 'platform',
      menu: { section: '平台配置', label: '申请字段配置', icon: 'EditPen', perm: 'setting.view' },
    },
  },
  // 有自定义 routes.ts 的模块不再走视图自动发现，需在此显式声明全部页面
  {
    path: 'settings',
    name: 'platform-settings',
    component: () => import('./ui/element-plus/views/Settings.vue'),
    meta: { title: '配置中心', requiresAuth: true, module: 'platform' },
  },
  {
    path: 'system-settings',
    name: 'platform-system-settings',
    component: () => import('./ui/element-plus/views/SystemSettings.vue'),
    meta: { title: '系统设置', requiresAuth: true, module: 'platform' },
  },
]

export default routes
