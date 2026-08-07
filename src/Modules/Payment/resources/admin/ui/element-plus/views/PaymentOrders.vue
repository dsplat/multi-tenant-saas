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
        <el-table-column label="操作" width="190">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="viewDetail(row)">详情</el-button>
            <el-button v-if="row.status === 'pending'" link type="success" size="small" @click="openMarkPaid(row)">补单</el-button>
            <el-button v-if="row.status === 'pending'" link type="danger" size="small" @click="closeOrder(row)">关单</el-button>
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
        <el-descriptions-item label="交易号">{{ detailOrder.transaction_id || '-' }}</el-descriptions-item>
        <el-descriptions-item v-if="detailOrder.extra" label="扩展信息">
          <pre style="margin: 0; font-size: 12px">{{ JSON.stringify(detailOrder.extra, null, 2) }}</pre>
        </el-descriptions-item>
      </el-descriptions>
      <template #footer>
        <el-button @click="showDetail = false">关闭</el-button>
      </template>
    </el-dialog>

    <!-- 手动补单弹窗 -->
    <el-dialog v-model="showMarkPaid" title="手动补单（标记为已支付）" width="460px">
      <el-alert type="warning" :closable="false" style="margin-bottom: 16px"
        title="用于线下收款/回调丢失场景，确认后订单立即标记为已支付" />
      <el-form label-width="90px">
        <el-form-item label="订单">
          <span style="font-family: monospace">{{ markPaidTarget?.order_no }}（¥{{ markPaidTarget?.amount }}）</span>
        </el-form-item>
        <el-form-item label="交易号">
          <el-input v-model="markPaidForm.transaction_id" placeholder="留空则自动生成 MANUAL- 前缀" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="markPaidForm.note" type="textarea" :rows="2" placeholder="补单原因（如：线下转账已到账）" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="showMarkPaid = false">取消</el-button>
        <el-button type="primary" :loading="operating" @click="submitMarkPaid">确认补单</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'

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
const showMarkPaid = ref(false)
const markPaidTarget = ref<any>(null)
const markPaidForm = reactive({ transaction_id: '', note: '' })
const operating = ref(false)

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
    const r = await axios.get(`${ADMIN_API}/${o.id}`)
    detailOrder.value = r.data.data || o
  } catch {
    detailOrder.value = o
  }
  showDetail.value = true
}

const openMarkPaid = (o: any) => {
  markPaidTarget.value = o
  markPaidForm.transaction_id = ''
  markPaidForm.note = ''
  showMarkPaid.value = true
}

const submitMarkPaid = async () => {
  operating.value = true
  try {
    const r = await axios.post(`${ADMIN_API}/${markPaidTarget.value.id}/mark-paid`, markPaidForm)
    ElMessage.success(r.data.message || '补单成功')
    showMarkPaid.value = false
    fetchOrders(currentPage.value)
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '补单失败')
  } finally {
    operating.value = false
  }
}

const closeOrder = async (o: any) => {
  try {
    const { value } = await ElMessageBox.prompt(`确认关闭订单 ${o.order_no}？关闭后不可恢复。`, '关单', {
      confirmButtonText: '确认关单',
      cancelButtonText: '取消',
      type: 'warning',
      inputPlaceholder: '关单原因（可选）',
      inputValidator: () => true,
    })
    await axios.post(`${ADMIN_API}/${o.id}/close`, { note: value || '' })
    ElMessage.success('订单已关闭')
    fetchOrders(currentPage.value)
  } catch (e: any) {
    if (e === 'cancel' || e?.message === 'cancel') return
    ElMessage.error(e.response?.data?.message || '关单失败')
  }
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
