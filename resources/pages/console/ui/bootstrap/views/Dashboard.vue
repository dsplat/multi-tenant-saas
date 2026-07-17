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
          <router-link to="/members" class="action-btn">管理成员</router-link>
          <router-link to="/credits" class="action-btn">查看积分</router-link>
          <router-link to="/tenant-settings" class="action-btn">租户设置</router-link>
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
import { useUserStore } from '@stores/user'

const userStore = useUserStore()
const stats = ref({ memberCount: 0, availableCredits: 0, usedCredits: 0, monthlyUsage: 0 })
const tenant = ref({ name: '', tenant_id: '', plan: '' })

const fetchDashboard = async () => {
  const [membersRes, creditsRes, settingsRes] = await Promise.allSettled([
    axios.get('/tenant/members'),
    axios.get(`/api/v1/tenants/${userStore.tenantId}/credits`),
    axios.get('/tenant/settings'),
  ])

  if (membersRes.status === 'fulfilled') {
    const members = membersRes.value.data.data || []
    stats.value.memberCount = members.length
  }

  if (creditsRes.status === 'fulfilled') {
    const credits = creditsRes.value.data.data || {}
    stats.value.availableCredits = credits.balance ?? credits.available ?? 0
    stats.value.usedCredits = credits.used ?? credits.consumed ?? 0
  }

  if (settingsRes.status === 'fulfilled') {
    const settings = settingsRes.value.data.data || {}
    tenant.value = {
      name: settings.name || settings.tenant_name || '-',
      tenant_id: settings.tenant_id || '-',
      plan: settings.subscription_plan || settings.plan || '-',
    }
  }
}

onMounted(fetchDashboard)
</script>

<style scoped>
.stat-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 20px; }
.stat-card { background: var(--bg-color, #fff); border-radius: 8px; padding: 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.stat-label { font-size: 13px; color: var(--text-color-secondary, #999); margin-bottom: 8px; }
.stat-value { font-size: 28px; font-weight: bold; color: var(--link-color); }
.content-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.panel h3 { margin: 0 0 16px; font-size: 15px; }
.quick-actions { display: flex; flex-direction: column; gap: 10px; }
.action-btn { display: block; padding: 10px 16px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; text-decoration: none; color: var(--text-color-primary, #333); font-size: 13px; transition: all 0.15s; }
.action-btn:hover { border-color: var(--link-color); color: var(--link-color); }
.info-list { display: flex; flex-direction: column; gap: 12px; }
.info-row { display: flex; justify-content: space-between; font-size: 13px; color: var(--text-color-primary, #333); }
.info-row span:first-child { color: var(--text-color-secondary, #999); }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-success { background: var(--badge-success-bg); color: var(--badge-success-fg); }
</style>
