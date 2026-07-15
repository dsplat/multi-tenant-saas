<template>
  <div class="page">
    <div class="page-header"><h2>支付订单</h2></div>

    <div class="panel">
      <div class="filter-bar">
        <select v-model="selectedTenantId" @change="fetchOrders">
          <option value="">全部租户</option>
          <option v-for="t in tenants" :key="t.tenant_id" :value="t.tenant_id">{{ t.name }} ({{ t.tenant_id }})</option>
        </select>
        <select v-model="statusFilter" @change="fetchOrders">
          <option value="">全部状态</option>
          <option value="pending">待支付</option>
          <option value="paid">已支付</option>
          <option value="failed">失败</option>
          <option value="cancelled">已取消</option>
          <option value="refunded">已退款</option>
        </select>
      </div>

      <table class="data-table">
        <thead><tr><th>订单号</th><th>租户</th><th>金额</th><th>状态</th><th>支付方式</th><th>描述</th><th>创建时间</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="o in orders" :key="o.order_no ?? o.id">
            <td class="mono">{{ o.order_no ?? o.id }}</td>
            <td>{{ o.tenant_id }}</td>
            <td>¥{{ o.amount }}</td>
            <td><span :class="['badge', statusClass(o.status)]">{{ statusLabel(o.status) }}</span></td>
            <td>{{ o.payment_method || o.driver || '-' }}</td>
            <td>{{ o.description || '-' }}</td>
            <td>{{ formatDate(o.created_at) }}</td>
            <td><button class="link-btn" @click="viewDetail(o)">详情</button></td>
          </tr>
          <tr v-if="orders.length === 0"><td colspan="8" class="empty-row">暂无订单</td></tr>
        </tbody>
      </table>

      <div v-if="totalPages > 1" class="pagination">
        <button :disabled="currentPage <= 1" @click="goPage(currentPage - 1)">上一页</button>
        <span>{{ currentPage }} / {{ totalPages }}</span>
        <button :disabled="currentPage >= totalPages" @click="goPage(currentPage + 1)">下一页</button>
      </div>
    </div>

    <div class="modal-backdrop" v-if="detailOrder" @click="detailOrder = null">
      <div class="modal-content" @click.stop>
        <h3>订单详情</h3>
        <div class="detail-grid">
          <div class="detail-item"><span class="label">订单号</span><span>{{ detailOrder.order_no ?? detailOrder.id }}</span></div>
          <div class="detail-item"><span class="label">租户ID</span><span>{{ detailOrder.tenant_id }}</span></div>
          <div class="detail-item"><span class="label">金额</span><span>¥{{ detailOrder.amount }}</span></div>
          <div class="detail-item"><span class="label">状态</span><span :class="['badge', statusClass(detailOrder.status)]">{{ statusLabel(detailOrder.status) }}</span></div>
          <div class="detail-item"><span class="label">支付方式</span><span>{{ detailOrder.payment_method || detailOrder.driver || '-' }}</span></div>
          <div class="detail-item"><span class="label">描述</span><span>{{ detailOrder.description || '-' }}</span></div>
          <div class="detail-item"><span class="label">创建时间</span><span>{{ detailOrder.created_at }}</span></div>
          <div class="detail-item"><span class="label">支付时间</span><span>{{ detailOrder.paid_at || '-' }}</span></div>
        </div>
        <div class="form-actions"><button @click="detailOrder = null">关闭</button></div>
      </div>
    </div>
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

const statusClass = (s: string) => ({ paid: 'badge-success', pending: 'badge-warning', failed: 'badge-danger', cancelled: 'badge-info', refunded: 'badge-warning' }[s] || 'badge-info')
const statusLabel = (s: string) => ({ paid: '已支付', pending: '待支付', failed: '失败', cancelled: '已取消', refunded: '已退款' }[s] || s)
const formatDate = (d: string) => d ? d.substring(0, 16) : '-'

const fetchTenants = async () => { try { const r = await axios.get('/api/v1/tenants', { params: { per_page: 100 } }); tenants.value = r.data.data || [] } catch {} }

const fetchOrders = async (page = 1) => {
  try {
    const params: any = { page, per_page: perPage }
    if (selectedTenantId.value) params.tenant_id = selectedTenantId.value
    if (statusFilter.value) params.status = statusFilter.value
    const r = await axios.get(ADMIN_API, { params })
    orders.value = r.data.data || []
    totalPages.value = r.data.meta?.last_page ?? r.data.last_page ?? 1
    currentPage.value = page
  } catch { orders.value = [] }
}

const goPage = (p: number) => fetchOrders(p)

const viewDetail = async (o: any) => {
  try {
    const r = await axios.get(`${ADMIN_API}/${o.order_no ?? o.id}`)
    detailOrder.value = r.data.data || o
  } catch { detailOrder.value = o }
}

onMounted(() => { fetchTenants(); fetchOrders() })
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.filter-bar { display: flex; gap: 12px; margin-bottom: 16px; }
.filter-bar select { padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; min-width: 160px; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.mono { font-family: monospace; font-size: 12px; }
.empty-row { text-align: center; color: var(--text-color-secondary, #999); padding: 24px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-success { background: #e6ffed; color: #52c41a; }
.badge-warning { background: #fff7e6; color: #fa8c16; }
.badge-danger { background: #fff1f0; color: #f5222d; }
.badge-info { background: #eee; color: #666; }
.link-btn { background: none; border: none; color: var(--primary-color, #409eff); cursor: pointer; font-size: 13px; padding: 0 4px; }
.pagination { display: flex; align-items: center; justify-content: center; gap: 16px; margin-top: 16px; }
.pagination button { padding: 6px 14px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; background: #fff; cursor: pointer; font-size: 13px; }
.pagination button:disabled { opacity: 0.4; cursor: not-allowed; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-content { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; min-width: 460px; }
.modal-content h3 { margin: 0 0 20px; }
.detail-grid { display: grid; gap: 12px; }
.detail-item { display: flex; gap: 12px; padding: 8px 0; border-bottom: 1px solid var(--border-color, #eee); font-size: 14px; }
.detail-item .label { color: var(--text-color-secondary, #666); min-width: 80px; }
.form-actions { display: flex; justify-content: flex-end; margin-top: 20px; }
.form-actions button { padding: 8px 16px; border-radius: 6px; border: 1px solid var(--border-color, #ddd); background: #fff; cursor: pointer; }
</style>
