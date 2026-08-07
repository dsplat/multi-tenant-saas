<template>
  <div class="quotas-page">
    <div class="page-header"><h2>配额管理</h2></div>

    <div class="panel">
      <div v-if="!tenantStore.hasTenant" class="empty-state">
        <h3>请先选择团队</h3>
        <p>在顶部导航栏选择一个团队后，即可查看该团队的配额。</p>
      </div>

      <div v-else>
        <table class="data-table">
          <thead>
            <tr><th>资源类型</th><th>限制</th><th>已用</th><th>剩余</th><th>使用情况</th></tr>
          </thead>
          <tbody>
            <tr v-for="q in quotas" :key="q.resource_type">
              <td>{{ q.resource_type }}</td>
              <td>{{ q.limit === -1 ? '无限制' : q.limit }}</td>
              <td>{{ q.used }}</td>
              <td>{{ q.limit === -1 ? '-' : q.remaining }}</td>
              <td>
                <div class="progress-cell" v-if="q.limit !== -1">
                  <div class="progress-bar">
                    <div class="progress-fill" :style="{ width: progressPercent(q) + '%' }" :class="progressClass(q)"></div>
                  </div>
                  <span class="progress-text">{{ progressPercent(q) }}%</span>
                </div>
                <span v-else class="badge badge-info">无限制</span>
              </td>
            </tr>
            <tr v-if="quotas.length === 0">
              <td colspan="5" class="empty-row">暂无配额数据</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import axios from 'axios'
// 租户上下文统一走头部团队选择器（tenantStore），页面内不再自建选择器
import { useTenantStore } from '@stores/tenant'

const tenantStore = useTenantStore()
const quotas = ref<any[]>([])

const progressPercent = (q: any) => {
  if (!q.limit || q.limit <= 0) return 0
  return Math.min(100, Math.round((q.used / q.limit) * 100))
}

const progressClass = (q: any) => {
  const pct = progressPercent(q)
  if (pct >= 90) return 'fill-danger'
  if (pct >= 70) return 'fill-warning'
  return 'fill-ok'
}

const loadQuotas = async () => {
  if (!tenantStore.hasTenant) return
  try {
    const res = await axios.get(`/api/v1/tenants/${tenantStore.tenantId}/quotas`)
    quotas.value = res.data.data || []
  } catch {
    quotas.value = []
  }
}

onMounted(() => { if (tenantStore.hasTenant) loadQuotas() })
watch(() => tenantStore.tenantId, () => { if (tenantStore.hasTenant) loadQuotas(); else quotas.value = [] })
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.empty-state { text-align: center; padding: 48px 24px; color: var(--text-color-secondary, #666); }
.empty-state h3 { margin: 0 0 8px; color: var(--text-color-primary, #333); }
.empty-state p { margin: 0; font-size: 13px; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.empty-row { text-align: center; color: var(--text-color-secondary, #999); padding: 24px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-info { background: var(--badge-info-bg); color: var(--badge-info-fg); }
.progress-cell { display: flex; align-items: center; gap: 8px; }
.progress-bar { flex: 1; height: 8px; background: var(--fill-color, #eee); border-radius: 4px; overflow: hidden; max-width: 160px; }
.progress-fill { height: 100%; border-radius: 4px; transition: width 0.3s; }
.fill-ok { background: #4caf50; }
.fill-warning { background: #ff9800; }
.fill-danger { background: #f44336; }
.progress-text { font-size: 12px; color: var(--text-color-secondary, #999); min-width: 36px; }
</style>
