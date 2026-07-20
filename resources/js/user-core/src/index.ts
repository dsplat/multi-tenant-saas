/**
 * @dsplat/user-core
 *
 * 多租户 SaaS 终端用户前端基座 - Headless 逻辑包
 *
 * 零 UI 依赖，纯 TypeScript 实现。
 * 通过 configure() 注入请求适配器，适配任意前端框架。
 *
 * @example
 * import { configure, performLogin, fetchCurrentUser } from '@dsplat/user-core'
 *
 * // 初始化（应用启动时调用一次）
 * configure({
 *   request: async (url, opts) => {
 *     const res = await fetch(url, {
 *       method: opts?.method || 'GET',
 *       headers: opts?.headers,
 *       body: opts?.body ? JSON.stringify(opts.body) : undefined,
 *     })
 *     return res.json()
 *   },
 *   baseURL: '/api/v1',
 *   getToken: () => localStorage.getItem('user_token'),
 *   setToken: (t) => localStorage.setItem('user_token', t),
 *   clearToken: () => localStorage.removeItem('user_token'),
 * })
 *
 * // 使用
 * const result = await performLogin({ email: 'user@example.com', password: 'secret' })
 * if (result.success && !result.mfaRequired) {
 *   console.log('Welcome', result.user?.name)
 * }
 */

// 核心配置
export { configure, getConfig, request } from './api/client'
export type { UserCoreConfig, RequestAdapter, RequestOptions } from './api/client'

// 类型
export * from './types'

// API 端点常量
export {
  AUTH_ENDPOINTS,
  MFA_ENDPOINTS,
  NOTIFICATION_ENDPOINTS,
  TENANT_ENDPOINTS,
} from './api/endpoints'

// API 函数（按需使用）
export * as authApi from './api/auth.api'
export * as userApi from './api/user.api'
export * as notificationApi from './api/notification.api'

// 业务逻辑（推荐入口）
export {
  // auth
  performLogin,
  performMfaVerify,
  performRegister,
  performLogout,
  fetchCurrentUser,
  isAuthenticated,
  isMfaChallenge,
  getOAuthLoginUrl,
  getSsoLoginUrl,
} from './composables/use-auth'

export {
  // profile
  updateProfile,
  changePassword,
  fetchOAuthBindings,
  unbindOAuthAccount,
  fetchMfaDevices,
  setupTotp,
  confirmTotp,
  fetchRecoveryCodeStatus,
  generateRecoveryCodes,
  fetchSessions,
  revokeSession,
  revokeAllOtherSessions,
} from './composables/use-profile'

export {
  // notification
  fetchNotifications,
  fetchCategories,
  fetchUnreadCount,
  markNotificationRead,
  batchMarkRead,
  markAllRead,
  removeNotification,
  clearReadNotifications,
  fetchPreferences,
  updatePreference,
} from './composables/use-notification'

export {
  // tenant
  resolveTenant,
  fetchLoginConfig,
  fetchSiteConfig,
  getSyncSiteConfig,
  cacheSiteConfig,
} from './composables/use-tenant'
