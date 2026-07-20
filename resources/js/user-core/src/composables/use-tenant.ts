/**
 * 租户上下文业务逻辑（框架无关）
 */

import type { LoginConfig, SiteConfig, TenantBrand } from '../types'
import { TENANT_ENDPOINTS } from '../api/endpoints'
import { request } from '../api/client'

/**
 * 根据域名解析租户
 */
export async function resolveTenant(domain?: string): Promise<{
  success: boolean
  tenant?: TenantBrand
  error?: string
}> {
  try {
    const host = domain || (typeof window !== 'undefined' ? window.location.host : '')
    const res = await request<{ success: boolean; data?: TenantBrand; message?: string }>(
      TENANT_ENDPOINTS.RESOLVE,
      { params: { domain: host }, skipAuth: true }
    )
    if (!res.success || !res.data) {
      return { success: false, error: res.message }
    }
    return { success: true, tenant: res.data }
  } catch (err: any) {
    return { success: false, error: err.message }
  }
}

/**
 * 获取租户登录配置（OAuth/SSO 提供商列表、是否允许注册）
 */
export async function fetchLoginConfig(domain?: string): Promise<{
  success: boolean
  config?: LoginConfig
  error?: string
}> {
  try {
    const host = domain || (typeof window !== 'undefined' ? window.location.host : '')
    const res = await request<{ success: boolean; data?: LoginConfig; message?: string }>(
      TENANT_ENDPOINTS.LOGIN_CONFIG,
      { params: { domain: host }, skipAuth: true }
    )
    if (!res.success || !res.data) {
      return { success: false, error: res.message }
    }
    return { success: true, config: res.data }
  } catch (err: any) {
    return { success: false, error: err.message }
  }
}

/**
 * 获取站点公开配置
 */
export async function fetchSiteConfig(): Promise<{
  success: boolean
  config?: SiteConfig
  error?: string
}> {
  try {
    const res = await request<{ success: boolean; data?: SiteConfig; message?: string }>(
      TENANT_ENDPOINTS.SITE_CONFIG,
      { skipAuth: true }
    )
    if (!res.success || !res.data) {
      return { success: false, error: res.message }
    }
    return { success: true, config: res.data }
  } catch (err: any) {
    return { success: false, error: err.message }
  }
}

/**
 * 从 window.__SITE_CONFIG__ 或 localStorage 同步读取站点配置
 * 用于首屏防闪烁
 */
export function getSyncSiteConfig(): Partial<SiteConfig> {
  if (typeof window === 'undefined') return {}

  // 优先读 window 注入
  const injected = (window as any).__SITE_CONFIG__
  if (injected) return injected

  // 其次读 localStorage 缓存
  try {
    const cached = localStorage.getItem('__site_config__')
    if (cached) return JSON.parse(cached)
  } catch {}

  return {}
}

/**
 * 缓存站点配置到 localStorage
 */
export function cacheSiteConfig(config: SiteConfig): void {
  if (typeof window === 'undefined') return
  try {
    localStorage.setItem('__site_config__', JSON.stringify(config))
  } catch {}
}
