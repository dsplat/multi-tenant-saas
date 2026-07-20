/**
 * 认证业务逻辑（框架无关）
 *
 * 封装登录/注册/OAuth/MFA 的完整业务流程，
 * 包括 token 管理和状态判断。
 */

import type {
  AuthResponse,
  LoginRequest,
  LoginResponse,
  MfaChallengeResponse,
  MfaType,
  MfaVerifyRequest,
  RegisterRequest,
  UserInfo,
} from '../types'
import * as authApi from '../api/auth.api'
import { getConfig } from '../api/client'

/** 判断登录响应是否为 MFA 挑战 */
export function isMfaChallenge(response: LoginResponse): response is MfaChallengeResponse {
  return 'mfa_required' in response && response.mfa_required === true
}

/**
 * 执行登录流程
 *
 * @returns 登录结果：成功时自动存储 token，MFA 时返回挑战信息
 */
export async function performLogin(data: LoginRequest): Promise<{
  success: boolean
  mfaRequired?: boolean
  mfaTypes?: MfaType[]
  userId?: number
  user?: UserInfo
  error?: string
}> {
  try {
    const res = await authApi.login(data)

    if (!res.success || !res.data) {
      return { success: false, error: res.message || '登录失败' }
    }

    const loginData = res.data as LoginResponse

    if (isMfaChallenge(loginData)) {
      return {
        success: true,
        mfaRequired: true,
        mfaTypes: loginData.available_types,
        userId: loginData.user_id,
      }
    }

    // 直接登录成功
    const authData = loginData as AuthResponse
    const config = getConfig()
    if (config.setToken) {
      config.setToken(authData.token)
    }

    return { success: true, user: authData.user }
  } catch (err: any) {
    return { success: false, error: err.message || '网络错误' }
  }
}

/**
 * 执行 MFA 验证
 */
export async function performMfaVerify(data: MfaVerifyRequest): Promise<{
  success: boolean
  user?: UserInfo
  error?: string
}> {
  try {
    const res = await authApi.mfaVerify(data)

    if (!res.success || !res.data) {
      return { success: false, error: res.message || '验证失败' }
    }

    const config = getConfig()
    if (config.setToken) {
      config.setToken(res.data.token)
    }

    return { success: true, user: res.data.user }
  } catch (err: any) {
    return { success: false, error: err.message || '网络错误' }
  }
}

/**
 * 执行注册流程
 */
export async function performRegister(data: RegisterRequest): Promise<{
  success: boolean
  user?: UserInfo
  error?: string
}> {
  try {
    const res = await authApi.register(data)

    if (!res.success || !res.data) {
      return { success: false, error: res.message || '注册失败' }
    }

    const config = getConfig()
    if (config.setToken) {
      config.setToken(res.data.token)
    }

    return { success: true, user: res.data.user }
  } catch (err: any) {
    return { success: false, error: err.message || '网络错误' }
  }
}

/**
 * 登出
 */
export async function performLogout(): Promise<void> {
  try {
    await authApi.logout()
  } finally {
    const config = getConfig()
    if (config.clearToken) {
      config.clearToken()
    }
  }
}

/**
 * 获取当前用户信息
 */
export async function fetchCurrentUser(): Promise<{
  success: boolean
  user?: UserInfo
  error?: string
}> {
  try {
    const res = await authApi.getMe()
    if (!res.success || !res.data) {
      return { success: false, error: res.message }
    }
    return { success: true, user: res.data }
  } catch (err: any) {
    return { success: false, error: err.message }
  }
}

/**
 * 检查是否已登录（有 token）
 */
export function isAuthenticated(): boolean {
  const config = getConfig()
  return !!(config.getToken && config.getToken())
}

/**
 * 获取 OAuth 登录跳转 URL
 */
export function getOAuthLoginUrl(provider: string, tenantId?: number): string {
  return authApi.getOAuthRedirectUrl(provider, tenantId)
}

/**
 * 获取 SSO 登录跳转 URL
 */
export function getSsoLoginUrl(provider: string): string {
  return authApi.getSsoRedirectUrl(provider)
}
