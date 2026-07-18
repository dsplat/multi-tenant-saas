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
]

export default routes
