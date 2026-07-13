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
          path: 'settings',
          name: 'Settings',
          component: () => import('../views/Settings.vue'),
          meta: { title: '系统设置', requiresAuth: true },
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
