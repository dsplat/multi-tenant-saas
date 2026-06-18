import { createRouter, createWebHistory } from 'vue-router'
import { useUserStore } from '../stores/user'

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
          path: 'settings',
          name: 'Settings',
          component: () => import('../views/Settings.vue'),
          meta: { title: '系统设置', requiresAuth: true },
        },
      ],
    },
  ],
})

router.beforeEach(async (to, _from, next) => {
  if (to.meta.requiresAuth !== false) {
    const userStore = useUserStore()
    if (!userStore.token) {
      next({ name: 'Login', query: { redirect: to.fullPath } })
      return
    }
  }
  next()
})

export default router
