import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

interface User {
  user_id: string
  name: string
  email: string
  role: string
  avatar?: string
  permissions?: string[]
}

export const useUserStore = defineStore('user', () => {
  const token = ref<string | null>(localStorage.getItem('admin_token'))
  const user = ref<User | null>(null)
  const permissions = ref<string[]>([])

  const isLoggedIn = computed(() => !!token.value)
  const isSuperAdmin = computed(() => user.value?.role === 'super_admin')

  const hasPermission = (perm: string): boolean => {
    if (isSuperAdmin.value) return true
    return permissions.value.includes(perm)
  }

  const hasAnyPermission = (perms: string[]): boolean => {
    if (isSuperAdmin.value) return true
    return perms.some(p => permissions.value.includes(p))
  }

  const setToken = (newToken: string) => {
    token.value = newToken
    localStorage.setItem('admin_token', newToken)
    axios.defaults.headers.common['Authorization'] = `Bearer ${newToken}`
  }

  const fetchUser = async () => {
    try {
      const response = await axios.get('/api/v1/admin/auth/user')
      const data = response.data.data
      user.value = data.user || data
      permissions.value = data.permissions || []
    } catch (error) {
      console.error('获取用户信息失败:', error)
      throw error
    }
  }

  const login = async (email: string, password: string) => {
    try {
      const response = await axios.post('/api/v1/admin/auth/login', { email, password })
      const { user: userData, auth_token } = response.data.data
      setToken(auth_token)
      user.value = userData
      permissions.value = userData.permissions || []
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
      token.value = null
      user.value = null
      permissions.value = []
      localStorage.removeItem('admin_token')
      delete axios.defaults.headers.common['Authorization']
    }
  }

  const init = async () => {
    if (token.value) {
      axios.defaults.headers.common['Authorization'] = `Bearer ${token.value}`
      try {
        await fetchUser()
      } catch (error) {
        token.value = null
        user.value = null
        permissions.value = []
        localStorage.removeItem('admin_token')
        delete axios.defaults.headers.common['Authorization']
      }
    }
  }

  return {
    token,
    user,
    permissions,
    isLoggedIn,
    isSuperAdmin,
    hasPermission,
    hasAnyPermission,
    setToken,
    fetchUser,
    login,
    logout,
    init,
  }
})
