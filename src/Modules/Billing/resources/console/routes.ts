const routes = [
  {
    path: 'billing/credits',
    name: 'billing-console-credits',
    component: () => import('./ui/element-plus/views/Credits.vue'),
    meta: { title: '积分管理', requiresAuth: true, module: 'billing' },
  },
  {
    path: 'billing/quotas',
    name: 'billing-console-quotas',
    component: () => import('./ui/element-plus/views/Quotas.vue'),
    meta: { title: '配额管理', requiresAuth: true, module: 'billing' },
  },
]

export default routes
