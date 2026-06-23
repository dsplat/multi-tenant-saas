import { createRouter, createWebHistory } from 'vue-router'

const router = createRouter({
  history: createWebHistory('/console/'),
  routes: [
    {
      path: '/login',
      name: 'Login',
      component: () => import('../views/Login.vue'),
      meta: { title: '登录', requiresAuth: false },
    },
    {
      path: '/',
      component: () => import('../layouts/ConsoleLayout.vue'),
      redirect: '/dashboard',
      children: [
        {
          path: 'dashboard',
          name: 'Dashboard',
          component: () => import('../views/Dashboard.vue'),
          meta: { title: '工作台', requiresAuth: true },
        },
        {
          path: 'members',
          name: 'Members',
          component: () => import('../views/Members.vue'),
          meta: { title: '成员管理', requiresAuth: true },
        },
        {
          path: 'settings',
          name: 'Settings',
          component: () => import('../views/Settings.vue'),
          meta: { title: '租户设置', requiresAuth: true },
        },
        {
          path: 'credits',
          name: 'Credits',
          component: () => import('../views/Credits.vue'),
          meta: { title: '积分管理', requiresAuth: true },
        },
        {
          path: 'tenant-settings',
          name: 'TenantSettings',
          component: () => import('../views/TenantSettings.vue'),
          meta: { title: '租户设置', requiresAuth: true },
        },
        {
          path: 'oauth',
          name: 'OAuthSettings',
          component: () => import('../views/OAuthSettings.vue'),
          meta: { title: '第三方登录', requiresAuth: true },
        },
        {
          path: 'sms',
          name: 'SmsSettings',
          component: () => import('../views/SmsSettings.vue'),
          meta: { title: '短信配置', requiresAuth: true },
        },
        {
          path: 'api-tokens',
          name: 'ApiTokens',
          component: () => import('../views/ApiTokens.vue'),
          meta: { title: 'API Token', requiresAuth: true },
        },
        {
          path: 'payment',
          name: 'PaymentSettings',
          component: () => import('../views/PaymentSettings.vue'),
          meta: { title: '支付配置', requiresAuth: true },
        },
      ],
    },
  ],
})

router.beforeEach(async (to, _from, next) => {
  if (to.meta.requiresAuth !== false) {
    const token = localStorage.getItem('console_token')
    if (!token) {
      next({ name: 'Login', query: { redirect: to.fullPath } })
      return
    }
  }
  next()
})

export default router
