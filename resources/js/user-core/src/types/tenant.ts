/**
 * 租户相关类型定义
 */

import type { OAuthProvider, SsoProvider } from './auth'

/** 租户品牌信息 */
export interface TenantBrand {
  tenant_id: number
  name: string
  logo?: string
  domain?: string
  branding?: {
    login_page_message?: string
    primary_color?: string
    favicon?: string
  }
}

/** 登录配置（基于租户域名解析） */
export interface LoginConfig {
  oauth_providers: OAuthProvider[]
  sso_providers: SsoProvider[]
  allow_register: boolean
}

/** 站点配置（公开） */
export interface SiteConfig {
  platform_name: string
  registration_enabled?: boolean
  apply_enabled?: boolean
  logo?: string
  favicon?: string
}

/** 租户解析响应 */
export interface TenantResolveResponse {
  tenant_id: number
  name: string
  logo?: string
  domain?: string
  branding?: TenantBrand['branding']
}
