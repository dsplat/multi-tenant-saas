<template>
  <div class="dashboard">
    <el-row :gutter="16" class="stat-row">
      <el-col :span="6">
        <el-card shadow="hover" class="stat-card">
          <el-statistic title="租户总数" :value="stats.tenantCount" />
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="hover" class="stat-card">
          <el-statistic title="活跃租户" :value="stats.activeTenantCount" />
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="hover" class="stat-card">
          <el-statistic title="已暂停" :value="stats.suspendedCount" />
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="hover" class="stat-card">
          <div class="stat-label">套餐分布</div>
          <div class="stat-text">{{ stats.planBreakdown }}</div>
        </el-card>
      </el-col>
    </el-row>

    <el-row :gutter="16" class="content-row">
      <el-col :span="14">
        <el-card shadow="hover">
          <template #header>最近租户</template>
          <el-table :data="recentTenants" stripe style="width: 100%" empty-text="暂无数据">
            <el-table-column prop="name" label="名称" />
            <el-table-column prop="subscription_plan" label="套餐">
              <template #default="{ row }">{{ row.subscription_plan || '-' }}</template>
            </el-table-column>
            <el-table-column label="状态" width="100">
              <template #default="{ row }">
                <el-tag :type="row.status === 'active' ? 'success' : 'info'" size="small">
                  {{ row.status === 'active' ? '活跃' : row.status }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="创建时间" width="120">
              <template #default="{ row }">{{ formatDate(row.created_at) }}</template>
            </el-table-column>
          </el-table>
        </el-card>
      </el-col>

      <el-col :span="10">
        <el-card shadow="hover">
          <template #header>系统信息</template>
          <el-descriptions :column="1" border>
            <el-descriptions-item label="框架版本">v2.4.0</el-descriptions-item>
            <el-descriptions-item label="Laravel">13.x</el-descriptions-item>
            <el-descriptions-item label="PHP">8.4</el-descriptions-item>
            <el-descriptions-item label="模块数">26</el-descriptions-item>
            <el-descriptions-item label="测试覆盖">2351 tests / 0 failures</el-descriptions-item>
          </el-descriptions>
        </el-card>
      </el-col>
    </el-row>
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
.dashboard { padding: 0; }
.stat-row { margin-bottom: 16px; }
.stat-card { height: 100%; }
.stat-label { font-size: 13px; color: var(--text-color-secondary, #909399); margin-bottom: 8px; }
.stat-text { font-size: 18px; font-weight: 600; color: var(--el-color-primary); }
.content-row { margin-bottom: 16px; }
</style>
