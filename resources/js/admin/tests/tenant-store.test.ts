import { describe, it, expect, beforeEach, vi } from 'vitest'
import { setActivePinia, createPinia } from 'pinia'
import { useTenantStore } from '../stores/tenant'

vi.mock('axios', () => ({
  default: {
    get: vi.fn(),
    post: vi.fn(),
    defaults: { headers: { common: {} } },
  },
}))

const tenant = (id: number, name = `t${id}`) => ({ tenant_id: id, name, slug: name, status: 'active' })

describe('admin tenant store', () => {
  beforeEach(() => {
    localStorage.clear()
    setActivePinia(createPinia())
  })

  it('selectTenant 持久化选中租户', () => {
    const store = useTenantStore()
    store.selectTenant(tenant(7))
    expect(store.selectedTenant?.tenant_id).toBe(7)
    expect(store.tenantId).toBe(7)
    expect(store.hasTenant).toBe(true)
    expect(localStorage.getItem('admin_selected_tenant')).toBe('7')
  })

  it('selectTenant(null) 清除持久化', () => {
    const store = useTenantStore()
    store.selectTenant(tenant(7))
    store.selectTenant(null)
    expect(store.selectedTenant).toBeNull()
    expect(store.hasTenant).toBe(false)
    expect(localStorage.getItem('admin_selected_tenant')).toBeNull()
  })

  it('restoreSelection 恢复已保存的租户', async () => {
    const store = useTenantStore()
    store.tenants = [tenant(1), tenant(2), tenant(3)]
    localStorage.setItem('admin_selected_tenant', '2')
    await store.restoreSelection()
    expect(store.selectedTenant?.tenant_id).toBe(2)
  })

  it('保存的租户不存在时回落到第一个', async () => {
    const store = useTenantStore()
    store.tenants = [tenant(1), tenant(2)]
    localStorage.setItem('admin_selected_tenant', '99')
    await store.restoreSelection()
    expect(store.selectedTenant?.tenant_id).toBe(1)
    expect(localStorage.getItem('admin_selected_tenant')).toBe('1')
  })

  it('无保存记录时自动选第一个', async () => {
    const store = useTenantStore()
    store.tenants = [tenant(5)]
    await store.restoreSelection()
    expect(store.selectedTenant?.tenant_id).toBe(5)
  })

  it('租户列表为空时保持未选中', async () => {
    const store = useTenantStore()
    await store.restoreSelection()
    expect(store.selectedTenant).toBeNull()
    expect(store.tenantId).toBe('')
  })
})
