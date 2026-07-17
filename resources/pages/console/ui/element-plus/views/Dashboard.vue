<template>
  <div class="dashboard">
    <el-row :gutter="16" class="stat-row">
      <el-col :span="6">
        <el-card shadow="hover" class="stat-card">
          <el-statistic title="成员总数" :value="stats.memberCount" />
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="hover" class="stat-card">
          <el-statistic title="可用积分" :value="stats.availableCredits" />
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="hover" class="stat-card">
          <el-statistic title="已用积分" :value="stats.usedCredits" />
        </el-card>
      </el-col>
      <el-col :span="6">
        <el-card shadow="hover" class="stat-card">
          <el-statistic title="本月使用" :value="stats.monthlyUsage" />
        </el-card>
      </el-col>
    </el-row>

    <el-row :gutter="16">
      <el-col :span="12">
        <el-card shadow="hover">
          <template #header>快速操作</template>
          <div class="quick-actions">
            <el-button type="primary" plain @click="$router.push('/members')">管理成员</el-button>
            <el-button type="primary" plain @click="$router.push('/credits')">查看积分</el-button>
            <el-button type="primary" plain @click="$router.push('/tenant-settings')">租户设置</el-button>
          </div>
        </el-card>
      </el-col>

      <el-col :span="12">
        <el-card shadow="hover">
          <template #header>租户信息</template>
          <el-descriptions :column="1" border>
            <el-descriptions-item label="租户名称">{{ tenant.name }}</el-descriptions-item>
            <el-descriptions-item label="租户ID">{{ tenant.tenant_id }}</el-descriptions-item>
            <el-descriptions-item label="套餐">{{ tenant.plan }}</el-descriptions-item>
            <el-descriptions-item label="状态">
              <el-tag type="success" size="small">活跃</el-tag>
            </el-descriptions-item>
          </el-descriptions>
        </el-card>
      </el-col>
    </el-row>
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
.dashboard { padding: 0; }
.stat-row { margin-bottom: 16px; }
.stat-card { height: 100%; }
.quick-actions { display: flex; flex-direction: column; gap: 12px; }
.quick-actions .el-button { justify-content: flex-start; }
</style>
