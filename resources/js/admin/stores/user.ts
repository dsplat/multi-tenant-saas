import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

// 双模认证：SPA 走 Cookie 会话（Sanctum stateful），不在前端存储/携带 Bearer token
axios.defaults.withCredentials = true

interface User {
  user_id: string
  name: string
  email: string
  role: string
  avatar?: string
  permissions?: string[]
}

export const useUserStore = defineStore('user', () => {
  const user = ref<User | null>(null)
  const permissions = ref<string[]>([])

  // 登录态以 /auth/user 探测结果（会话）为准，不再依赖本地 token
  const isLoggedIn = computed(() => !!user.value)
  const isSuperAdmin = computed(() => user.value?.role === 'super_admin')

  const hasPermission = (perm: string): boolean => {
    if (isSuperAdmin.value) return true
    return permissions.value.includes(perm)
  }

  const hasAnyPermission = (perms: string[]): boolean => {
    if (isSuperAdmin.value) return true
    return perms.some(p => permissions.value.includes(p))
  }

  const fetchUser = async () => {
    try {
      const response = await axios.get('/api/v1/admin/auth/user')
      const data = response.data.data
      user.value = data.user || data
      permissions.value = data.permissions || []
    } catch (error) {
      user.value = null
      permissions.value = []
      console.error('获取用户信息失败:', error)
      throw error
    }
  }

  const login = async (email: string, password: string) => {
    try {
      const response = await axios.post('/api/v1/admin/auth/login', { email, password })
      const { operator, user: userData } = response.data.data
      const userInfo = operator || userData
      user.value = userInfo
      permissions.value = userInfo?.permissions || []
      return response.data
    } catch (error) {
      console.error('登录失败:', error)
      throw error
    }
  }

  const logout = async () => {
    try {
      await axios.post('/api/v1/admin/auth/logout')
    } catch (error) {
      console.error('登出失败:', error)
    } finally {
      user.value = null
      permissions.value = []
    }
  }

  const init = async () => {
    // 先取 XSRF-TOKEN Cookie（Sanctum stateful 写请求需要），再探测会话状态
    try { await axios.get('/api/v1/admin/auth/csrf-cookie') } catch { /* 忽略：端点不可达时降级为未登录 */ }
    try {
      await fetchUser()
    } catch {
      // 未登录或瞬时错误：user 保持 null，由路由守卫兜底跳登录页
    }
  }

  return {
    user,
    permissions,
    isLoggedIn,
    isSuperAdmin,
    hasPermission,
    hasAnyPermission,
    fetchUser,
    login,
    logout,
    init,
  }
})
