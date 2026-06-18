<template>
  <div class="tenant-detail" v-loading="loading">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>租户详情</span>
          <el-button @click="router.push('/tenants')">返回列表</el-button>
        </div>
      </template>
      
      <el-descriptions :column="2" border>
        <el-descriptions-item label="租户ID">{{ tenant.tenant_id }}</el-descriptions-item>
        <el-descriptions-item label="租户名称">{{ tenant.name }}</el-descriptions-item>
        <el-descriptions-item label="标识">{{ tenant.slug }}</el-descriptions-item>
        <el-descriptions-item label="自定义域名">{{ tenant.custom_domain || '-' }}</el-descriptions-item>
        <el-descriptions-item label="状态">
          <el-tag :type="tenant.status === 'active' ? 'success' : 'danger'">
            {{ tenant.status === 'active' ? '活跃' : '未激活' }}
          </el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="套餐">{{ tenant.subscription_plan }}</el-descriptions-item>
        <el-descriptions-item label="总积分">{{ tenant.total_credits }}</el-descriptions-item>
        <el-descriptions-item label="已用积分">{{ tenant.used_credits }}</el-descriptions-item>
        <el-descriptions-item label="可用积分">{{ tenant.available_credits }}</el-descriptions-item>
        <el-descriptions-item label="创建时间">{{ tenant.created_at }}</el-descriptions-item>
      </el-descriptions>
    </el-card>
    
    <el-card style="margin-top: 20px;">
      <template #header>
        <span>成员列表</span>
      </template>
      
      <el-table :data="members" style="width: 100%">
        <el-table-column prop="user_id" label="用户ID" width="180" />
        <el-table-column prop="name" label="姓名" />
        <el-table-column prop="email" label="邮箱" />
        <el-table-column prop="pivot.role" label="角色" width="120">
          <template #default="{ row }">
            <el-tag :type="row.pivot.role === 'tenant_admin' ? 'warning' : 'info'">
              {{ row.pivot.role === 'tenant_admin' ? '管理员' : '普通用户' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="pivot.is_active" label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="row.pivot.is_active ? 'success' : 'danger'">
              {{ row.pivot.is_active ? '激活' : '未激活' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="pivot.joined_at" label="加入时间" width="180" />
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

const loading = ref(false)
const tenant = ref<any>({})
const members = ref([])

const fetchTenant = async () => {
  loading.value = true
  try {
    const response = await axios.get(`/api/v1/tenants/${route.params.id}`)
    tenant.value = response.data.data
  } catch (error) {
    console.error('获取租户详情失败:', error)
  } finally {
    loading.value = false
  }
}

const fetchMembers = async () => {
  try {
    const response = await axios.get(`/api/v1/tenants/${route.params.id}/members`)
    members.value = response.data.data
  } catch (error) {
    console.error('获取成员列表失败:', error)
  }
}

onMounted(() => {
  fetchTenant()
  fetchMembers()
})
</script>

<style scoped>
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
</style>
