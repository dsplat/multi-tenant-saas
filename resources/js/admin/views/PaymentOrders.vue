<template>
  <div class="payment-page">
    <div class="page-header"><h2>支付订单</h2></div>

    <div class="panel" style="margin-bottom: 16px;">
      <div class="tenant-select">
        <label>选择租户：</label>
        <select v-model="selectedTenantId" @change="loadOrders">
          <option value="">请选择</option>
          <option v-for="t in tenants" :key="t.tenant_id" :value="t.tenant_id">{{ t.name }}</option>
        </select>
      </div>

      <div v-if="selectedTenantId">
        <div class="toolbar">
          <button class="primary-btn" @click="showCreate = true">创建充值订单</button>
        </div>

        <table class="data-table">
          <thead>
            <tr><th>订单号</th><th>时间</th><th>金额</th><th>状态</th><th>描述</th></tr>
          </thead>
          <tbody>
            <tr v-for="o in orders" :key="o.order_no">
              <td>{{ o.order_no }}</td>
              <td>{{ o.created_at }}</td>
              <td>{{ o.amount }}</td>
              <td>
                <span :class="['badge', orderStatusClass(o.status)]">{{ orderStatusLabel(o.status) }}</span>
              </td>
              <td>{{ o.description || '-' }}</td>
            </tr>
            <tr v-if="orders.length === 0">
              <td colspan="5" class="empty-row">暂无订单</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="modal-backdrop" v-if="showCreate" @click="showCreate = false">
      <div class="modal-content" @click.stop>
        <h3>创建充值订单</h3>
        <form @submit.prevent="handleCreate">
          <div class="form-group">
            <label>金额</label>
            <input v-model.number="createForm.amount" type="number" min="1" step="0.01" required />
          </div>
          <div class="form-group">
            <label>描述</label>
            <input v-model="createForm.description" placeholder="充值说明" />
          </div>
          <div class="form-actions">
            <button type="button" class="btn-cancel" @click="showCreate = false">取消</button>
            <button type="submit" class="primary-btn">创建</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'

const tenants = ref<any[]>([])
const selectedTenantId = ref('')
const orders = ref<any[]>([])
const showCreate = ref(false)
const createForm = reactive({ amount: 0, description: '' })

const orderStatusClass = (s: string) => ({ paid: 'badge-success', pending: 'badge-warning', failed: 'badge-danger', cancelled: 'badge-info' }[s] || 'badge-info')
const orderStatusLabel = (s: string) => ({ paid: '已支付', pending: '待支付', failed: '失败', cancelled: '已取消' }[s] || s)

const fetchTenants = async () => {
  try {
    const res = await axios.get('/api/v1/tenants')
    tenants.value = res.data.data || []
  } catch {}
}

const loadOrders = async () => {
  if (!selectedTenantId.value) return
  try {
    const res = await axios.get(`/api/v1/tenants/${selectedTenantId.value}/payment-orders`)
    orders.value = res.data.data || []
  } catch {
    orders.value = []
  }
}

const handleCreate = async () => {
  try {
    await axios.post(`/api/v1/tenants/${selectedTenantId.value}/payment-orders`, createForm)
    showCreate.value = false
    createForm.amount = 0
    createForm.description = ''
    loadOrders()
  } catch (e: any) {
    alert(e.response?.data?.message || '创建失败')
  }
}

onMounted(fetchTenants)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.tenant-select { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
.tenant-select label { font-size: 14px; color: var(--text-color-secondary, #666); }
.tenant-select select { padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; min-width: 200px; }
.toolbar { margin-bottom: 16px; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.empty-row { text-align: center; color: var(--text-color-secondary, #999); padding: 24px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-success { background: #e8f5e9; color: #2e7d32; }
.badge-warning { background: #fff3e0; color: #e65100; }
.badge-danger { background: #fce4ec; color: #c62828; }
.badge-info { background: #eee; color: #666; }
.primary-btn { padding: 8px 16px; border: none; border-radius: 6px; background: var(--primary-color, #409eff); color: #fff; cursor: pointer; font-size: 13px; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 3000; }
.modal-content { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; width: 420px; }
.modal-content h3 { margin: 0 0 16px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 13px; box-sizing: border-box; }
.form-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 16px; }
.btn-cancel { padding: 8px 16px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; background: #fff; cursor: pointer; font-size: 13px; }
</style>
