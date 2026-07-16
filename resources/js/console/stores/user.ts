import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

interface User {
  user_id: string
  name: string
  email: string
  role: string
  avatar?: string
  tenant_id?: string
}

export const useUserStore = defineStore('user', () => {
  const token = ref<string | null>(localStorage.getItem('admin_token'))
  const user = ref<User | null>(null)
  
  const isLoggedIn = computed(() => !!token.value)
  const isSuperAdmin = computed(() => user.value?.role === 'super_admin')
  const tenantId = computed(() => user.value?.tenant_id || localStorage.getItem('console_tenant_id') || '')
  
  // 设置 token
  const setToken = (newToken: string) => {
    token.value = newToken
    localStorage.setItem('admin_token', newToken)
    axios.defaults.headers.common['Authorization'] = `Bearer ${newToken}`
  }
  
  // 获取用户信息
  const fetchUser = async () => {
    try {
      const response = await axios.get('/api/v1/console/auth/user')
      user.value = response.data.data
    } catch (error) {
      console.error('获取用户信息失败:', error)
      throw error
    }
  }

  // 登录
  const login = async (email: string, password: string) => {
    try {
      const response = await axios.post('/api/v1/console/auth/login', {
        email,
        password,
      })

      const { user: userData, auth_token, tenant_id } = response.data.data
      setToken(auth_token)
      userData.tenant_id = tenant_id
      user.value = userData
      if (tenant_id) localStorage.setItem('console_tenant_id', String(tenant_id))

      return response.data
    } catch (error) {
      console.error('登录失败:', error)
      throw error
    }
  }

  // 登出
  const logout = async () => {
    try {
      await axios.post('/api/v1/console/auth/logout')
    } catch (error) {
      console.error('登出失败:', error)
    } finally {
      token.value = null
      user.value = null
      localStorage.removeItem('admin_token')
      delete axios.defaults.headers.common['Authorization']
    }
  }
  
  // 初始化
  const init = async () => {
    if (token.value) {
      axios.defaults.headers.common['Authorization'] = `Bearer ${token.value}`
      try {
        await fetchUser()
      } catch (error) {
        // token 无效，清除
        token.value = null
        user.value = null
        localStorage.removeItem('admin_token')
        delete axios.defaults.headers.common['Authorization']
      }
    }
  }
  
  return {
    token,
    user,
    isLoggedIn,
    isSuperAdmin,
    tenantId,
    setToken,
    fetchUser,
    login,
    logout,
    init,
  }
})
