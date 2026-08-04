const routes = [
  {
    path: 'commerce/catalog',
    name: 'commerce-console-catalog',
    component: () => import('./ui/element-plus/views/SkuCatalog.vue'),
    meta: { title: '商品目录', requiresAuth: true, module: 'commerce' },
  },
  {
    path: 'commerce/orders',
    name: 'commerce-console-orders',
    component: () => import('./ui/element-plus/views/OrderList.vue'),
    meta: { title: '我的订单', requiresAuth: true, module: 'commerce' },
  },
  {
    path: 'commerce/orders/:id',
    name: 'commerce-console-order-detail',
    component: () => import('./ui/element-plus/views/OrderDetail.vue'),
    meta: { title: '订单详情', requiresAuth: true, module: 'commerce' },
  },
]

export default routes
