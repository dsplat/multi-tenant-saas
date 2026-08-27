// 有自定义 routes.ts 的模块不再走视图自动发现，需在此显式声明全部页面；
// meta.menu 声明侧边菜单（AdminLayout 动态聚合，无需改布局硬编码）
const MENU_SECTION = '平台配置'

const routes = [
  {
    path: 'service-provider-settings',
    name: 'wechatwork-admin-service-provider-settings',
    component: () => import('./ui/element-plus/views/ServiceProviderSettings.vue'),
    meta: {
      title: '企微服务商', requiresAuth: true, module: 'wechatwork',
      menu: { section: MENU_SECTION, label: '企微服务商', icon: 'Connection', perm: 'setting.view' },
    },
  },
]

export default routes
