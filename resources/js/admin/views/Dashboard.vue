<template>
  <div class="dashboard">
    <div class="stat-grid">
      <div class="stat-card"><div class="stat-label">租户总数</div><div class="stat-value">{{ stats.tenantCount }}</div></div>
      <div class="stat-card"><div class="stat-label">活跃租户</div><div class="stat-value">{{ stats.activeTenantCount }}</div></div>
      <div class="stat-card"><div class="stat-label">已暂停</div><div class="stat-value">{{ stats.suspendedCount }}</div></div>
      <div class="stat-card"><div class="stat-label">套餐分布</div><div class="stat-value">{{ stats.planBreakdown }}</div></div>
    </div>

    <div class="content-grid">
      <div class="panel">
        <h3>最近租户</h3>
        <table class="data-table">
          <thead><tr><th>名称</th><th>套餐</th><th>状态</th><th>创建时间</th></tr></thead>
          <tbody>
            <tr v-for="t in recentTenants" :key="t.tenant_id">
              <td>{{ t.name }}</td><td>{{ t.subscription_plan || '-' }}</td>
              <td><span :class="['badge', t.status === 'active' ? 'badge-success' : 'badge-info']">{{ t.status === 'active' ? '活跃' : t.status }}</span></td>
              <td>{{ formatDate(t.created_at) }}</td>
            </tr>
            <tr v-if="recentTenants.length === 0"><td colspan="4" class="empty-row">暂无数据</td></tr>
          </tbody>
        </table>
      </div>

      <div class="panel">
        <h3>系统信息</h3>
        <div class="info-list">
          <div class="info-row"><span>框架版本</span><span>v2.4.0</span></div>
          <div class="info-row"><span>Laravel</span><span>13.x</span></div>
          <div class="info-row"><span>PHP</span><span>8.4</span></div>
          <div class="info-row"><span>模块数</span><span>26</span></div>
          <div class="info-row"><span>测试覆盖</span><span>2351 tests / 0 failures</span></div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'

const stats = reactive({ tenantCount: 0, activeTenantCount: 0, suspendedCount: 0, planBreakdown: '-' })
const recentTenants = ref<any[]>([])
const formatDate = (d: string) => d ? d.substring(0, 10) : '-'

const fetchDashboard = async () => {
  try {
    const r = await axios.get('/api/v1/tenants', { params: { per_page: 100 } })
    const all = r.data.data || []
    stats.tenantCount = all.length
    stats.activeTenantCount = all.filter((t: any) => t.status === 'active').length
    stats.suspendedCount = all.filter((t: any) => t.status === 'suspended').length
    const plans: Record<string, number> = {}
    all.forEach((t: any) => { const p = t.subscription_plan || 'free'; plans[p] = (plans[p] || 0) + 1 })
    stats.planBreakdown = Object.entries(plans).map(([k, v]) => `${k}:${v}`).join(' / ')
    recentTenants.value = all.slice(0, 5)
  } catch {}
}

onMounted(fetchDashboard)
</script>

<style scoped>
.stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
.stat-card { background: var(--bg-color, #fff); border-radius: 8px; padding: 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.stat-label { font-size: 13px; color: var(--text-color-secondary, #999); margin-bottom: 8px; }
.stat-value { font-size: 24px; font-weight: 600; color: var(--link-color); }
.content-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.panel h3 { margin: 0 0 16px; font-size: 15px; color: var(--text-color-primary); }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; color: var(--text-color-secondary); font-weight: 500; }
.data-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; color: var(--text-color-primary); }
.empty-row { text-align: center; color: var(--text-color-secondary, #999); padding: 24px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-success { background: var(--badge-success-bg); color: var(--badge-success-fg); }
.badge-info { background: var(--badge-info-bg); color: var(--badge-info-fg); }
.info-list { display: flex; flex-direction: column; gap: 12px; }
.info-row { display: flex; justify-content: space-between; font-size: 13px; color: var(--text-color-primary, #333); }
.info-row span:first-child { color: var(--text-color-secondary, #999); }
</style>
