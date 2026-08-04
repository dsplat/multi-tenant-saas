import { describe, it, expect, vi } from 'vitest'
import { createAuthGuard, type AuthStoreLike } from '../router/guards'

const makeStore = (over: Partial<AuthStoreLike> = {}): AuthStoreLike => ({
  user: null,
  fetchUser: vi.fn().mockResolvedValue(undefined),
  ...over,
})

const to = (over: Record<string, unknown> = {}) =>
  ({ fullPath: '/dashboard', name: 'Dashboard', meta: {}, ...over }) as any

const from = {} as any

describe('admin 路由守卫', () => {
  it('公开页（requiresAuth: false）直接放行', async () => {
    const next = vi.fn()
    await createAuthGuard(() => makeStore())(to({ meta: { requiresAuth: false } }), from, next)
    expect(next).toHaveBeenCalledWith()
  })

  it('未登录（探测失败）跳转登录页并携带 redirect', async () => {
    const next = vi.fn()
    const store = makeStore({ fetchUser: vi.fn().mockRejectedValue(new Error('401')) })
    await createAuthGuard(() => store)(to({ fullPath: '/tenants' }), from, next)
    expect(next).toHaveBeenCalledWith({ name: 'Login', query: { redirect: '/tenants' } })
  })

  it('已登录且有用户信息直接放行，不重复探测', async () => {
    const next = vi.fn()
    const store = makeStore({ user: { name: 'u' } })
    await createAuthGuard(() => store)(to(), from, next)
    expect(store.fetchUser).not.toHaveBeenCalled()
    expect(next).toHaveBeenCalledWith()
  })

  it('无用户信息时先探测，探测成功后放行', async () => {
    const next = vi.fn()
    const store = makeStore()
    ;(store.fetchUser as ReturnType<typeof vi.fn>).mockImplementation(async () => {
      ;(store as any).user = { name: 'u' }
    })
    await createAuthGuard(() => store)(to(), from, next)
    expect(store.fetchUser).toHaveBeenCalledOnce()
    expect(next).toHaveBeenCalledWith()
  })

  it('探测失败且仍无用户时跳转登录页', async () => {
    const next = vi.fn()
    const store = makeStore({ fetchUser: vi.fn().mockRejectedValue(new Error('401')) })
    await createAuthGuard(() => store)(to({ fullPath: '/x' }), from, next)
    expect(store.user).toBeNull()
    expect(next).toHaveBeenCalledWith({ name: 'Login', query: { redirect: '/x' } })
  })
})
