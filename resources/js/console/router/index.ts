import { createRouter, createWebHistory } from 'vue-router'
import { useUserStore } from '../stores/user'
import { getAllModuleRoutes } from '../module-loader'

// 绝对路径从 Vite root（项目根）开始
const frameworkLayouts = import.meta.glob('/vendor/dsplat/multi-tenant-saas/resources/pages/console/ui/*/layouts/*.vue')
const frameworkViews = import.meta.glob('/vendor/dsplat/multi-tenant-saas/resources/pages/console/ui/*/views/*.vue')
const localLayouts = import.meta.glob('/resources/pages/console/ui/*/layouts/*.vue')
const localViews = import.meta.glob('/resources/pages/console/ui/*/views/*.vue')

function getFramework(): string {
  return localStorage.getItem('multi-tenant-saas-ui-framework')
    || (import.meta.env.VITE_UI_FRAMEWORK as string)
    || 'element-plus'
}

const fw = getFramework()

function resolveView(name: string): () => Promise<any> {
  const localFwPath = `/resources/pages/console/ui/${fw}/views/${name}.vue`
  if (localViews[localFwPath]) return () => (localViews[localFwPath] as () => Promise<any>)()
  const vendorFwPath = `/vendor/dsplat/multi-tenant-saas/resources/pages/console/ui/${fw}/views/${name}.vue`
  if (frameworkViews[vendorFwPath]) return () => (frameworkViews[vendorFwPath] as () => Promise<any>)()
  const localBsPath = `/resources/pages/console/ui/bootstrap/views/${name}.vue`
  if (localViews[localBsPath]) return () => (localViews[localBsPath] as () => Promise<any>)()
  const vendorBsPath = `/vendor/dsplat/multi-tenant-saas/resources/pages/console/ui/bootstrap/views/${name}.vue`
  if (frameworkViews[vendorBsPath]) return () => (frameworkViews[vendorBsPath] as () => Promise<any>)()
  throw new Error(`View not found: ${name}`)
}

function resolveLayout(name: string): () => Promise<any> {
  const localFw = `/resources/pages/console/ui/${fw}/layouts/${name}.vue`
  if (localLayouts[localFw]) return () => (localLayouts[localFw] as () => Promise<any>)()
  const vendorFw = `/vendor/dsplat/multi-tenant-saas/resources/pages/console/ui/${fw}/layouts/${name}.vue`
  if (frameworkLayouts[vendorFw]) return () => (frameworkLayouts[vendorFw] as () => Promise<any>)()
  const localBs = `/resources/pages/console/ui/bootstrap/layouts/${name}.vue`
  if (localLayouts[localBs]) return () => (localLayouts[localBs] as () => Promise<any>)()
  const vendorBs = `/vendor/dsplat/multi-tenant-saas/resources/pages/console/ui/bootstrap/layouts/${name}.vue`
  if (frameworkLayouts[vendorBs]) return () => (frameworkLayouts[vendorBs] as () => Promise<any>)()
  throw new Error(`Layout not found: ${name}`)
}

const router = createRouter({
  history: createWebHistory('/console/'),
  routes: [
    {
      path: '/login',
      name: 'Login',
      component: resolveView('Login'),
      meta: { title: '登录', requiresAuth: false },
    },
    {
      path: '/',
      name: 'ConsoleRoot',
      component: resolveLayout('ConsoleLayout'),
      redirect: '/dashboard',
      children: [
        {
          path: 'dashboard',
          name: 'Dashboard',
          component: resolveView('Dashboard'),
          meta: { title: '工作台', requiresAuth: true },
        },
      ],
    },
  ],
})

// 动态加载模块路由
getAllModuleRoutes().then(moduleRoutes => {
  if (moduleRoutes.length > 0) {
    const mainRoute = router.getRoutes().find(r => r.name === 'ConsoleRoot')
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
        localStorage.removeItem('console_token')
        next({ name: 'Login', query: { redirect: to.fullPath } })
        return
      }
    }
  }
  next()
})

export default router
