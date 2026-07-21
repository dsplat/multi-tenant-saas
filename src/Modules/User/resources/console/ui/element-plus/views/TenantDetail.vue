<template>
  <div class="page">
    <div class="page-header">
      <h2>租户详情</h2>
      <el-button @click="router.push('/tenants')">返回列表</el-button>
    </div>

    <el-card v-if="tenant" shadow="never" style="margin-bottom: 16px">
      <el-descriptions :column="2" border>
        <el-descriptions-item label="租户ID">{{ tenant.tenant_id }}</el-descriptions-item>
        <el-descriptions-item label="名称">{{ tenant.name }}</el-descriptions-item>
        <el-descriptions-item label="标识">{{ tenant.slug }}</el-descriptions-item>
        <el-descriptions-item label="自定义域名">{{ tenant.domain || '-' }}</el-descriptions-item>
        <el-descriptions-item label="状态">
          <el-tag :type="tenant.status === 'active' ? 'success' : 'info'" size="small">{{ tenant.status === 'active' ? '活跃' : '未激活' }}</el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="套餐">{{ tenant.subscription_plan }}</el-descriptions-item>
        <el-descriptions-item label="总积分">{{ tenant.total_credits }}</el-descriptions-item>
        <el-descriptions-item label="可用积分">{{ tenant.available_credits }}</el-descriptions-item>
      </el-descriptions>
    </el-card>

    <el-card shadow="never">
      <template #header><span style="font-size: 15px; font-weight: 500">成员列表</span></template>
      <el-table :data="members" stripe style="width: 100%" empty-text="暂无成员">
        <el-table-column prop="user_id" label="用户ID" width="120" />
        <el-table-column prop="name" label="姓名" width="120" />
        <el-table-column prop="email" label="邮箱" />
        <el-table-column label="角色" width="100">
          <template #default="{ row }">
            <el-tag :type="row.pivot?.role === 'tenant_admin' ? 'warning' : 'info'" size="small">
              {{ row.pivot?.role === 'tenant_admin' ? '管理员' : '普通用户' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.pivot?.is_active ? 'success' : 'danger'" size="small">
              {{ row.pivot?.is_active ? '激活' : '未激活' }}
            </el-tag>
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'

const route = useRoute()
const router = useRouter()
const tenant = ref<any>(null)
const members = ref<any[]>([])

onMounted(async () => {
  try {
    const res = await axios.get(`/api/v1/tenants/${route.params.id}`)
    tenant.value = res.data.data
  } catch {}
  try {
    const res = await axios.get(`/api/v1/tenants/${route.params.id}/members`)
    members.value = res.data.data || []
  } catch {}
})
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
</style>
