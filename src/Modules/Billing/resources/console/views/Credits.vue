<template>
  <div class="page">
    <div class="page-header"><h2>积分管理</h2></div>

    <div class="stat-grid">
      <div class="stat-card"><div class="stat-label">总积分</div><div class="stat-value">{{ balance.total }}</div></div>
      <div class="stat-card"><div class="stat-label">已使用</div><div class="stat-value">{{ balance.used }}</div></div>
      <div class="stat-card"><div class="stat-label">可用积分</div><div class="stat-value">{{ balance.available }}</div></div>
    </div>

    <div class="panel">
      <h3>交易记录</h3>
      <table class="data-table">
        <thead><tr><th>时间</th><th>类型</th><th>金额</th><th>余额</th><th>描述</th></tr></thead>
        <tbody>
          <tr v-for="t in transactions" :key="t.id ?? t.credit_transaction_id">
            <td>{{ formatDate(t.created_at) }}</td>
            <td><span :class="['badge', typeClass(t.type ?? t.transaction_type)]">{{ typeLabel(t.type ?? t.transaction_type) }}</span></td>
            <td :class="{ 'text-green': (t.amount ?? 0) > 0, 'text-red': (t.amount ?? 0) < 0 }">{{ (t.amount ?? 0) > 0 ? '+' : '' }}{{ t.amount }}</td>
            <td>{{ t.balance_after ?? t.balance ?? '-' }}</td>
            <td>{{ t.description ?? t.remark ?? '-' }}</td>
          </tr>
          <tr v-if="transactions.length === 0"><td colspan="5" class="empty-row">暂无交易记录</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'
import { useUserStore } from '@stores/user'

const userStore = useUserStore()
const balance = reactive({ total: 0, used: 0, available: 0 })
const transactions = ref<any[]>([])
const formatDate = (d: string) => d ? d.substring(0, 16) : '-'
const typeClass = (t: string) => ({ recharge: 'badge-success', consume: 'badge-danger', gift: 'badge-info', refund: 'badge-warning', credit: 'badge-success', debit: 'badge-danger' }[t] || 'badge-info')
const typeLabel = (t: string) => ({ recharge: '充值', consume: '消费', gift: '赠送', refund: '退款', credit: '充值', debit: '消费' }[t] || t)

const fetchCredits = async () => {
  try {
    const r = await axios.get(`/api/v1/tenants/${userStore.tenantId}/credits`)
    const data = r.data.data || r.data
    balance.total = data.total ?? data.balance ?? 0
    balance.used = data.used ?? data.consumed ?? 0
    balance.available = data.available ?? data.balance ?? 0
    transactions.value = data.transactions ?? data.history ?? []
  } catch {}
}

onMounted(fetchCredits)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.stat-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 20px; }
.stat-card { background: var(--bg-color, #fff); border-radius: 8px; padding: 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.stat-label { font-size: 13px; color: var(--text-color-secondary, #999); margin-bottom: 8px; }
.stat-value { font-size: 28px; font-weight: 600; color: var(--link-color); }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.panel h3 { margin: 0 0 16px; font-size: 15px; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.empty-row { text-align: center; color: var(--text-color-secondary, #999); padding: 24px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-success { background: var(--badge-success-bg); color: var(--badge-success-fg); }
.badge-danger { background: var(--badge-danger-bg); color: var(--badge-danger-fg); }
.badge-info { background: var(--badge-info-bg); color: var(--badge-info-fg); }
.badge-warning { background: var(--badge-warning-bg); color: var(--badge-warning-fg); }
.text-green { color: var(--badge-success-fg); }
.text-red { color: var(--link-danger); }
</style>
