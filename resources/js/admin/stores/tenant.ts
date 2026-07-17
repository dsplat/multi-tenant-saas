import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import axios from 'axios'

interface Tenant {
  tenant_id: number
  name: string
  slug: string
  status: string
  subscription_plan?: string
}

export const useTenantStore = defineStore('tenant', () => {
  const selectedTenant = ref<Tenant | null>(null)
  const tenants = ref<Tenant[]>([])
  const loading = ref(false)

  const tenantId = computed(() => selectedTenant.value?.tenant_id || '')
  const hasTenant = computed(() => !!selectedTenant.value)

  const fetchTenants = async () => {
    loading.value = true
    try {
      const r = await axios.get('/api/v1/tenants', { params: { per_page: 100 } })
      tenants.value = r.data.data || []
    } catch { tenants.value = [] }
    finally { loading.value = false }
  }

  const selectTenant = (tenant: Tenant | null) => {
    selectedTenant.value = tenant
    if (tenant) {
      localStorage.setItem('admin_selected_tenant', String(tenant.tenant_id))
    } else {
      localStorage.removeItem('admin_selected_tenant')
    }
  }

  const restoreSelection = async () => {
    const saved = localStorage.getItem('admin_selected_tenant')
    if (saved && tenants.value.length > 0) {
      const found = tenants.value.find(t => t.tenant_id === Number(saved))
      if (found) {
        selectedTenant.value = found
        return
      }
    }
    // Auto-select first tenant if no saved selection
    if (tenants.value.length > 0) {
      selectedTenant.value = tenants.value[0]
      localStorage.setItem('admin_selected_tenant', String(tenants.value[0].tenant_id))
    } else {
      selectedTenant.value = null
    }
  }

  return {
    selectedTenant,
    tenants,
    loading,
    tenantId,
    hasTenant,
    fetchTenants,
    selectTenant,
    restoreSelection,
  }
})
