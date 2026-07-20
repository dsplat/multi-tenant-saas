/**
 * 用户中心相关 API 函数
 */

import type {
  ApiResponse,
  ChangePasswordRequest,
  MfaDevice,
  OAuthBinding,
  RecoveryCodeStatus,
  UpdateProfileRequest,
  UserInfo,
  UserSession,
} from '../types'
import { AUTH_ENDPOINTS, MFA_ENDPOINTS } from './endpoints'
import { request } from './client'

/** 更新个人资料 */
export function updateProfile(data: UpdateProfileRequest): Promise<ApiResponse<UserInfo>> {
  return request(AUTH_ENDPOINTS.UPDATE_PROFILE, {
    method: 'PUT',
    body: data,
  })
}

/** 修改密码 */
export function changePassword(data: ChangePasswordRequest): Promise<ApiResponse> {
  return request(AUTH_ENDPOINTS.CHANGE_PASSWORD, {
    method: 'PUT',
    body: data,
  })
}

/** 获取已绑定的 OAuth 账号列表 */
export function getOAuthBindings(): Promise<ApiResponse<OAuthBinding[]>> {
  return request(AUTH_ENDPOINTS.OAUTH_BINDINGS)
}

/** 解绑 OAuth 账号 */
export function unbindOAuth(provider: string): Promise<ApiResponse> {
  return request(AUTH_ENDPOINTS.OAUTH_UNBIND(provider), {
    method: 'DELETE',
  })
}

// ========== MFA 管理 ==========

/** 设置 TOTP（获取 secret + qr code） */
export function setupTotp(): Promise<ApiResponse<{ secret: string; qr_code: string }>> {
  return request(MFA_ENDPOINTS.TOTP_SETUP, { method: 'POST' })
}

/** 确认 TOTP 绑定 */
export function confirmTotp(code: string): Promise<ApiResponse> {
  return request(MFA_ENDPOINTS.TOTP_CONFIRM, {
    method: 'POST',
    body: { code },
  })
}

/** 发送邮箱验证码（用于 MFA） */
export function sendEmailCode(): Promise<ApiResponse> {
  return request(MFA_ENDPOINTS.EMAIL_SEND, { method: 'POST' })
}

/** 发送短信验证码（用于 MFA） */
export function sendSmsCode(): Promise<ApiResponse> {
  return request(MFA_ENDPOINTS.SMS_SEND, { method: 'POST' })
}

/** 获取 MFA 设备列表 */
export function getMfaDevices(): Promise<ApiResponse<MfaDevice[]>> {
  return request(MFA_ENDPOINTS.DEVICES)
}

/** 删除 MFA 设备 */
export function deleteMfaDevice(deviceId: number): Promise<ApiResponse> {
  return request(MFA_ENDPOINTS.DEVICE(deviceId), { method: 'DELETE' })
}

/** 重命名 MFA 设备 */
export function renameMfaDevice(deviceId: number, name: string): Promise<ApiResponse> {
  return request(MFA_ENDPOINTS.DEVICE(deviceId), {
    method: 'PUT',
    body: { name },
  })
}

/** 设置主 MFA 设备 */
export function setPrimaryMfaDevice(deviceId: number): Promise<ApiResponse> {
  return request(MFA_ENDPOINTS.DEVICE_PRIMARY(deviceId), { method: 'POST' })
}

/** 生成恢复码 */
export function generateRecoveryCodes(): Promise<ApiResponse<{ codes: string[] }>> {
  return request(MFA_ENDPOINTS.RECOVERY_GENERATE, { method: 'POST' })
}

/** 获取恢复码状态 */
export function getRecoveryCodeStatus(): Promise<ApiResponse<RecoveryCodeStatus>> {
  return request(MFA_ENDPOINTS.RECOVERY_STATUS)
}

/** 获取活跃会话列表 */
export function getSessions(): Promise<ApiResponse<UserSession[]>> {
  return request(MFA_ENDPOINTS.SESSIONS)
}

/** 撤销指定会话 */
export function revokeSession(sessionId: number): Promise<ApiResponse> {
  return request(MFA_ENDPOINTS.SESSION_REVOKE(sessionId), { method: 'DELETE' })
}

/** 撤销所有其他会话 */
export function revokeAllSessions(): Promise<ApiResponse> {
  return request(MFA_ENDPOINTS.SESSIONS_REVOKE_ALL, { method: 'POST' })
}
