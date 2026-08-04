<template>
  <div class="page">
    <div class="page-header">
      <el-button text @click="$router.back()">← 返回</el-button>
      <h2 style="display: inline-block; margin-left: 8px">订单详情</h2>
    </div>

    <el-card v-loading="loading" shadow="never">
      <el-descriptions :column="2" border>
        <el-descriptions-item label="订单号">{{ order.order_no }}</el-descriptions-item>
        <el-descriptions-item label="状态">
          <el-tag :type="statusTag(order.status)" size="small">{{ statusLabel(order.status) }}</el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="金额">¥{{ Number(order.amount || 0).toFixed(2) }}</el-descriptions-item>
        <el-descriptions-item label="支付时间">{{ order.paid_at || '—' }}</el-descriptions-item>
        <el-descriptions-item label="创建时间">{{ order.created_at || '—' }}</el-descriptions-item>
        <el-descriptions-item label="关联支付单">{{ order.payment_order_id || '—' }}</el-descriptions-item>
      </el-descriptions>

      <template v-if="order.items?.length">
        <h3 style="margin: 20px 0 12px">订单明细</h3>
        <el-table :data="order.items" border stripe>
          <el-table-column prop="sku_name" label="商品" min-width="180" />
          <el-table-column prop="quantity" label="数量" width="90" />
          <el-table-column label="单价" width="110">
            <template #default="{ row }">¥{{ Number(row.unit_price || 0).toFixed(2) }}</template>
          </el-table-column>
          <el-table-column label="小计" width="110">
            <template #default="{ row }">¥{{ Number(row.subtotal || 0).toFixed(2) }}</template>
          </el-table-column>
        </el-table>
      </template>

      <div v-if="order.status === 'pending'" style="margin-top: 20px">
        <el-button type="primary" @click="pay">去支付</el-button>
        <el-button type="danger" plain @click="cancel">取消订单</el-button>
      </div>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute } from 'vue-router'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'

const route = useRoute()
const loading = ref(false)
const order = ref<any>({})

const statusLabel = (s: string) =>
  ({ pending: '待支付', paid: '已支付', fulfilled: '已履约', partial_failed: '部分失败', cancelled: '已取消', refunded: '已退款' }[s] || s)
const statusTag = (s: string) =>
  ({ pending: 'warning', paid: 'primary', fulfilled: 'success', partial_failed: 'danger', cancelled: 'info', refunded: 'info' }[s] || 'info')

const loadOrder = async () => {
  loading.value = true
  try {
    const r = await axios.get(`/api/v1/commerce/orders/${route.params.id}`)
    order.value = r.data?.data || {}
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '加载订单失败')
  } finally {
    loading.value = false
  }
}

const pay = async () => {
  try {
    const r = await axios.post(`/api/v1/commerce/orders/${order.value.order_id}/pay`)
    const payUrl = r.data?.data?.pay_url
    if (payUrl) window.location.href = payUrl
    else { ElMessage.success('支付已发起'); loadOrder() }
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '支付发起失败')
  }
}

const cancel = async () => {
  try {
    await ElMessageBox.confirm('确定取消该订单？', '提示', { type: 'warning' })
    await axios.post(`/api/v1/commerce/orders/${order.value.order_id}/cancel`)
    ElMessage.success('订单已取消')
    loadOrder()
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '取消失败')
  }
}

onMounted(loadOrder)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
</style>
