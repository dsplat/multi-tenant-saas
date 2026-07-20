/**
 * 认证相关类型定义
 */

import type { UserInfo } from './user'

/** 登录请求 */
export interface LoginRequest {
  email: string
  password: string
}

/** 注册请求 */
export interface RegisterRequest {
  name: string
  email: string
  password: string
  password_confirmation: string
  phone?: string
}

/** 忘记密码请求 */
export interface ForgotPasswordRequest {
  email: string
}

/** 重置密码请求 */
export interface ResetPasswordRequest {
  token: string
  email: string
  password: string
  password_confirmation: string
}

/** 认证响应（登录成功） */
export interface AuthResponse {
  token: string
  user: UserInfo
}

/** MFA 挑战响应（需要二次验证） */
export interface MfaChallengeResponse {
  mfa_required: true
  user_id: number
  available_types: MfaType[]
}

/** 登录响应（可能是直接成功或需要 MFA） */
export type LoginResponse = AuthResponse | MfaChallengeResponse

/** MFA 验证请求 */
export interface MfaVerifyRequest {
  user_id: number
  type: MfaType
  code: string
}

/** MFA 类型 */
export type MfaType = 'totp' | 'email' | 'sms'

/** OAuth 提供商 */
export interface OAuthProvider {
  provider: string
  name: string
}

/** SSO 提供商 */
export interface SsoProvider {
  provider: string
  name: string
}

/** 邮箱验证请求 */
export interface VerifyEmailRequest {
  token: string
}

/** 重发验证邮件请求 */
export interface ResendVerificationRequest {
  email: string
}

/** 通用 API 响应包装 */
export interface ApiResponse<T = unknown> {
  success: boolean
  data?: T
  message?: string
}

/** 分页元数据 */
export interface PaginationMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}
