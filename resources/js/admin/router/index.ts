import { createRouter, createWebHistory } from 'vue-router'
import { useUserStore } from '../stores/user'
import { getAllModuleRoutes } from '../module-loader'

// 绝对路径从 Vite root（项目根）开始
// 框架自带页面（vendor 内，框架开发时此 glob 为空，走本地）
const frameworkLayouts = import.meta.glob('/vendor/dsplat/multi-tenant-saas/resources/pages/admin/ui/*/layouts/*.vue')
const frameworkViews = import.meta.glob('/vendor/dsplat/multi-tenant-saas/resources/pages/admin/ui/*/views/*.vue')
// 本地覆盖页面（框架开发时指向自身，下游项目指向覆盖层）
const localLayouts = import.meta.glob('/resources/pages/admin/ui/*/layouts/*.vue')
const localViews = import.meta.glob('/resources/pages/admin/ui/*/views/*.vue')

function getFramework(): string {
  return localStorage.getItem('multi-tenant-saas-ui-framework')
    || (import.meta.env.VITE_UI_FRAMEWORK as string)
    || 'element-plus'
}

const fw = getFramework()

// 优先级：本地 → 框架 vendor → bootstrap 降级
function resolveView(name: string): () => Promise<any> {
  // 1. 本地覆盖 — 当前框架
  const localFwPath = `/resources/pages/admin/ui/${fw}/views/${name}.vue`
  if (localViews[localFwPath]) return () => (localViews[localFwPath] as () => Promise<any>)()
  // 2. 框架 vendor — 当前框架
  const vendorFwPath = `/vendor/dsplat/multi-tenant-saas/resources/pages/admin/ui/${fw}/views/${name}.vue`
  if (frameworkViews[vendorFwPath]) return () => (frameworkViews[vendorFwPath] as () => Promise<any>)()
  // 3. 本地覆盖 — bootstrap 降级
  const localBsPath = `/resources/pages/admin/ui/bootstrap/views/${name}.vue`
  if (localViews[localBsPath]) return () => (localViews[localBsPath] as () => Promise<any>)()
  // 4. 框架 vendor — bootstrap 降级
  const vendorBsPath = `/vendor/dsplat/multi-tenant-saas/resources/pages/admin/ui/bootstrap/views/${name}.vue`
  if (frameworkViews[vendorBsPath]) return () => (frameworkViews[vendorBsPath] as () => Promise<any>)()
  throw new Error(`View not found: ${name}`)
}

function resolveLayout(name: string): () => Promise<any> {
  const localFw = `/resources/pages/admin/ui/${fw}/layouts/${name}.vue`
  if (localLayouts[localFw]) return () => (localLayouts[localFw] as () => Promise<any>)()
  const vendorFw = `/vendor/dsplat/multi-tenant-saas/resources/pages/admin/ui/${fw}/layouts/${name}.vue`
  if (frameworkLayouts[vendorFw]) return () => (frameworkLayouts[vendorFw] as () => Promise<any>)()
  const localBs = `/resources/pages/admin/ui/bootstrap/layouts/${name}.vue`
  if (localLayouts[localBs]) return () => (localLayouts[localBs] as () => Promise<any>)()
  const vendorBs = `/vendor/dsplat/multi-tenant-saas/resources/pages/admin/ui/bootstrap/layouts/${name}.vue`
  if (frameworkLayouts[vendorBs]) return () => (frameworkLayouts[vendorBs] as () => Promise<any>)()
  throw new Error(`Layout not found: ${name}`)
}

const router = createRouter({
  history: createWebHistory('/admin/'),
  routes: [
    {
      path: '/login',
      name: 'Login',
      component: resolveView('Login'),
      meta: { title: '登录', requiresAuth: false },
    },
    {
      path: '/',
      name: 'AdminRoot',
      component: resolveLayout('AdminLayout'),
      redirect: '/dashboard',
      children: [
        {
          path: 'dashboard',
          name: 'Dashboard',
          component: resolveView('Dashboard'),
          meta: { title: '仪表盘', requiresAuth: true },
        },
        {
          path: 'queue-failed',
          name: 'QueueFailed',
          component: resolveView('QueueFailed'),
          meta: { title: '失败队列', requiresAuth: true, permission: 'setting.view' },
        },
      ],
    },
  ],
})

// 动态加载模块路由
getAllModuleRoutes().then(moduleRoutes => {
  if (moduleRoutes.length > 0) {
    const mainRoute = router.getRoutes().find(r => r.name === 'AdminRoot')
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
