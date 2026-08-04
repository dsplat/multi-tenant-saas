const routes = [
  {
    path: 'commerce-orders',
    name: 'commerce-admin-orders',
    component: () => import('./ui/element-plus/views/CommerceOrders.vue'),
    meta: { title: '商业体订单', requiresAuth: true, module: 'commerce' },
  },
  {
    path: 'content-library',
    name: 'commerce-admin-content-library',
    component: () => import('./ui/element-plus/views/ContentLibrary.vue'),
    meta: { title: '平台内容库', requiresAuth: true, module: 'commerce' },
  },
]

export default routes
