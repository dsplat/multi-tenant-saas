import { describe, it, expect, beforeEach, vi } from 'vitest'
import { createAuthGuard, type AuthStoreLike } from '../router/guards'

const makeStore = (over: Partial<AuthStoreLike> = {}): AuthStoreLike => ({
  token: null,
  user: null,
  tenantId: '',
  fetchUser: vi.fn().mockResolvedValue(undefined),
  ...over,
})

const to = (over: Record<string, unknown> = {}) =>
  ({ fullPath: '/dashboard', name: 'Dashboard', meta: {}, ...over }) as any

const from = {} as any

describe('console 路由守卫', () => {
  beforeEach(() => localStorage.clear())

  it('公开页（requiresAuth: false）直接放行', async () => {
    const next = vi.fn()
    await createAuthGuard(() => makeStore())(to({ meta: { requiresAuth: false } }), from, next)
    expect(next).toHaveBeenCalledWith()
  })

  it('未登录跳转登录页并携带 redirect', async () => {
    const next = vi.fn()
    await createAuthGuard(() => makeStore())(to({ fullPath: '/customers' }), from, next)
    expect(next).toHaveBeenCalledWith({ name: 'Login', query: { redirect: '/customers' } })
  })

  it('token 失效时清理并跳转登录页', async () => {
    localStorage.setItem('auth_token', 'bad')
    const next = vi.fn()
    const store = makeStore({ token: 'bad', fetchUser: vi.fn().mockRejectedValue(new Error('401')) })
    await createAuthGuard(() => store)(to({ fullPath: '/x' }), from, next)
    expect(store.token).toBeNull()
    expect(localStorage.getItem('auth_token')).toBeNull()
    expect(next).toHaveBeenCalledWith({ name: 'Login', query: { redirect: '/x' } })
  })

  it('已登录有租户直接放行', async () => {
    const next = vi.fn()
    const store = makeStore({ token: 'tok', user: { name: 'u' }, tenantId: '11' })
    await createAuthGuard(() => store)(to(), from, next)
    expect(next).toHaveBeenCalledWith()
  })

  it('无租户访问业务页引导去申请团队', async () => {
    const next = vi.fn()
    const store = makeStore({ token: 'tok', user: { name: 'u' }, tenantId: '' })
    await createAuthGuard(() => store)(to(), from, next)
    expect(next).toHaveBeenCalledWith({ name: 'ApplyTeam' })
  })

  it('无租户访问申请页本身放行（避免重定向死循环）', async () => {
    const next = vi.fn()
    const store = makeStore({ token: 'tok', user: { name: 'u' }, tenantId: '' })
    await createAuthGuard(() => store)(
      to({ name: 'ApplyTeam', fullPath: '/apply', meta: { requiresTenant: false } }),
      from,
      next,
    )
    expect(next).toHaveBeenCalledWith()
  })

  it('requiresTenant: false 的页面无租户也放行', async () => {
    const next = vi.fn()
    const store = makeStore({ token: 'tok', user: { name: 'u' }, tenantId: '' })
    await createAuthGuard(() => store)(
      to({ name: 'Profile', meta: { requiresTenant: false } }),
      from,
      next,
    )
    expect(next).toHaveBeenCalledWith()
  })
})
