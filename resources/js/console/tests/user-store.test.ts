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

  describe('login', () => {
    it('MFA 场景提前返回且不写 token', async () => {
      mockedAxios.post.mockResolvedValue({ data: { data: { mfa_required: true } } })
      const store = useUserStore()
      const res = await store.login('a@a.com', 'pwd')
      expect(res.data.mfa_required).toBe(true)
      expect(store.token).toBeNull()
    })

    it('Operator 登录选择第一个租户并写入 X-Tenant-ID', async () => {
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
      expect(store.token).toBe('tok-op')
      expect(store.user?.tenant_id).toBe('11')
      expect(store.tenantId).toBe('11')
      expect(localStorage.getItem('auth_tenant_id')).toBe('11')
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

    it('Legacy User 登录写入 tenant_id', async () => {
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
      expect(store.token).toBe('tok-legacy')
      expect(store.tenantId).toBe('33')
      expect(mockedAxios.defaults.headers.common['X-Tenant-ID']).toBe('33')
    })
  })

  describe('logout', () => {
    it('清空 token、租户上下文与请求头', async () => {
      mockedAxios.post.mockRejectedValue(new Error('network'))
      const store = useUserStore()
      store.setToken('tok')
      localStorage.setItem('auth_tenant_id', '11')
      mockedAxios.defaults.headers.common['X-Tenant-ID'] = '11'
      await store.logout()
      expect(store.token).toBeNull()
      expect(localStorage.getItem('auth_token')).toBeNull()
      expect(localStorage.getItem('auth_tenant_id')).toBeNull()
      expect(mockedAxios.defaults.headers.common['Authorization']).toBeUndefined()
      expect(mockedAxios.defaults.headers.common['X-Tenant-ID']).toBeUndefined()
    })
  })

  describe('init 容错', () => {
    it('403 时清残留租户头重试成功，不清 token', async () => {
      localStorage.setItem('auth_token', 'tok')
      localStorage.setItem('auth_tenant_id', '99')
      mockedAxios.get
        .mockRejectedValueOnce(httpError(403))
        .mockResolvedValueOnce({ data: { data: { user: { name: 'u' }, permissions: [] } } })
      const store = useUserStore()
      await store.init()
      expect(store.token).toBe('tok')
      expect(store.user?.name).toBe('u')
      expect(localStorage.getItem('auth_tenant_id')).toBeNull()
    })

    it('401 时清除会话', async () => {
      localStorage.setItem('auth_token', 'tok')
      mockedAxios.get.mockRejectedValue(httpError(401))
      const store = useUserStore()
      await store.init()
      expect(store.token).toBeNull()
      expect(localStorage.getItem('auth_token')).toBeNull()
    })

    it('5xx/网络错误保留 token 避免误登出', async () => {
      localStorage.setItem('auth_token', 'tok')
      mockedAxios.get.mockRejectedValue(httpError(500))
      const store = useUserStore()
      await store.init()
      expect(store.token).toBe('tok')
      expect(localStorage.getItem('auth_token')).toBe('tok')
    })
  })
})
