const routes = [
  {
    path: 'tenant-applications',
    name: 'platform-tenant-applications',
    component: () => import('./ui/element-plus/views/TenantApplications.vue'),
    meta: { title: 'Tenant Applications', requiresAuth: true, module: 'platform' },
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
    meta: { title: 'Apply Field Config', requiresAuth: true, module: 'platform' },
  },
]

export default routes
