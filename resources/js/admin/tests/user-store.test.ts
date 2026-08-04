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
  defaults: { headers: { common: Record<string, string> }; withCredentials?: boolean }
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

  describe('Cookie 会话模式', () => {
    it('axios 启用凭证携带，且不管理 Authorization 头', () => {
      useUserStore()
      expect(mockedAxios.defaults.withCredentials).toBe(true)
      expect(mockedAxios.defaults.headers.common['Authorization']).toBeUndefined()
    })

    it('isLoggedIn 以用户探测结果为准，不再依赖本地 token', () => {
      const store = useUserStore()
      expect(store.isLoggedIn).toBe(false)
      store.user = { user_id: '1', name: 'a', email: 'a@a.com', role: 'operator' }
      expect(store.isLoggedIn).toBe(true)
    })

    it('不读写 admin_token localStorage', async () => {
      mockedAxios.post.mockResolvedValue({
        data: { data: { operator: { name: 'op', permissions: [] }, auth_token: 'tok-1' } },
      })
      const store = useUserStore()
      await store.login('a@a.com', 'pwd')
      expect(localStorage.getItem('admin_token')).toBeNull()
    })
  })

  describe('login', () => {
    it('登录成功写入用户与权限', async () => {
      mockedAxios.post.mockResolvedValue({
        data: { data: { operator: { name: 'op', permissions: ['a.b'] }, auth_token: 'tok-1' } },
      })
      const store = useUserStore()
      await store.login('a@a.com', 'pwd')
      expect(store.user?.name).toBe('op')
      expect(store.permissions).toEqual(['a.b'])
      expect(store.isLoggedIn).toBe(true)
    })

    it('登录失败抛出异常且不写用户', async () => {
      mockedAxios.post.mockRejectedValue(new Error('401'))
      const store = useUserStore()
      await expect(store.login('a@a.com', 'bad')).rejects.toThrow()
      expect(store.user).toBeNull()
      expect(store.isLoggedIn).toBe(false)
    })
  })

  describe('logout', () => {
    it('登出 API 失败也要清空本地会话', async () => {
      mockedAxios.post.mockRejectedValue(new Error('network'))
      const store = useUserStore()
      store.user = { user_id: '1', name: 'a', email: 'a@a.com', role: 'operator' }
      await store.logout()
      expect(store.user).toBeNull()
      expect(store.permissions).toEqual([])
      expect(store.isLoggedIn).toBe(false)
    })
  })

  describe('init', () => {
    it('先请求 csrf-cookie 再探测会话', async () => {
      mockedAxios.get.mockImplementation(async (url: string) => {
        if (url.includes('csrf-cookie')) return { status: 204 }
        return { data: { data: { user: { name: 'u1' }, permissions: ['p1'] } } }
      })
      const store = useUserStore()
      await store.init()
      const calls = mockedAxios.get.mock.calls.map(c => c[0])
      expect(calls[0]).toBe('/api/v1/admin/auth/csrf-cookie')
      expect(calls[1]).toBe('/api/v1/admin/auth/user')
      expect(store.user?.name).toBe('u1')
      expect(store.permissions).toEqual(['p1'])
    })

    it('会话探测失败时保持未登录且不抛出', async () => {
      mockedAxios.get.mockImplementation(async (url: string) => {
        if (url.includes('csrf-cookie')) return { status: 204 }
        throw new Error('401')
      })
      const store = useUserStore()
      await expect(store.init()).resolves.toBeUndefined()
      expect(store.user).toBeNull()
      expect(store.isLoggedIn).toBe(false)
    })
  })
})
