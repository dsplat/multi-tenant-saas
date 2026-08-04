import type { NavigationGuard } from 'vue-router'

// 认证守卫依赖的最小 store 契约（便于单测注入假 store）
export interface AuthStoreLike {
  user: unknown | null
  fetchUser: () => Promise<void>
}

// 从 index.ts 抽出的认证守卫（Cookie 会话模式）：
// 无本地 token，登录态以 /auth/user 探测结果为准；探测失败跳登录页
export function createAuthGuard(getStore: () => AuthStoreLike): NavigationGuard {
  return async (to, _from, next) => {
    if (to.meta.requiresAuth !== false) {
      const userStore = getStore()
      if (!userStore.user) {
        try {
          await userStore.fetchUser()
        } catch {
          // 探测失败（未登录/会话失效/瞬时错误）一律按未登录处理
        }
        if (!userStore.user) {
          next({ name: 'Login', query: { redirect: to.fullPath } })
          return
        }
      }
    }
    next()
  }
}
