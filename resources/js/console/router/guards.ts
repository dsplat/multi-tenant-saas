import type { NavigationGuard } from 'vue-router'

// 认证守卫依赖的最小 store 契约（便于单测注入假 store）
export interface AuthStoreLike {
  token: string | null
  user: unknown | null
  tenantId: string
  fetchUser: () => Promise<void>
}

// 从 index.ts 抽出的认证守卫：
// 1. 未登录跳登录页
// 2. token 失效清理后跳登录页
// 3. 无租户用户只能访问申请页，其余页面引导去申请创建团队
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
          localStorage.removeItem('auth_token')
          next({ name: 'Login', query: { redirect: to.fullPath } })
          return
        }
      }
      const needsTenant = to.meta.requiresTenant !== false
      const hasTenant = !!userStore.tenantId
      if (needsTenant && !hasTenant && to.name !== 'ApplyTeam') {
        next({ name: 'ApplyTeam' })
        return
      }
    }
    next()
  }
}
