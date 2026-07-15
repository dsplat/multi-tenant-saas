<template>
  <div class="dashboard">
    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-label">成员总数</div>
        <div class="stat-value">{{ stats.memberCount }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">可用积分</div>
        <div class="stat-value">{{ stats.availableCredits }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">已用积分</div>
        <div class="stat-value">{{ stats.usedCredits }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">本月使用</div>
        <div class="stat-value">{{ stats.monthlyUsage }}</div>
      </div>
    </div>

    <div class="content-grid">
      <div class="panel">
        <h3>快速操作</h3>
        <div class="quick-actions">
          <a href="/console/members" class="action-btn">管理成员</a>
          <a href="/console/credits" class="action-btn">查看积分</a>
          <a href="/console/settings" class="action-btn">租户设置</a>
        </div>
      </div>

      <div class="panel">
        <h3>租户信息</h3>
        <div class="info-list">
          <div class="info-row"><span>租户名称</span><span>{{ tenant.name }}</span></div>
          <div class="info-row"><span>租户ID</span><span>{{ tenant.tenant_id }}</span></div>
          <div class="info-row"><span>套餐</span><span>{{ tenant.plan }}</span></div>
          <div class="info-row"><span>状态</span><span class="badge badge-success">活跃</span></div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const stats = ref({ memberCount: 0, availableCredits: 0, usedCredits: 0, monthlyUsage: 0 })
const tenant = ref({ name: '', tenant_id: '', plan: '' })

const fetchDashboard = async () => {
  try {
    const membersRes = await axios.get('/tenant/members')
    const members = membersRes.data.data || []
    stats.value.memberCount = members.length

    const creditsRes = await axios.get('/tenant/credits')
    const credits = creditsRes.data.data || {}
    stats.value.availableCredits = credits.balance ?? credits.available ?? 0
    stats.value.usedCredits = credits.used ?? credits.consumed ?? 0

    const settingsRes = await axios.get('/tenant/settings')
    const settings = settingsRes.data.data || {}
    tenant.value = {
      name: settings.name || settings.tenant_name || '-',
      tenant_id: settings.tenant_id || '-',
      plan: settings.subscription_plan || settings.plan || '-',
    }
  } catch {}
}

onMounted(fetchDashboard)
</script>

<style scoped>
.stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
.stat-card { background: var(--bg-color, #fff); border-radius: 8px; padding: 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.stat-label { font-size: 13px; color: var(--text-color-secondary, #999); margin-bottom: 8px; }
.stat-value { font-size: 28px; font-weight: bold; color: var(--primary-color, #409eff); }
.content-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.panel h3 { margin: 0 0 16px; font-size: 15px; }
.quick-actions { display: flex; flex-direction: column; gap: 10px; }
.action-btn { display: block; padding: 10px 16px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; text-decoration: none; color: var(--text-color-primary, #333); font-size: 13px; transition: all 0.15s; }
.action-btn:hover { border-color: var(--primary-color, #409eff); color: var(--primary-color, #409eff); }
.info-list { display: flex; flex-direction: column; gap: 12px; }
.info-row { display: flex; justify-content: space-between; font-size: 13px; color: var(--text-color-primary, #333); }
.info-row span:first-child { color: var(--text-color-secondary, #999); }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-success { background: #e8f5e9; color: #2e7d32; }
</style>
