import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import axios from 'axios'
import { useUserStore } from '../stores/user'

vi.mock('axios', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
    defaults: { headers: { common: {} } },
  },
}))

const mockedAxios = axios as unknown as {
  get: ReturnType<typeof vi.fn>
  post: ReturnType<typeof vi.fn>
  defaults: { headers: { common: Record<string, string> } }
}

describe('admin user store', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    vi.clearAllMocks()
    mockedAxios.defaults.headers.common = {}
  })

  describe('权限判断', () => {
    it('super_admin 拥有任意权限', () => {
      const store = useUserStore()
      store.user = { user_id: '1', name: 'a', email: 'a@a.com', role: 'super_admin' }
      expect(store.hasPermission('anything.xyz')).toBe(true)
      expect(store.hasAnyPermission(['x', 'y'])).toBe(true)
    })

    it('普通角色按权限列表判断', () => {
      const store = useUserStore()
      store.user = { user_id: '1', name: 'a', email: 'a@a.com', role: 'operator' }
      store.permissions = ['tenant.view']
      expect(store.hasPermission('tenant.view')).toBe(true)
      expect(store.hasPermission('tenant.delete')).toBe(false)
      expect(store.hasAnyPermission(['tenant.delete', 'tenant.view'])).toBe(true)
      expect(store.hasAnyPermission(['tenant.delete'])).toBe(false)
    })
  })

  describe('token 管理', () => {
    it('setToken 持久化到 localStorage 并设置 axios 头', () => {
      const store = useUserStore()
      store.setToken('tok-123')
      expect(store.token).toBe('tok-123')
      expect(localStorage.getItem('admin_token')).toBe('tok-123')
      expect(mockedAxios.defaults.headers.common['Authorization']).toBe('Bearer tok-123')
      expect(store.isLoggedIn).toBe(true)
    })

    it('初始 token 从 localStorage 读取', () => {
      localStorage.setItem('admin_token', 'saved-tok')
      const store = useUserStore()
      expect(store.token).toBe('saved-tok')
    })
  })

  describe('login', () => {
    it('登录成功写入 token、用户与权限', async () => {
      mockedAxios.post.mockResolvedValue({
        data: { data: { operator: { name: 'op', permissions: ['a.b'] }, auth_token: 'tok-1' } },
      })
      const store = useUserStore()
      await store.login('a@a.com', 'pwd')
      expect(store.token).toBe('tok-1')
      expect(store.user?.name).toBe('op')
      expect(store.permissions).toEqual(['a.b'])
    })

    it('登录失败抛出异常且不写 token', async () => {
      mockedAxios.post.mockRejectedValue(new Error('401'))
      const store = useUserStore()
      await expect(store.login('a@a.com', 'bad')).rejects.toThrow()
      expect(store.token).toBeNull()
    })
  })

  describe('logout', () => {
    it('登出 API 失败也要清空本地会话', async () => {
      mockedAxios.post.mockRejectedValue(new Error('network'))
      const store = useUserStore()
      store.setToken('tok-x')
      store.user = { user_id: '1', name: 'a', email: 'a@a.com', role: 'operator' }
      await store.logout()
      expect(store.token).toBeNull()
      expect(store.user).toBeNull()
      expect(localStorage.getItem('admin_token')).toBeNull()
      expect(mockedAxios.defaults.headers.common['Authorization']).toBeUndefined()
    })
  })

  describe('init', () => {
    it('token 有效时拉取用户信息', async () => {
      localStorage.setItem('admin_token', 'tok-ok')
      mockedAxios.get.mockResolvedValue({
        data: { data: { user: { name: 'u1' }, permissions: ['p1'] } },
      })
      const store = useUserStore()
      await store.init()
      expect(store.user?.name).toBe('u1')
      expect(store.permissions).toEqual(['p1'])
    })

    it('token 失效时清空会话', async () => {
      localStorage.setItem('admin_token', 'tok-bad')
      mockedAxios.get.mockRejectedValue(new Error('401'))
      const store = useUserStore()
      await store.init()
      expect(store.token).toBeNull()
      expect(localStorage.getItem('admin_token')).toBeNull()
    })
  })
})
