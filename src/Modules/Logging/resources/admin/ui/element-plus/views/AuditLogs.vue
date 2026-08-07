<template>
  <div class="page">
    <div class="page-header"><h2>审计日志</h2></div>

    <el-card shadow="never">
      <el-empty v-if="!tenantStore.hasTenant" description="请先在页面右上角选择团队" />

      <template v-else>
        <div class="filter-bar">
          <el-select v-model="filters.action" placeholder="全部操作" clearable style="width: 160px" @change="loadLogs">
            <el-option v-for="a in actionOptions" :key="a" :label="a" :value="a" />
          </el-select>
          <el-select v-model="filters.resource_type" placeholder="全部资源" clearable style="width: 160px" @change="loadLogs">
            <el-option v-for="r in resourceTypeOptions" :key="r" :label="r" :value="r" />
          </el-select>
        </div>

        <el-table :data="logs" stripe style="width: 100%" empty-text="暂无日志">
          <el-table-column prop="created_at" label="时间" width="180" />
          <el-table-column label="用户" width="120">
            <template #default="{ row }">{{ row.user_name || row.user_id || '-' }}</template>
          </el-table-column>
          <el-table-column label="操作" width="100">
            <template #default="{ row }"><el-tag size="small">{{ row.action }}</el-tag></template>
          </el-table-column>
          <el-table-column label="资源类型" width="120">
            <template #default="{ row }">{{ row.resource_type || '-' }}</template>
          </el-table-column>
          <el-table-column label="资源ID">
            <template #default="{ row }">{{ row.resource_id || '-' }}</template>
          </el-table-column>
        </el-table>

        <el-pagination
          v-if="totalPages > 1"
          v-model:current-page="currentPage"
          :page-size="perPage"
          :total="totalPages * perPage"
          layout="prev, pager, next"
          style="margin-top: 16px; justify-content: center"
          @current-change="goPage"
        />
      </template>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted, watch } from 'vue'
import axios from 'axios'
// 租户上下文统一走头部团队选择器（tenantStore），页面内不再自建选择器
import { useTenantStore } from '@/admin/stores/tenant'

const tenantStore = useTenantStore()
const logs = ref<any[]>([])
const currentPage = ref(1)
const totalPages = ref(1)
const perPage = 20

const actionOptions = ref<string[]>([])
const resourceTypeOptions = ref<string[]>([])
const filters = reactive({ action: '', resource_type: '' })

const loadLogs = async () => {
  if (!tenantStore.hasTenant) return
  currentPage.value = 1
  try {
    const res = await axios.get(`/api/v1/tenants/${tenantStore.tenantId}/audit-logs`, {
      params: { page: 1, per_page: perPage, action: filters.action || undefined, resource_type: filters.resource_type || undefined },
    })
    logs.value = res.data.data || []
    totalPages.value = res.data.meta?.last_page || 1
    if (res.data.meta?.actions) actionOptions.value = res.data.meta.actions
    if (res.data.meta?.resource_types) resourceTypeOptions.value = res.data.meta.resource_types
  } catch {
    logs.value = []
  }
}

const goPage = async (page: number) => {
  if (!tenantStore.hasTenant) return
  try {
    const res = await axios.get(`/api/v1/tenants/${tenantStore.tenantId}/audit-logs`, {
      params: { page, per_page: perPage, action: filters.action || undefined, resource_type: filters.resource_type || undefined },
    })
    logs.value = res.data.data || []
    currentPage.value = page
    totalPages.value = res.data.meta?.last_page || 1
  } catch {}
}

onMounted(() => { if (tenantStore.hasTenant) loadLogs() })
watch(() => tenantStore.tenantId, () => { if (tenantStore.hasTenant) loadLogs(); else logs.value = [] })
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.filter-bar { display: flex; gap: 12px; margin-bottom: 16px; }
</style>
