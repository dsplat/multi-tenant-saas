<template>
  <div class="dashboard">
    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-label">租户总数</div>
        <div class="stat-value">{{ stats.tenantCount }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">用户总数</div>
        <div class="stat-value">{{ stats.userCount }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">活跃租户</div>
        <div class="stat-value">{{ stats.activeTenantCount }}</div>
      </div>
      <div class="stat-card">
        <div class="stat-label">今日新增</div>
        <div class="stat-value">{{ stats.todayNewCount }}</div>
      </div>
    </div>

    <div class="content-grid">
      <div class="panel">
        <h3>最近租户</h3>
        <table class="data-table">
          <thead>
            <tr>
              <th>租户名称</th>
              <th>状态</th>
              <th>创建时间</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="t in recentTenants" :key="t.name">
              <td>{{ t.name }}</td>
              <td>
                <span :class="['badge', t.status === 'active' ? 'badge-success' : 'badge-info']">
                  {{ t.status === 'active' ? '活跃' : '未激活' }}
                </span>
              </td>
              <td>{{ t.created_at }}</td>
            </tr>
          </tbody>
        </table>
      </div>

      <div class="panel">
        <h3>系统信息</h3>
        <div class="info-list">
          <div class="info-row"><span>框架版本</span><span>1.0.0</span></div>
          <div class="info-row"><span>Laravel</span><span>12.x</span></div>
          <div class="info-row"><span>PHP</span><span>8.2+</span></div>
          <div class="info-row"><span>数据库</span><span>MySQL 8.0+</span></div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'

const stats = ref({
  tenantCount: 0,
  userCount: 0,
  activeTenantCount: 0,
  todayNewCount: 0,
})

const recentTenants = ref<any[]>([])

onMounted(() => {
  stats.value = { tenantCount: 10, userCount: 156, activeTenantCount: 8, todayNewCount: 3 }
  recentTenants.value = [
    { name: '示例企业A', status: 'active', created_at: '2026-06-18' },
    { name: '示例企业B', status: 'active', created_at: '2026-06-17' },
    { name: '示例企业C', status: 'inactive', created_at: '2026-06-16' },
  ]
})
</script>

<style scoped>
.stat-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 16px;
  margin-bottom: 20px;
}

.stat-card {
  background: var(--bg-color, #fff);
  border-radius: 8px;
  padding: 20px;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
}

.stat-label {
  font-size: 13px;
  color: var(--text-color-secondary, #999);
  margin-bottom: 8px;
}

.stat-value {
  font-size: 28px;
  font-weight: bold;
  color: var(--primary-color, #409eff);
}

.content-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}

.panel {
  background: var(--bg-color, #fff);
  border-radius: 8px;
  padding: 20px;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
}

.panel h3 {
  margin: 0 0 16px;
  font-size: 15px;
}

.data-table {
  width: 100%;
  border-collapse: collapse;
}

.data-table th,
.data-table td {
  text-align: left;
  padding: 10px 12px;
  border-bottom: 1px solid var(--border-color, #eee);
  font-size: 13px;
  color: var(--text-color-primary, #333);
}

.data-table th {
  color: var(--text-color-secondary, #999);
  font-weight: 500;
}

.badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 12px;
}

.badge-success {
  background: #e8f5e9;
  color: #2e7d32;
}

.badge-info {
  background: #eee;
  color: #666;
}

.info-list {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.info-row {
  display: flex;
  justify-content: space-between;
  font-size: 13px;
  color: var(--text-color-primary, #333);
}

.info-row span:first-child {
  color: var(--text-color-secondary, #999);
}
</style>
