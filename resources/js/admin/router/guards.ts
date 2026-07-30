import type { NavigationGuard } from 'vue-router'

// 认证守卫依赖的最小 store 契约（便于单测注入假 store）
export interface AuthStoreLike {
  token: string | null
  user: unknown | null
  fetchUser: () => Promise<void>
}

// 从 index.ts 抽出的认证守卫：未登录跳登录页；token 失效清理后跳登录页
export function createAuthGuard(getStore: () => AuthStoreLike): NavigationGuard {
  return async (to, _from, next) => {
    if (to.meta.requiresAuth !== false) {
      const userStore = getStore()
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
  }
}
