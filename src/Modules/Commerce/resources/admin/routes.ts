// 有自定义 routes.ts 的模块不再走视图自动发现，需在此显式声明全部页面；
// meta.menu 声明侧边菜单（AdminLayout 动态聚合，无需改布局硬编码）
const MENU_SECTION = '商业运营'

const routes = [
  {
    path: 'sku-pool',
    name: 'commerce-admin-sku-pool',
    component: () => import('./ui/element-plus/views/SkuPool.vue'),
    meta: {
      title: 'SKU 商品池', requiresAuth: true, module: 'commerce',
      menu: { section: MENU_SECTION, label: 'SKU 商品池', icon: 'Goods', perm: 'setting.view' },
    },
  },
  {
    path: 'supply-grants',
    name: 'commerce-admin-supply-grants',
    component: () => import('./ui/element-plus/views/SupplyGrants.vue'),
    meta: {
      title: '供给授权', requiresAuth: true, module: 'commerce',
      menu: { section: MENU_SECTION, label: '供给授权', icon: 'Unlock', perm: 'setting.view' },
    },
  },
  {
    path: 'commerce-orders',
    name: 'commerce-admin-orders',
    component: () => import('./ui/element-plus/views/CommerceOrders.vue'),
    meta: {
      title: '商业体订单', requiresAuth: true, module: 'commerce',
      menu: { section: MENU_SECTION, label: '商业订单', icon: 'ShoppingCart', perm: 'setting.view' },
    },
  },
  {
    path: 'prepay-accounts',
    name: 'commerce-admin-prepay-accounts',
    component: () => import('./ui/element-plus/views/PrepayAccounts.vue'),
    meta: {
      title: '预存货款', requiresAuth: true, module: 'commerce',
      menu: { section: MENU_SECTION, label: '预存货款', icon: 'Wallet', perm: 'setting.view' },
    },
  },
  {
    path: 'deposits',
    name: 'commerce-admin-deposits',
    component: () => import('./ui/element-plus/views/Deposits.vue'),
    meta: {
      title: '域名保证金', requiresAuth: true, module: 'commerce',
      menu: { section: MENU_SECTION, label: '域名保证金', icon: 'Money', perm: 'setting.view' },
    },
  },
  {
    path: 'content-library',
    name: 'commerce-admin-content-library',
    component: () => import('./ui/element-plus/views/ContentLibrary.vue'),
    meta: {
      title: '平台内容库', requiresAuth: true, module: 'commerce',
      menu: { section: MENU_SECTION, label: '内容库', icon: 'Collection', perm: 'setting.view' },
    },
  },
]

export default routes
