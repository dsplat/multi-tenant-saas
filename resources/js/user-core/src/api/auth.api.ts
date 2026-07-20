/**
 * 认证相关 API 函数
 */

import type {
  ApiResponse,
  AuthResponse,
  ForgotPasswordRequest,
  LoginRequest,
  LoginResponse,
  MfaVerifyRequest,
  RegisterRequest,
  ResendVerificationRequest,
  ResetPasswordRequest,
  UserInfo,
  VerifyEmailRequest,
} from '../types'
import { AUTH_ENDPOINTS } from './endpoints'
import { request } from './client'

/** 邮箱密码登录 */
export function login(data: LoginRequest): Promise<ApiResponse<LoginResponse>> {
  return request(AUTH_ENDPOINTS.LOGIN, {
    method: 'POST',
    body: data,
    skipAuth: true,
  })
}

/** 注册 */
export function register(data: RegisterRequest): Promise<ApiResponse<AuthResponse>> {
  return request(AUTH_ENDPOINTS.REGISTER, {
    method: 'POST',
    body: data,
    skipAuth: true,
  })
}

/** 忘记密码（发送重置邮件） */
export function forgotPassword(data: ForgotPasswordRequest): Promise<ApiResponse> {
  return request(AUTH_ENDPOINTS.FORGOT_PASSWORD, {
    method: 'POST',
    body: data,
    skipAuth: true,
  })
}

/** 重置密码 */
export function resetPassword(data: ResetPasswordRequest): Promise<ApiResponse> {
  return request(AUTH_ENDPOINTS.RESET_PASSWORD, {
    method: 'POST',
    body: data,
    skipAuth: true,
  })
}

/** 验证邮箱 */
export function verifyEmail(data: VerifyEmailRequest): Promise<ApiResponse> {
  return request(AUTH_ENDPOINTS.VERIFY_EMAIL, {
    method: 'POST',
    body: data,
    skipAuth: true,
  })
}

/** 重发验证邮件 */
export function resendVerification(data: ResendVerificationRequest): Promise<ApiResponse> {
  return request(AUTH_ENDPOINTS.RESEND_VERIFICATION, {
    method: 'POST',
    body: data,
    skipAuth: true,
  })
}

/** MFA 二次验证 */
export function mfaVerify(data: MfaVerifyRequest): Promise<ApiResponse<AuthResponse>> {
  return request(AUTH_ENDPOINTS.MFA_VERIFY, {
    method: 'POST',
    body: data,
    skipAuth: true,
  })
}

/** 获取当前用户信息 */
export function getMe(): Promise<ApiResponse<UserInfo>> {
  return request(AUTH_ENDPOINTS.ME)
}

/** 登出 */
export function logout(): Promise<ApiResponse> {
  return request(AUTH_ENDPOINTS.LOGOUT, { method: 'POST' })
}

/** 获取 OAuth 重定向 URL */
export function getOAuthRedirectUrl(provider: string, tenantId?: number): string {
  const config = '/api/v1'
  const params = tenantId ? `?tenant_id=${tenantId}` : ''
  return `${config}${AUTH_ENDPOINTS.OAUTH_REDIRECT(provider)}${params}`
}

/** 获取 SSO 重定向 URL */
export function getSsoRedirectUrl(provider: string): string {
  return `/api/v1${AUTH_ENDPOINTS.SSO_REDIRECT(provider)}`
}
