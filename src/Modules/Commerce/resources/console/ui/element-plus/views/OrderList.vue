<template>
  <div class="page">
    <div class="page-header"><h2>我的订单</h2></div>

    <el-card shadow="never">
      <CrudTable ref="tableRef" fetch-api="/api/v1/commerce/orders" :columns="columns"
        :search-fields="searchFields">
        <template #col-status="{ row }">
          <el-tag :type="statusTag(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
        </template>
        <template #col-amount="{ row }">¥{{ Number(row.amount).toFixed(2) }}</template>
        <template #col-created_at="{ row }">{{ formatDate(row.created_at) }}</template>
        <template #actions="{ row }">
          <el-button size="small" @click="goDetail(row)">详情</el-button>
          <el-button v-if="row.status === 'pending'" size="small" type="primary"
            @click="pay(row)">支付</el-button>
          <el-button v-if="row.status === 'pending'" size="small" type="danger"
            @click="cancel(row)">取消</el-button>
        </template>
      </CrudTable>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'
import CrudTable from '@multi-tenant-saas/ui-core/components/CrudTable.vue'

const router = useRouter()
const tableRef = ref()

const columns = [
  { prop: 'order_no', label: '订单号', minWidth: 180 },
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

const goDetail = (row: any) => {
  router.push({ name: 'commerce-console-order-detail', params: { id: row.order_id } })
}

const pay = async (row: any) => {
  try {
    const r = await axios.post(`/api/v1/commerce/orders/${row.order_id}/pay`)
    const payUrl = r.data?.data?.pay_url
    if (payUrl) {
      window.location.href = payUrl
    } else {
      ElMessage.success('支付已发起')
      tableRef.value?.reload()
    }
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '支付发起失败')
  }
}

const cancel = async (row: any) => {
  try {
    await ElMessageBox.confirm('确定取消该订单？', '提示', { type: 'warning' })
    await axios.post(`/api/v1/commerce/orders/${row.order_id}/cancel`)
    ElMessage.success('订单已取消')
    tableRef.value?.reload()
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '取消失败')
  }
}
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
</style>
