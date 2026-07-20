/**
 * 用户相关类型定义
 */

/** 用户基本信息 */
export interface UserInfo {
  user_id: number
  name: string
  email: string
  phone?: string
  avatar?: string
  email_verified_at?: string | null
  phone_verified_at?: string | null
  is_active?: boolean
  last_active_at?: string | null
  created_at?: string
}

/** OAuth 绑定账号 */
export interface OAuthBinding {
  id: number
  provider: string
  provider_user_id: string
  nickname?: string
  avatar?: string
  created_at: string
}

/** MFA 设备 */
export interface MfaDevice {
  id: number
  type: 'totp' | 'email' | 'sms'
  name: string
  is_primary: boolean
  last_used_at?: string | null
  created_at: string
}

/** MFA 恢复码状态 */
export interface RecoveryCodeStatus {
  total: number
  remaining: number
  generated_at?: string | null
}

/** 用户会话 */
export interface UserSession {
  id: number
  ip_address?: string
  user_agent?: string
  last_activity: string
  is_current: boolean
}

/** 更新个人资料请求 */
export interface UpdateProfileRequest {
  name?: string
  phone?: string
  avatar?: string
}

/** 修改密码请求 */
export interface ChangePasswordRequest {
  current_password: string
  password: string
  password_confirmation: string
}
