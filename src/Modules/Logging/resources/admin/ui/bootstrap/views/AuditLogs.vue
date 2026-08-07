<template>
  <div class="audit-page">
    <div class="page-header"><h2>审计日志</h2></div>

    <div class="panel">
      <div v-if="!tenantStore.hasTenant" class="empty-state">
        <h3>请先选择团队</h3>
        <p>在顶部导航栏选择一个团队后，即可查看该团队的审计日志。</p>
      </div>

      <div v-else>
        <div class="filters">
          <div class="filter-group">
            <label>操作类型</label>
            <select v-model="filters.action" @change="loadLogs">
              <option value="">全部</option>
              <option v-for="a in actionOptions" :key="a" :value="a">{{ a }}</option>
            </select>
          </div>
          <div class="filter-group">
            <label>资源类型</label>
            <select v-model="filters.resource_type" @change="loadLogs">
              <option value="">全部</option>
              <option v-for="r in resourceTypeOptions" :key="r" :value="r">{{ r }}</option>
            </select>
          </div>
        </div>

        <table class="data-table">
          <thead>
            <tr><th>时间</th><th>用户</th><th>操作</th><th>资源类型</th><th>资源ID</th></tr>
          </thead>
          <tbody>
            <tr v-for="log in logs" :key="log.id">
              <td>{{ log.created_at }}</td>
              <td>{{ log.user_name || log.user_id || '-' }}</td>
              <td><span class="badge badge-info">{{ log.action }}</span></td>
              <td>{{ log.resource_type || '-' }}</td>
              <td>{{ log.resource_id || '-' }}</td>
            </tr>
            <tr v-if="logs.length === 0">
              <td colspan="5" class="empty-row">暂无日志</td>
            </tr>
          </tbody>
        </table>

        <div class="pagination" v-if="totalPages > 1">
          <button class="page-btn" :disabled="currentPage <= 1" @click="goPage(currentPage - 1)">上一页</button>
          <span class="page-info">{{ currentPage }} / {{ totalPages }}</span>
          <button class="page-btn" :disabled="currentPage >= totalPages" @click="goPage(currentPage + 1)">下一页</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted, watch } from 'vue'
import axios from 'axios'
// 租户上下文统一走头部团队选择器（tenantStore），页面内不再自建选择器
import { useTenantStore } from '@stores/tenant'

const tenantStore = useTenantStore()
const logs = ref<any[]>([])
const currentPage = ref(1)
const totalPages = ref(1)
const perPage = 20

const actionOptions = ref<string[]>([])
const resourceTypeOptions = ref<string[]>([])
const filters = reactive({ action: '', resource_type: '' })

const loadLogs = async () => {
  if (!tenantStore.hasTenant) return
  currentPage.value = 1
  try {
    const res = await axios.get(`/api/v1/tenants/${tenantStore.tenantId}/audit-logs`, {
      params: { page: 1, per_page: perPage, action: filters.action || undefined, resource_type: filters.resource_type || undefined },
    })
    logs.value = res.data.data || []
    totalPages.value = res.data.meta?.last_page || 1
    if (res.data.meta?.actions) actionOptions.value = res.data.meta.actions
    if (res.data.meta?.resource_types) resourceTypeOptions.value = res.data.meta.resource_types
  } catch {
    logs.value = []
  }
}

const goPage = async (page: number) => {
  if (!tenantStore.hasTenant) return
  try {
    const res = await axios.get(`/api/v1/tenants/${tenantStore.tenantId}/audit-logs`, {
      params: { page, per_page: perPage, action: filters.action || undefined, resource_type: filters.resource_type || undefined },
    })
    logs.value = res.data.data || []
    currentPage.value = page
    totalPages.value = res.data.meta?.last_page || 1
  } catch {}
}

onMounted(() => { if (tenantStore.hasTenant) loadLogs() })
watch(() => tenantStore.tenantId, () => { if (tenantStore.hasTenant) loadLogs(); else logs.value = [] })
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.empty-state { text-align: center; padding: 48px 24px; color: var(--text-color-secondary, #666); }
.empty-state h3 { margin: 0 0 8px; color: var(--text-color-primary, #333); }
.empty-state p { margin: 0; font-size: 13px; }
.filters { display: flex; gap: 16px; margin-bottom: 16px; }
.filter-group { display: flex; align-items: center; gap: 8px; }
.filter-group label { font-size: 13px; color: var(--text-color-secondary, #666); }
.filter-group select { padding: 6px 10px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 13px; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.empty-row { text-align: center; color: var(--text-color-secondary, #999); padding: 24px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-info { background: var(--badge-info-bg); color: var(--badge-info-fg); }
.pagination { display: flex; align-items: center; justify-content: center; gap: 16px; margin-top: 20px; }
.page-btn { padding: 6px 14px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; background: #fff; cursor: pointer; font-size: 13px; }
.page-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.page-info { font-size: 13px; color: var(--text-color-secondary, #666); }
</style>
