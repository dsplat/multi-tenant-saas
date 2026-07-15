<template>
  <div class="page">
    <div class="page-header"><h2>积分总览</h2></div>
    <div class="panel">
      <div v-if="overview" class="stats-grid">
        <div class="stat-card"><div class="stat-value">{{ overview.total_tenants ?? '-' }}</div><div class="stat-label">租户总数</div></div>
        <div class="stat-card"><div class="stat-value">{{ overview.total_balance ?? '-' }}</div><div class="stat-label">总余额</div></div>
        <div class="stat-card"><div class="stat-value">{{ overview.total_recharged ?? '-' }}</div><div class="stat-label">总充值</div></div>
        <div class="stat-card"><div class="stat-value">{{ overview.total_consumed ?? '-' }}</div><div class="stat-label">总消耗</div></div>
      </div>

      <h3 style="margin: 24px 0 12px;">批量充值</h3>
      <form @submit.prevent="handleBatchRecharge" class="recharge-form">
        <div class="form-group"><label>租户 ID（逗号分隔）</label><input v-model="rechargeForm.tenant_ids" required placeholder="1001,1002,1003" /></div>
        <div class="form-group"><label>充值金额</label><input v-model.number="rechargeForm.amount" type="number" min="1" required /></div>
        <div class="form-group"><label>备注</label><input v-model="rechargeForm.remark" /></div>
        <button type="submit" class="primary-btn" :disabled="recharging">充值</button>
      </form>

      <div v-if="rechargeResult" class="result-box">
        <p>成功: {{ rechargeResult.succeeded ?? 0 }}，失败: {{ rechargeResult.failed ?? 0 }}</p>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const overview = ref<any>(null)
const rechargeForm = ref({ tenant_ids: '', amount: 0, remark: '' })
const recharging = ref(false)
const rechargeResult = ref<any>(null)

const fetchOverview = async () => { try { const r = await axios.get('/v1/admin/admin/billing/credits/overview'); overview.value = r.data.data || r.data } catch {} }

const handleBatchRecharge = async () => {
  recharging.value = true; rechargeResult.value = null
  try {
    const ids = rechargeForm.value.tenant_ids.split(',').map(s => parseInt(s.trim())).filter(Boolean)
    const r = await axios.post('/v1/admin/admin/billing/credits/batch-recharge', { tenant_ids: ids, amount: rechargeForm.value.amount, remark: rechargeForm.value.remark })
    rechargeResult.value = r.data.data || r.data; await fetchOverview()
  } catch (e: any) { rechargeResult.value = { error: e.response?.data?.message || e.message } } finally { recharging.value = false }
}

onMounted(fetchOverview)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.stats-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 16px; margin-bottom: 24px; }
.stat-card { background: var(--fill-color, #f5f7fa); border-radius: 8px; padding: 20px; text-align: center; }
.stat-value { font-size: 28px; font-weight: 600; color: var(--primary-color, #409eff); }
.stat-label { font-size: 13px; color: var(--text-color-secondary, #666); margin-top: 4px; }
.recharge-form { max-width: 400px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; box-sizing: border-box; }
.primary-btn { padding: 10px 24px; background: var(--primary-color, #409eff); color: #fff; border: none; border-radius: 6px; cursor: pointer; }
.primary-btn:disabled { opacity: 0.6; }
.result-box { margin-top: 16px; padding: 12px; background: #f5f5f5; border-radius: 6px; font-size: 13px; }
</style>
