/**
 * 用户资料管理业务逻辑（框架无关）
 */

import type {
  ChangePasswordRequest,
  MfaDevice,
  OAuthBinding,
  RecoveryCodeStatus,
  UpdateProfileRequest,
  UserInfo,
  UserSession,
} from '../types'
import * as userApi from '../api/user.api'

/**
 * 更新个人资料
 */
export async function updateProfile(data: UpdateProfileRequest): Promise<{
  success: boolean
  user?: UserInfo
  error?: string
}> {
  try {
    const res = await userApi.updateProfile(data)
    if (!res.success) {
      return { success: false, error: res.message || '更新失败' }
    }
    return { success: true, user: res.data }
  } catch (err: any) {
    return { success: false, error: err.message || '网络错误' }
  }
}

/**
 * 修改密码
 */
export async function changePassword(data: ChangePasswordRequest): Promise<{
  success: boolean
  error?: string
}> {
  try {
    const res = await userApi.changePassword(data)
    if (!res.success) {
      return { success: false, error: res.message || '密码修改失败' }
    }
    return { success: true }
  } catch (err: any) {
    return { success: false, error: err.message || '网络错误' }
  }
}

/**
 * 获取 OAuth 绑定列表
 */
export async function fetchOAuthBindings(): Promise<{
  success: boolean
  bindings?: OAuthBinding[]
  error?: string
}> {
  try {
    const res = await userApi.getOAuthBindings()
    if (!res.success) {
      return { success: false, error: res.message }
    }
    return { success: true, bindings: res.data || [] }
  } catch (err: any) {
    return { success: false, error: err.message }
  }
}

/**
 * 解绑 OAuth 账号
 */
export async function unbindOAuthAccount(provider: string): Promise<{
  success: boolean
  error?: string
}> {
  try {
    const res = await userApi.unbindOAuth(provider)
    if (!res.success) {
      return { success: false, error: res.message || '解绑失败' }
    }
    return { success: true }
  } catch (err: any) {
    return { success: false, error: err.message || '网络错误' }
  }
}

// ========== MFA 管理 ==========

/**
 * 获取 MFA 设备列表
 */
export async function fetchMfaDevices(): Promise<{
  success: boolean
  devices?: MfaDevice[]
  error?: string
}> {
  try {
    const res = await userApi.getMfaDevices()
    if (!res.success) {
      return { success: false, error: res.message }
    }
    return { success: true, devices: res.data || [] }
  } catch (err: any) {
    return { success: false, error: err.message }
  }
}

/**
 * 设置 TOTP
 */
export async function setupTotp(): Promise<{
  success: boolean
  secret?: string
  qrCode?: string
  error?: string
}> {
  try {
    const res = await userApi.setupTotp()
    if (!res.success || !res.data) {
      return { success: false, error: res.message }
    }
    return { success: true, secret: res.data.secret, qrCode: res.data.qr_code }
  } catch (err: any) {
    return { success: false, error: err.message }
  }
}

/**
 * 确认 TOTP 绑定
 */
export async function confirmTotp(code: string): Promise<{
  success: boolean
  error?: string
}> {
  try {
    const res = await userApi.confirmTotp(code)
    if (!res.success) {
      return { success: false, error: res.message || '验证码错误' }
    }
    return { success: true }
  } catch (err: any) {
    return { success: false, error: err.message }
  }
}

/**
 * 获取恢复码状态
 */
export async function fetchRecoveryCodeStatus(): Promise<{
  success: boolean
  status?: RecoveryCodeStatus
  error?: string
}> {
  try {
    const res = await userApi.getRecoveryCodeStatus()
    if (!res.success) {
      return { success: false, error: res.message }
    }
    return { success: true, status: res.data }
  } catch (err: any) {
    return { success: false, error: err.message }
  }
}

/**
 * 生成新的恢复码
 */
export async function generateRecoveryCodes(): Promise<{
  success: boolean
  codes?: string[]
  error?: string
}> {
  try {
    const res = await userApi.generateRecoveryCodes()
    if (!res.success || !res.data) {
      return { success: false, error: res.message }
    }
    return { success: true, codes: res.data.codes }
  } catch (err: any) {
    return { success: false, error: err.message }
  }
}

// ========== 会话管理 ==========

/**
 * 获取活跃会话列表
 */
export async function fetchSessions(): Promise<{
  success: boolean
  sessions?: UserSession[]
  error?: string
}> {
  try {
    const res = await userApi.getSessions()
    if (!res.success) {
      return { success: false, error: res.message }
    }
    return { success: true, sessions: res.data || [] }
  } catch (err: any) {
    return { success: false, error: err.message }
  }
}

/**
 * 撤销指定会话
 */
export async function revokeSession(sessionId: number): Promise<{
  success: boolean
  error?: string
}> {
  try {
    const res = await userApi.revokeSession(sessionId)
    if (!res.success) {
      return { success: false, error: res.message }
    }
    return { success: true }
  } catch (err: any) {
    return { success: false, error: err.message }
  }
}

/**
 * 撤销所有其他会话
 */
export async function revokeAllOtherSessions(): Promise<{
  success: boolean
  error?: string
}> {
  try {
    const res = await userApi.revokeAllSessions()
    if (!res.success) {
      return { success: false, error: res.message }
    }
    return { success: true }
  } catch (err: any) {
    return { success: false, error: err.message }
  }
}
