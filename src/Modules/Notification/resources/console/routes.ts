const routes = [
  {
    path: 'mail-templates',
    name: 'notification-console-mail-templates',
    component: () => import('./ui/element-plus/views/TenantMailTemplates.vue'),
    meta: { title: 'Mail Templates', requiresAuth: true, module: 'notification' },
  },
]

export default routes
