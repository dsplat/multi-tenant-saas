import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

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
  const token = ref<string | null>(localStorage.getItem('console_token'))
  const user = ref<User | null>(null)
  const permissions = ref<string[]>([])

  const isLoggedIn = computed(() => !!token.value)
  const tenantId = computed(() => user.value?.tenant_id || localStorage.getItem('console_tenant_id') || '')

  const hasPermission = (perm: string): boolean => {
    if (['super_admin', 'tenant_admin', 'platform_admin'].includes(user.value?.role || '')) return true
    return permissions.value.includes(perm)
  }

  const setToken = (newToken: string) => {
    token.value = newToken
    localStorage.setItem('console_token', newToken)
    axios.defaults.headers.common['Authorization'] = `Bearer ${newToken}`
  }

  const fetchUser = async () => {
    try {
      const response = await axios.get('/api/v1/console/auth/user')
      const data = response.data.data
      user.value = data.user || data
      permissions.value = data.permissions || []
      if (data.tenant_id) localStorage.setItem('console_tenant_id', String(data.tenant_id))
    } catch (error) {
      console.error('获取用户信息失败:', error)
      throw error
    }
  }

  const login = async (email: string, password: string, tenantId?: string) => {
    try {
      const tid = tenantId || localStorage.getItem('console_tenant_id') || new URLSearchParams(window.location.search).get('tenant_id') || ''
      const headers: Record<string, string> = {}
      if (tid) headers['X-Tenant-ID'] = tid
      const response = await axios.post('/api/v1/console/auth/login', { email, password }, { headers })
      const data = response.data.data

      // MFA required — return early without setting token
      if (data.mfa_required) {
        return response.data
      }

      // Operator login (new flow)
      if (data.operator) {
        const { operator, tenants, no_tenant } = data
        if (data.auth_token) setToken(data.auth_token)
        user.value = { ...operator, tenants }
        permissions.value = operator.permissions || []
        if (no_tenant || !tenants?.length) {
          // No tenant — will be redirected to /console/apply
          if (user.value) user.value.tenant_id = undefined
          localStorage.removeItem('console_tenant_id')
          delete axios.defaults.headers.common['X-Tenant-ID']
          return { ...response.data, no_tenant: true }
        }
        // Use first tenant
        const firstTenant = tenants[0]
        const effectiveTenantId = firstTenant.tenant_id
        if (user.value) user.value.tenant_id = String(effectiveTenantId)
        localStorage.setItem('console_tenant_id', String(effectiveTenantId))
        axios.defaults.headers.common['X-Tenant-ID'] = String(effectiveTenantId)
        return response.data
      }

      // Legacy User login
      const { user: userData, auth_token, tenant_id } = data
      setToken(auth_token)
      userData.tenant_id = tenant_id
      user.value = userData
      permissions.value = userData.permissions || []
      if (tenant_id) {
        localStorage.setItem('console_tenant_id', String(tenant_id))
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
      token.value = null; user.value = null; permissions.value = []
      localStorage.removeItem('console_token'); localStorage.removeItem('console_tenant_id')
      delete axios.defaults.headers.common['Authorization']
      delete axios.defaults.headers.common['X-Tenant-ID']
    }
  }

  const switchTenant = async (tenantId: number) => {
    // Update headers and storage
    axios.defaults.headers.common['X-Tenant-ID'] = String(tenantId)
    localStorage.setItem('console_tenant_id', String(tenantId))
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
    if (token.value) {
      axios.defaults.headers.common['Authorization'] = `Bearer ${token.value}`
      const storedTid = localStorage.getItem('console_tenant_id')
      if (storedTid) axios.defaults.headers.common['X-Tenant-ID'] = storedTid
      try { await fetchUser() } catch {
        token.value = null; user.value = null; permissions.value = []
        localStorage.removeItem('console_token')
        delete axios.defaults.headers.common['Authorization']
      }
    }
  }

  return { token, user, permissions, isLoggedIn, tenantId, hasPermission, setToken, fetchUser, login, logout, switchTenant, init }
})
