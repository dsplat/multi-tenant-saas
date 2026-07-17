<template>
  <div class="page">
    <div class="page-header"><h2>支付订单</h2></div>

    <el-card shadow="never">
      <div class="filter-bar">
        <el-select v-model="selectedTenantId" placeholder="全部租户" clearable style="width: 200px" @change="fetchOrders">
          <el-option v-for="t in tenants" :key="t.tenant_id" :label="`${t.name} (${t.tenant_id})`" :value="t.tenant_id" />
        </el-select>
        <el-select v-model="statusFilter" placeholder="全部状态" clearable style="width: 140px" @change="fetchOrders">
          <el-option label="待支付" value="pending" />
          <el-option label="已支付" value="paid" />
          <el-option label="失败" value="failed" />
          <el-option label="已取消" value="cancelled" />
          <el-option label="已退款" value="refunded" />
        </el-select>
      </div>

      <el-table :data="orders" stripe style="width: 100%" empty-text="暂无订单">
        <el-table-column label="订单号" width="180">
          <template #default="{ row }"><span style="font-family: monospace; font-size: 12px">{{ row.order_no ?? row.id }}</span></template>
        </el-table-column>
        <el-table-column prop="tenant_id" label="租户" width="100" />
        <el-table-column label="金额" width="100">
          <template #default="{ row }">¥{{ row.amount }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="支付方式" width="120">
          <template #default="{ row }">{{ row.payment_method || row.driver || '-' }}</template>
        </el-table-column>
        <el-table-column label="描述" show-overflow-tooltip>
          <template #default="{ row }">{{ row.description || '-' }}</template>
        </el-table-column>
        <el-table-column label="创建时间" width="160">
          <template #default="{ row }">{{ formatDate(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="80">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="viewDetail(row)">详情</el-button>
          </template>
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
    </el-card>

    <el-dialog v-model="showDetail" title="订单详情" width="500px">
      <el-descriptions v-if="detailOrder" :column="1" border>
        <el-descriptions-item label="订单号">{{ detailOrder.order_no ?? detailOrder.id }}</el-descriptions-item>
        <el-descriptions-item label="租户ID">{{ detailOrder.tenant_id }}</el-descriptions-item>
        <el-descriptions-item label="金额">¥{{ detailOrder.amount }}</el-descriptions-item>
        <el-descriptions-item label="状态">
          <el-tag :type="statusType(detailOrder.status)" size="small">{{ statusLabel(detailOrder.status) }}</el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="支付方式">{{ detailOrder.payment_method || detailOrder.driver || '-' }}</el-descriptions-item>
        <el-descriptions-item label="描述">{{ detailOrder.description || '-' }}</el-descriptions-item>
        <el-descriptions-item label="创建时间">{{ detailOrder.created_at }}</el-descriptions-item>
        <el-descriptions-item label="支付时间">{{ detailOrder.paid_at || '-' }}</el-descriptions-item>
      </el-descriptions>
      <template #footer>
        <el-button @click="showDetail = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const ADMIN_API = '/api/v1/admin/payments/orders'
const tenants = ref<any[]>([])
const orders = ref<any[]>([])
const selectedTenantId = ref('')
const statusFilter = ref('')
const currentPage = ref(1)
const totalPages = ref(1)
const perPage = 20
const detailOrder = ref<any>(null)
const showDetail = ref(false)

const statusType = (s: string) => ({ paid: 'success', pending: 'warning', failed: 'danger', cancelled: 'info', refunded: 'warning' }[s] || 'info')
const statusLabel = (s: string) => ({ paid: '已支付', pending: '待支付', failed: '失败', cancelled: '已取消', refunded: '已退款' }[s] || s)
const formatDate = (d: string) => d ? d.substring(0, 16) : '-'

const fetchTenants = async () => {
  try {
    const r = await axios.get('/api/v1/tenants', { params: { per_page: 100 } })
    tenants.value = r.data.data || []
  } catch {}
}

const fetchOrders = async (page = 1) => {
  try {
    const params: any = { page, per_page: perPage }
    if (selectedTenantId.value) params.tenant_id = selectedTenantId.value
    if (statusFilter.value) params.status = statusFilter.value
    const r = await axios.get(ADMIN_API, { params })
    orders.value = r.data.data || []
    totalPages.value = r.data.meta?.last_page ?? r.data.last_page ?? 1
    currentPage.value = page
  } catch {
    orders.value = []
  }
}

const goPage = (p: number) => fetchOrders(p)

const viewDetail = async (o: any) => {
  try {
    const r = await axios.get(`${ADMIN_API}/${o.order_no ?? o.id}`)
    detailOrder.value = r.data.data || o
  } catch {
    detailOrder.value = o
  }
  showDetail.value = true
}

onMounted(() => {
  fetchTenants()
  fetchOrders()
})
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.filter-bar { display: flex; gap: 12px; margin-bottom: 16px; }
</style>
