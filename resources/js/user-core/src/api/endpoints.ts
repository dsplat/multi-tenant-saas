/**
 * API 端点常量
 *
 * 所有路径相对于 baseURL（默认 /api/v1）
 */

export const AUTH_ENDPOINTS = {
  // 公开端点（无需认证）
  LOGIN: '/auth/login',
  REGISTER: '/auth/register',
  FORGOT_PASSWORD: '/auth/forgot-password',
  RESET_PASSWORD: '/auth/reset-password',
  VERIFY_EMAIL: '/auth/verify-email',
  RESEND_VERIFICATION: '/auth/resend-verification',
  MFA_VERIFY: '/auth/mfa/verify',

  // OAuth
  OAUTH_REDIRECT: (provider: string) => `/auth/${provider}/redirect`,
  OAUTH_CALLBACK: (provider: string) => `/auth/${provider}/callback`,

  // SSO
  SSO_REDIRECT: (provider: string) => `/auth/sso/${provider}/redirect`,
  SSO_CALLBACK: (provider: string) => `/auth/sso/${provider}/callback`,

  // 需认证端点
  ME: '/auth/me',
  LOGOUT: '/auth/logout',
  UPDATE_PROFILE: '/auth/profile',
  CHANGE_PASSWORD: '/auth/password',
  OAUTH_BINDINGS: '/auth/oauth-bindings',
  OAUTH_UNBIND: (provider: string) => `/auth/oauth-bindings/${provider}`,
} as const

export const MFA_ENDPOINTS = {
  TOTP_SETUP: '/mfa/totp/setup',
  TOTP_CONFIRM: '/mfa/totp/confirm',
  EMAIL_SEND: '/mfa/email/send',
  SMS_SEND: '/mfa/sms/send',
  DEVICES: '/mfa/devices',
  DEVICE: (id: number) => `/mfa/devices/${id}`,
  DEVICE_PRIMARY: (id: number) => `/mfa/devices/${id}/primary`,
  RECOVERY_GENERATE: '/mfa/recovery-codes/generate',
  RECOVERY_STATUS: '/mfa/recovery-codes/status',
  SESSIONS: '/mfa/sessions',
  SESSION_REVOKE: (id: number) => `/mfa/sessions/${id}`,
  SESSIONS_REVOKE_ALL: '/mfa/sessions/revoke-all',
} as const

export const NOTIFICATION_ENDPOINTS = {
  LIST: '/in-app-notifications',
  CATEGORIES: '/in-app-notifications/categories',
  UNREAD_COUNT: '/in-app-notifications/unread-count',
  MARK_READ: (id: number) => `/in-app-notifications/${id}/read`,
  BATCH_READ: '/in-app-notifications/read/batch',
  READ_ALL: '/in-app-notifications/read-all',
  DELETE: (id: number) => `/in-app-notifications/${id}`,
  CLEAR_READ: '/in-app-notifications/read/clear',
  PREFERENCES: '/in-app-notifications/preferences',
  PREFERENCES_BATCH: '/in-app-notifications/preferences/batch',
} as const

export const TENANT_ENDPOINTS = {
  RESOLVE: '/tenant/resolve',
  LOGIN_CONFIG: '/tenant/login-config',
  SITE_CONFIG: '/public/site-config',
} as const
