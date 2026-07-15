import { createRouter, createWebHistory } from 'vue-router'
import { useUserStore } from '../stores/user'
import { getAllModuleRoutes } from '../module-loader'

const router = createRouter({
  history: createWebHistory('/admin/'),
  routes: [
    {
      path: '/login',
      name: 'Login',
      component: () => import('../views/Login.vue'),
      meta: { title: '登录', requiresAuth: false },
    },
    {
      path: '/',
      component: () => import('../layouts/AdminLayout.vue'),
      redirect: '/dashboard',
      children: [
        {
          path: 'dashboard',
          name: 'Dashboard',
          component: () => import('../views/Dashboard.vue'),
          meta: { title: '仪表盘', requiresAuth: true },
        },
        {
          path: 'tenants',
          name: 'Tenants',
          component: () => import('../views/Tenants.vue'),
          meta: { title: '租户管理', requiresAuth: true },
        },
        {
          path: 'tenants/:id',
          name: 'TenantDetail',
          component: () => import('../views/TenantDetail.vue'),
          meta: { title: '租户详情', requiresAuth: true },
        },
        {
          path: 'users',
          name: 'Users',
          component: () => import('../views/Users.vue'),
          meta: { title: '用户管理', requiresAuth: true },
        },
        {
          path: 'domains',
          name: 'Domains',
          component: () => import('../views/DomainSettings.vue'),
          meta: { title: '域名管理', requiresAuth: true },
        },
        {
          path: 'oauth',
          name: 'OAuthSettings',
          component: () => import('../views/OAuthSettings.vue'),
          meta: { title: '第三方登录', requiresAuth: true },
        },
        {
          path: 'audit-logs',
          name: 'AuditLogs',
          component: () => import('../views/AuditLogs.vue'),
          meta: { title: '审计日志', requiresAuth: true },
        },
        {
          path: 'sms',
          name: 'SmsSettings',
          component: () => import('../views/SmsSettings.vue'),
          meta: { title: '短信配置', requiresAuth: true },
        },
        {
          path: 'payments',
          name: 'PaymentOrders',
          component: () => import('../views/PaymentOrders.vue'),
          meta: { title: '支付订单', requiresAuth: true },
        },
        {
          path: 'api-tokens',
          name: 'ApiTokens',
          component: () => import('../views/ApiTokens.vue'),
          meta: { title: 'API Token', requiresAuth: true },
        },
        {
          path: 'quotas',
          name: 'Quotas',
          component: () => import('../views/Quotas.vue'),
          meta: { title: '配额管理', requiresAuth: true },
        },
        {
          path: 'operators',
          name: 'Operators',
          component: () => import('../views/Operators.vue'),
          meta: { title: '运营人员', requiresAuth: true },
        },
        {
          path: 'roles',
          name: 'Roles',
          component: () => import('../views/Roles.vue'),
          meta: { title: '角色权限', requiresAuth: true },
        },
        {
          path: 'plans',
          name: 'Plans',
          component: () => import('../views/Plans.vue'),
          meta: { title: '订阅计划', requiresAuth: true },
        },
        {
          path: 'modules',
          name: 'Modules',
          component: () => import('../views/Modules.vue'),
          meta: { title: '模块管理', requiresAuth: true },
        },
        {
          path: 'plugins',
          name: 'Plugins',
          component: () => import('../views/Plugins.vue'),
          meta: { title: '插件管理', requiresAuth: true },
        },
        {
          path: 'ssl',
          name: 'SSL',
          component: () => import('../views/SSL.vue'),
          meta: { title: 'SSL 证书', requiresAuth: true },
        },
        {
          path: 'webhooks',
          name: 'Webhooks',
          component: () => import('../views/Webhooks.vue'),
          meta: { title: 'Webhooks', requiresAuth: true },
        },
        {
          path: 'feature-flags',
          name: 'FeatureFlags',
          component: () => import('../views/FeatureFlags.vue'),
          meta: { title: '功能开关', requiresAuth: true },
        },
        {
          path: 'ip-whitelist',
          name: 'IpWhitelist',
          component: () => import('../views/IpWhitelist.vue'),
          meta: { title: 'IP 白名单', requiresAuth: true },
        },
        {
          path: 'branding',
          name: 'Branding',
          component: () => import('../views/Branding.vue'),
          meta: { title: '品牌配置', requiresAuth: true },
        },
        {
          path: 'sso-providers',
          name: 'SsoProviders',
          component: () => import('../views/SsoProviders.vue'),
          meta: { title: 'SSO 提供商', requiresAuth: true },
        },
        {
          path: 'credits',
          name: 'CreditsOverview',
          component: () => import('../views/Credits.vue'),
          meta: { title: '积分总览', requiresAuth: true },
        },
        {
          path: 'system-settings',
          name: 'SystemSettings',
          component: () => import('../views/SystemSettings.vue'),
          meta: { title: '系统设置', requiresAuth: true },
        },
        {
          path: 'tenant-keys',
          name: 'TenantKeys',
          component: () => import('../views/TenantKeys.vue'),
          meta: { title: '租户密钥', requiresAuth: true },
        },
        {
          path: 'retention-policies',
          name: 'RetentionPolicies',
          component: () => import('../views/RetentionPolicies.vue'),
          meta: { title: '数据保留策略', requiresAuth: true },
        },
        {
          path: 'consents',
          name: 'Consents',
          component: () => import('../views/Consents.vue'),
          meta: { title: '合规同意', requiresAuth: true },
        },
        {
          path: 'sandbox',
          name: 'Sandbox',
          component: () => import('../views/Sandbox.vue'),
          meta: { title: '沙箱环境', requiresAuth: true },
        },
        {
          path: 'settings',
          name: 'Settings',
          component: () => import('../views/Settings.vue'),
          meta: { title: '配置中心', requiresAuth: true },
        },
      ],
    },
  ],
})

// 动态加载模块路由
getAllModuleRoutes().then(moduleRoutes => {
  if (moduleRoutes.length > 0) {
    const mainRoute = router.getRoutes().find(r => r.name === undefined && r.path === '/')
    if (mainRoute) {
      for (const route of moduleRoutes) {
        router.addRoute(mainRoute.name as string, {
          path: route.path,
          name: route.name,
          component: route.component,
          meta: route.meta,
        })
      }
    }
  }
})

router.beforeEach(async (to, _from, next) => {
  if (to.meta.requiresAuth !== false) {
    const userStore = useUserStore()
    if (!userStore.token) {
      next({ name: 'Login', query: { redirect: to.fullPath } })
      return
    }
    if (!userStore.user) {
      try {
        await userStore.fetchUser()
      } catch {
        userStore.token = null
        localStorage.removeItem('admin_token')
        next({ name: 'Login', query: { redirect: to.fullPath } })
        return
      }
    }
  }
  next()
})

export default router
