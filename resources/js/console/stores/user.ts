import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

// 双模认证：SPA 走 Cookie 会话（Sanctum stateful），不在前端存储/携带 Bearer token
axios.defaults.withCredentials = true

interface User {
  user_id?: string
  operator_id?: string
  name: string
  email: string
  role?: string
  scope?: string
  avatar?: string
  tenant_id?: string
  tenants?: Array<{ tenant_id: number; name: string; role: string }>
}

export const useUserStore = defineStore('user', () => {
  const user = ref<User | null>(null)
  const permissions = ref<string[]>([])

  // 登录态以 /auth/user 探测结果（会话）为准，不再依赖本地 token
  const isLoggedIn = computed(() => !!user.value)
  const tenantId = computed(() => user.value?.tenant_id || localStorage.getItem('auth_tenant_id') || '')

  const hasPermission = (perm: string): boolean => {
    if (['super_admin', 'tenant_admin', 'platform_admin'].includes(user.value?.role || '')) return true
    return permissions.value.includes(perm)
  }

  const fetchUser = async () => {
    try {
      const response = await axios.get('/api/v1/console/auth/user')
      const data = response.data.data
      user.value = data.user || data
      permissions.value = data.permissions || []
      if (data.tenant_id) localStorage.setItem('auth_tenant_id', String(data.tenant_id))
    } catch (error) {
      user.value = null
      permissions.value = []
      console.error('获取用户信息失败:', error)
      throw error
    }
  }

  const login = async (email: string, password: string, tenantId?: string) => {
    try {
      const tid = tenantId || localStorage.getItem('auth_tenant_id') || new URLSearchParams(window.location.search).get('tenant_id') || ''
      const headers: Record<string, string> = {}
      if (tid) headers['X-Tenant-ID'] = tid
      const response = await axios.post('/api/v1/console/auth/login', { email, password }, { headers })
      const data = response.data.data

      // MFA required — return early（会话由 mfa-verify 完成验证后建立）
      if (data.mfa_required) {
        return response.data
      }

      // Operator login (new flow)
      if (data.operator) {
        const { operator, tenants, no_tenant } = data
        user.value = { ...operator, tenants }
        permissions.value = operator.permissions || []
        if (no_tenant || !tenants?.length) {
          // No tenant — will be redirected to /console/apply
          if (user.value) user.value.tenant_id = undefined
          localStorage.removeItem('auth_tenant_id')
          delete axios.defaults.headers.common['X-Tenant-ID']
          return { ...response.data, no_tenant: true }
        }
        // Use first tenant
        const firstTenant = tenants[0]
        const effectiveTenantId = firstTenant.tenant_id
        if (user.value) user.value.tenant_id = String(effectiveTenantId)
        localStorage.setItem('auth_tenant_id', String(effectiveTenantId))
        axios.defaults.headers.common['X-Tenant-ID'] = String(effectiveTenantId)
        return response.data
      }

      // Legacy User login
      const { user: userData, tenant_id } = data
      userData.tenant_id = tenant_id != null ? String(tenant_id) : tenant_id
      user.value = userData
      permissions.value = userData.permissions || []
      if (tenant_id) {
        localStorage.setItem('auth_tenant_id', String(tenant_id))
        axios.defaults.headers.common['X-Tenant-ID'] = String(tenant_id)
      }
      return response.data
    } catch (error) {
      console.error('登录失败:', error)
      throw error
    }
  }

  const logout = async () => {
    try { await axios.post('/api/v1/console/auth/logout') } catch {}
    finally {
      user.value = null; permissions.value = []
      localStorage.removeItem('auth_tenant_id')
      delete axios.defaults.headers.common['X-Tenant-ID']
    }
  }

  const switchTenant = async (tenantId: number) => {
    // Update headers and storage
    axios.defaults.headers.common['X-Tenant-ID'] = String(tenantId)
    localStorage.setItem('auth_tenant_id', String(tenantId))
    if (user.value) user.value.tenant_id = String(tenantId)

    // Reload user info for the new tenant context
    try {
      await fetchUser()
      // Trigger a full page reload to ensure all data is refreshed
      window.location.reload()
    } catch (error) {
      console.error('切换团队失败:', error)
      throw error
    }
  }

  const init = async () => {
    // 先取 XSRF-TOKEN Cookie（Sanctum stateful 写请求需要）
    try { await axios.get('/api/v1/console/auth/csrf-cookie') } catch { /* 忽略：端点不可达时降级为未登录 */ }

    // 恢复租户上下文头（非凭证，仅路由租户用）
    const storedTid = localStorage.getItem('auth_tenant_id')
    if (storedTid) axios.defaults.headers.common['X-Tenant-ID'] = storedTid

    try {
      await fetchUser()
    } catch (error: any) {
      const status = error?.response?.status
      if (status === 403) {
        // 403 多为残留的过期 X-Tenant-ID 与当前域名租户冲突：清残留租户头重试
        localStorage.removeItem('auth_tenant_id')
        delete axios.defaults.headers.common['X-Tenant-ID']
        try { await fetchUser(); return } catch { /* 仍失败则按未登录处理 */ }
      }
      // 未登录/会话失效/瞬时错误：user 保持 null，由路由守卫兜底跳登录页
    }
  }

  return { user, permissions, isLoggedIn, tenantId, hasPermission, fetchUser, login, logout, switchTenant, init }
})
