import { describe, it, expect, beforeEach, vi } from 'vitest'
import { createAuthGuard, type AuthStoreLike } from '../router/guards'

const makeStore = (over: Partial<AuthStoreLike> = {}): AuthStoreLike => ({
  token: null,
  user: null,
  fetchUser: vi.fn().mockResolvedValue(undefined),
  ...over,
})

const to = (over: Record<string, unknown> = {}) =>
  ({ fullPath: '/dashboard', name: 'Dashboard', meta: {}, ...over }) as any

const from = {} as any

describe('admin 路由守卫', () => {
  beforeEach(() => localStorage.clear())

  it('公开页（requiresAuth: false）直接放行', async () => {
    const next = vi.fn()
    await createAuthGuard(() => makeStore())(to({ meta: { requiresAuth: false } }), from, next)
    expect(next).toHaveBeenCalledWith()
  })

  it('未登录跳转登录页并携带 redirect', async () => {
    const next = vi.fn()
    await createAuthGuard(() => makeStore())(to({ fullPath: '/tenants' }), from, next)
    expect(next).toHaveBeenCalledWith({ name: 'Login', query: { redirect: '/tenants' } })
  })

  it('已登录且有用户信息直接放行', async () => {
    const next = vi.fn()
    const store = makeStore({ token: 'tok', user: { name: 'u' } })
    await createAuthGuard(() => store)(to(), from, next)
    expect(store.fetchUser).not.toHaveBeenCalled()
    expect(next).toHaveBeenCalledWith()
  })

  it('有 token 无用户信息时先拉取再放行', async () => {
    const next = vi.fn()
    const store = makeStore({ token: 'tok' })
    await createAuthGuard(() => store)(to(), from, next)
    expect(store.fetchUser).toHaveBeenCalledOnce()
    expect(next).toHaveBeenCalledWith()
  })

  it('token 失效时清理并跳转登录页', async () => {
    localStorage.setItem('admin_token', 'bad')
    const next = vi.fn()
    const store = makeStore({ token: 'bad', fetchUser: vi.fn().mockRejectedValue(new Error('401')) })
    await createAuthGuard(() => store)(to({ fullPath: '/x' }), from, next)
    expect(store.token).toBeNull()
    expect(localStorage.getItem('admin_token')).toBeNull()
    expect(next).toHaveBeenCalledWith({ name: 'Login', query: { redirect: '/x' } })
  })
})
