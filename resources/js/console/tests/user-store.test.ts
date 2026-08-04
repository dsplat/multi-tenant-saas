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

const httpError = (status: number) => Object.assign(new Error(`http ${status}`), { response: { status } })

describe('console user store', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
    vi.clearAllMocks()
    mockedAxios.defaults.headers.common = {}
  })

  describe('权限判断', () => {
    it('管理类角色（tenant_admin 等）拥有任意权限', () => {
      const store = useUserStore()
      for (const role of ['super_admin', 'tenant_admin', 'platform_admin']) {
        store.user = { name: 'a', email: 'a@a.com', role }
        expect(store.hasPermission('whatever')).toBe(true)
      }
    })

    it('普通角色按权限列表判断', () => {
      const store = useUserStore()
      store.user = { name: 'a', email: 'a@a.com', role: 'member' }
      store.permissions = ['customer.view']
      expect(store.hasPermission('customer.view')).toBe(true)
      expect(store.hasPermission('customer.delete')).toBe(false)
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
      store.user = { name: 'a', email: 'a@a.com' }
      expect(store.isLoggedIn).toBe(true)
    })
  })

  describe('login', () => {
    it('MFA 场景提前返回且不写租户上下文', async () => {
      mockedAxios.post.mockResolvedValue({ data: { data: { mfa_required: true } } })
      const store = useUserStore()
      const res = await store.login('a@a.com', 'pwd')
      expect(res.data.mfa_required).toBe(true)
      expect(store.user).toBeNull()
    })

    it('Operator 登录选择第一个租户并写入 X-Tenant-ID（不存 token）', async () => {
      mockedAxios.post.mockResolvedValue({
        data: {
          data: {
            operator: { operator_id: '1', name: 'op', email: 'a@a.com', permissions: ['p1'] },
            tenants: [
              { tenant_id: 11, name: 't1', role: 'tenant_admin' },
              { tenant_id: 22, name: 't2', role: 'member' },
            ],
            auth_token: 'tok-op',
          },
        },
      })
      const store = useUserStore()
      await store.login('a@a.com', 'pwd')
      expect(store.user?.tenant_id).toBe('11')
      expect(store.tenantId).toBe('11')
      expect(localStorage.getItem('auth_tenant_id')).toBe('11')
      expect(localStorage.getItem('auth_token')).toBeNull()
      expect(mockedAxios.defaults.headers.common['X-Tenant-ID']).toBe('11')
      expect(store.permissions).toEqual(['p1'])
    })

    it('Operator 无租户时返回 no_tenant 并清除租户上下文', async () => {
      localStorage.setItem('auth_tenant_id', '99')
      mockedAxios.post.mockResolvedValue({
        data: {
          data: {
            operator: { operator_id: '1', name: 'op', email: 'a@a.com' },
            tenants: [],
            no_tenant: true,
            auth_token: 'tok-nt',
          },
        },
      })
      const store = useUserStore()
      const res = await store.login('a@a.com', 'pwd')
      expect(res.no_tenant).toBe(true)
      expect(store.user?.tenant_id).toBeUndefined()
      expect(localStorage.getItem('auth_tenant_id')).toBeNull()
    })

    it('Legacy User 登录写入 tenant_id（不存 token）', async () => {
      mockedAxios.post.mockResolvedValue({
        data: {
          data: {
            user: { user_id: '5', name: 'u', email: 'u@u.com', permissions: [] },
            auth_token: 'tok-legacy',
            tenant_id: 33,
          },
        },
      })
      const store = useUserStore()
      await store.login('u@u.com', 'pwd')
      expect(store.tenantId).toBe('33')
      expect(localStorage.getItem('auth_token')).toBeNull()
      expect(mockedAxios.defaults.headers.common['X-Tenant-ID']).toBe('33')
    })
  })

  describe('logout', () => {
    it('清空用户与租户上下文', async () => {
      mockedAxios.post.mockRejectedValue(new Error('network'))
      const store = useUserStore()
      store.user = { name: 'a', email: 'a@a.com' }
      localStorage.setItem('auth_tenant_id', '11')
      mockedAxios.defaults.headers.common['X-Tenant-ID'] = '11'
      await store.logout()
      expect(store.user).toBeNull()
      expect(localStorage.getItem('auth_tenant_id')).toBeNull()
      expect(mockedAxios.defaults.headers.common['X-Tenant-ID']).toBeUndefined()
    })
  })

  describe('init 容错', () => {
    it('403 时清残留租户头重试成功', async () => {
      localStorage.setItem('auth_tenant_id', '99')
      mockedAxios.get
        .mockResolvedValueOnce({ status: 204 }) // csrf-cookie
        .mockRejectedValueOnce(httpError(403))
        .mockResolvedValueOnce({ data: { data: { user: { name: 'u' }, permissions: [] } } })
      const store = useUserStore()
      await store.init()
      expect(store.user?.name).toBe('u')
      expect(localStorage.getItem('auth_tenant_id')).toBeNull()
    })

    it('401 时保持未登录', async () => {
      mockedAxios.get
        .mockResolvedValueOnce({ status: 204 }) // csrf-cookie
        .mockRejectedValue(httpError(401))
      const store = useUserStore()
      await store.init()
      expect(store.user).toBeNull()
      expect(store.isLoggedIn).toBe(false)
    })

    it('5xx/网络错误不抛出，保持未登录由守卫兜底', async () => {
      mockedAxios.get
        .mockResolvedValueOnce({ status: 204 }) // csrf-cookie
        .mockRejectedValue(httpError(500))
      const store = useUserStore()
      await expect(store.init()).resolves.toBeUndefined()
      expect(store.user).toBeNull()
    })

    it('init 时恢复 localStorage 中的 X-Tenant-ID 头', async () => {
      localStorage.setItem('auth_tenant_id', '77')
      mockedAxios.get.mockRejectedValue(httpError(401))
      const store = useUserStore()
      await store.init()
      expect(mockedAxios.defaults.headers.common['X-Tenant-ID']).toBe('77')
    })
  })
})
