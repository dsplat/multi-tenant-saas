<template>
  <div class="page">
    <div class="page-header"><h2>商业体订单总览</h2></div>

    <el-card shadow="never">
      <CrudTable ref="tableRef" fetch-api="/api/v1/admin/commerce/orders" :columns="columns"
        :search-fields="searchFields">
        <template #toolbar>
          <el-button type="warning" :loading="retrying" @click="retryFailed">重试失败履约</el-button>
        </template>
        <template #col-status="{ row }">
          <el-tag :type="statusTag(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
        </template>
        <template #col-amount="{ row }">¥{{ Number(row.amount).toFixed(2) }}</template>
        <template #col-created_at="{ row }">{{ formatDate(row.created_at) }}</template>
      </CrudTable>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'
import CrudTable from '@multi-tenant-saas/ui-core/components/CrudTable.vue'

const tableRef = ref()
const retrying = ref(false)

const columns = [
  { prop: 'order_no', label: '订单号', minWidth: 180 },
  { prop: 'tenant_id', label: '租户ID', width: 110 },
  { prop: 'amount', label: '金额', width: 110 },
  { prop: 'status', label: '状态', width: 110 },
  { prop: 'paid_at', label: '支付时间', width: 160 },
  { prop: 'created_at', label: '创建时间', width: 160 },
]

const searchFields = [
  {
    prop: 'status', label: '状态', type: 'select' as const, options: [
      { label: '待支付', value: 'pending' },
      { label: '已支付', value: 'paid' },
      { label: '已履约', value: 'fulfilled' },
      { label: '部分失败', value: 'partial_failed' },
      { label: '已取消', value: 'cancelled' },
      { label: '已退款', value: 'refunded' },
    ],
  },
]

const statusLabel = (s: string) =>
  ({ pending: '待支付', paid: '已支付', fulfilled: '已履约', partial_failed: '部分失败', cancelled: '已取消', refunded: '已退款' }[s] || s)
const statusTag = (s: string) =>
  ({ pending: 'warning', paid: 'primary', fulfilled: 'success', partial_failed: 'danger', cancelled: 'info', refunded: 'info' }[s] || 'info')
const formatDate = (d: string) => d ? d.substring(0, 16) : '-'

const retryFailed = async () => {
  retrying.value = true
  try {
    const r = await axios.post('/api/v1/admin/commerce/retry')
    ElMessage.success(`已触发重试：${r.data?.data?.retried ?? r.data?.retried ?? 0} 条`)
    tableRef.value?.reload()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '重试失败')
  } finally {
    retrying.value = false
  }
}
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
</style>
