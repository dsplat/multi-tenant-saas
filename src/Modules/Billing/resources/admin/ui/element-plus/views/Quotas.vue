<template>
  <div class="page">
    <div class="page-header"><h2>配额管理</h2></div>

    <el-card shadow="never">
      <el-empty v-if="!tenantStore.hasTenant" description="请先在页面右上角选择团队" />

      <el-table v-else :data="quotas" stripe style="width: 100%" empty-text="暂无配额数据">
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
import { ref, onMounted, watch } from 'vue'
import axios from 'axios'
// 租户上下文统一走头部团队选择器（tenantStore），页面内不再自建选择器
import { useTenantStore } from '@/admin/stores/tenant'

const tenantStore = useTenantStore()
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

const loadQuotas = async () => {
  if (!tenantStore.hasTenant) return
  try { const res = await axios.get(`/api/v1/tenants/${tenantStore.tenantId}/quotas`); quotas.value = res.data.data || [] }
  catch { quotas.value = [] }
}

onMounted(() => { if (tenantStore.hasTenant) loadQuotas() })
watch(() => tenantStore.tenantId, () => { if (tenantStore.hasTenant) loadQuotas(); else quotas.value = [] })
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
</style>
