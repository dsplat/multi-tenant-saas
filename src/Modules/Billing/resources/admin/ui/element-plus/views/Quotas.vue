<template>
  <div class="page">
    <div class="page-header"><h2>配额管理</h2></div>

    <el-card shadow="never">
      <div class="tenant-select">
        <span>选择租户：</span>
        <el-select v-model="selectedTenantId" placeholder="请选择" style="width: 240px" @change="loadQuotas">
          <el-option v-for="t in tenants" :key="t.tenant_id" :label="t.name" :value="t.tenant_id" />
        </el-select>
      </div>

      <el-table v-if="selectedTenantId" :data="quotas" stripe style="width: 100%" empty-text="暂无配额数据">
        <el-table-column prop="resource_type" label="资源类型" />
        <el-table-column label="限制" width="100">
          <template #default="{ row }">{{ row.limit === -1 ? '无限制' : row.limit }}</template>
        </el-table-column>
        <el-table-column prop="used" label="已用" width="80" />
        <el-table-column label="剩余" width="80">
          <template #default="{ row }">{{ row.limit === -1 ? '-' : row.remaining }}</template>
        </el-table-column>
        <el-table-column label="使用情况" width="200">
          <template #default="{ row }">
            <el-progress v-if="row.limit !== -1" :percentage="progressPercent(row)" :status="progressStatus(row)" :stroke-width="8" />
            <el-tag v-else size="small">无限制</el-tag>
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const tenants = ref<any[]>([])
const selectedTenantId = ref('')
const quotas = ref<any[]>([])

const progressPercent = (q: any) => {
  if (!q.limit || q.limit <= 0) return 0
  return Math.min(100, Math.round((q.used / q.limit) * 100))
}

const progressStatus = (q: any) => {
  const pct = progressPercent(q)
  if (pct >= 90) return 'exception'
  if (pct >= 70) return 'warning'
  return 'success'
}

const fetchTenants = async () => { try { const res = await axios.get('/api/v1/tenants'); tenants.value = res.data.data || [] } catch {} }

const loadQuotas = async () => {
  if (!selectedTenantId.value) return
  try { const res = await axios.get(`/api/v1/tenants/${selectedTenantId.value}/quotas`); quotas.value = res.data.data || [] }
  catch { quotas.value = [] }
}

onMounted(fetchTenants)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.tenant-select { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
</style>
