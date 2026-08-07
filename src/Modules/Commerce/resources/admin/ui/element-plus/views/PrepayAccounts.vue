<template>
  <div class="page">
    <div class="page-header">
      <h2>预存货款账户</h2>
      <p class="page-desc">
        租户向平台预存货款（预收账款负债），供货结算时扣款确认收入；不允许负余额，线下到账后人工记账
      </p>
    </div>

    <el-row :gutter="16" class="summary-row">
      <el-col :span="6"><el-card shadow="never"><div class="stat-label">开户租户数</div><div class="stat-value">{{ summary.total_tenants || 0 }}</div></el-card></el-col>
      <el-col :span="6"><el-card shadow="never"><div class="stat-label">预存余额合计（负债）</div><div class="stat-value">¥{{ fen(summary.total_balance) }}</div></el-card></el-col>
      <el-col :span="6"><el-card shadow="never"><div class="stat-label">累计充值</div><div class="stat-value">¥{{ fen(summary.total_recharged) }}</div></el-card></el-col>
      <el-col :span="6"><el-card shadow="never"><div class="stat-label">累计结算确认</div><div class="stat-value">¥{{ fen(summary.total_consumed) }}</div></el-card></el-col>
    </el-row>

    <el-card shadow="never">
      <CrudTable ref="tableRef" fetch-api="/api/v1/admin/commerce/prepay-accounts"
        :columns="columns" :search-fields="searchFields" :extra-params="{ per_page: 100 }"
        :actions-width="180" @loaded="loadSummary">
        <template #toolbar>
          <el-button type="primary" @click="openRecharge(null)">新租户充值</el-button>
        </template>
        <template #col-tenant="{ row }">{{ row.tenant?.name || row.tenant_id }}</template>
        <template #col-balance="{ row }">
          <el-tag v-if="row.balance <= 10000" type="danger" size="small" effect="plain">¥{{ fen(row.balance) }}</el-tag>
          <span v-else>¥{{ fen(row.balance) }}</span>
        </template>
        <template #col-total_recharged="{ row }">¥{{ fen(row.total_recharged) }}</template>
        <template #col-total_consumed="{ row }">¥{{ fen(row.total_consumed) }}</template>
        <template #actions="{ row }">
          <el-button size="small" type="primary" @click="openRecharge(row)">充值</el-button>
          <el-button size="small" @click="openHistory(row)">流水</el-button>
        </template>
      </CrudTable>
    </el-card>

    <!-- 充值弹窗 -->
    <el-dialog v-model="rechargeVisible" title="预存货款充值" width="440px">
      <el-form label-width="90px">
        <el-form-item label="租户ID">
          <el-input-number v-if="!rechargeForm.lockTenant" v-model="rechargeForm.tenantId" :min="1"
            :controls="false" style="width: 100%" placeholder="首次充值自动开户" />
          <el-input v-else :model-value="rechargeForm.tenantName" disabled />
        </el-form-item>
        <el-form-item label="金额（元）">
          <el-input-number v-model="rechargeForm.amountYuan" :min="0.01" :precision="2" :step="100" style="width: 100%" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="rechargeForm.note" placeholder="如：银行转账单号 / 到账日期" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="rechargeVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="doRecharge">确认充值</el-button>
      </template>
    </el-dialog>

    <!-- 流水抽屉 -->
    <el-drawer v-model="historyVisible" :title="`预存流水 — ${historyTenantName}`" size="52%">
      <div class="drawer-balance">
        当前余额：<b>¥{{ fen(historyAccount?.balance) }}</b>
        <span class="muted">（累计充值 ¥{{ fen(historyAccount?.total_recharged) }} / 累计结算 ¥{{ fen(historyAccount?.total_consumed) }}）</span>
      </div>
      <el-table :data="historyRows" v-loading="historyLoading" border stripe size="small">
        <el-table-column prop="created_at" label="时间" width="160" />
        <el-table-column label="类型" width="90">
          <template #default="{ row }">
            <el-tag :type="txTag(row.type)" size="small">{{ txLabel(row.type) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="金额" width="110">
          <template #default="{ row }">
            <span :class="row.amount >= 0 ? 'amount-pos' : 'amount-neg'">
              {{ row.amount >= 0 ? '+' : '' }}¥{{ fen(row.amount) }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="余额" width="110">
          <template #default="{ row }">¥{{ fen(row.balance_after) }}</template>
        </el-table-column>
        <el-table-column prop="description" label="说明" min-width="180" show-overflow-tooltip />
        <el-table-column prop="related_id" label="业务引用" width="140" show-overflow-tooltip />
      </el-table>
    </el-drawer>
  </div>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'
import CrudTable from '@multi-tenant-saas/ui-core/components/CrudTable.vue'

const tableRef = ref()
const summary = ref<any>({})

const columns = [
  { prop: 'tenant_id', label: '租户ID', width: 120 },
  { prop: 'tenant', label: '租户', minWidth: 160 },
  { prop: 'balance', label: '预存余额', width: 130 },
  { prop: 'total_recharged', label: '累计充值', width: 130 },
  { prop: 'total_consumed', label: '累计结算', width: 130 },
  { prop: 'last_warning_at', label: '上次低额告警', width: 160 },
  { prop: 'created_at', label: '开户时间', width: 160 },
]

const searchFields = [{ prop: 'tenant_id', label: '租户ID' }]

// 分 → 元展示（负数保留符号）
const fen = (v: any) => {
  const n = Number(v || 0) / 100
  return n.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
}

const loadSummary = async () => {
  try {
    const res = await axios.get('/api/v1/admin/commerce/prepay-accounts', { params: { per_page: 1 } })
    summary.value = res.data?.summary || {}
  } catch { /* 忽略 */ }
}

// ===== 充值 =====
const rechargeVisible = ref(false)
const submitting = ref(false)
const rechargeForm = reactive({ tenantId: 0, tenantName: '', amountYuan: 100, note: '', lockTenant: false })

const openRecharge = (row: any) => {
  rechargeForm.lockTenant = !!row
  rechargeForm.tenantId = row?.tenant_id || 0
  rechargeForm.tenantName = row ? `${row.tenant?.name || ''}（${row.tenant_id}）` : ''
  rechargeForm.amountYuan = 100
  rechargeForm.note = ''
  rechargeVisible.value = true
}

const doRecharge = async () => {
  if (!rechargeForm.tenantId) {
    ElMessage.warning('请输入租户ID')
    return
  }
  submitting.value = true
  try {
    await axios.post('/api/v1/admin/commerce/prepay/recharge', {
      tenant_id: rechargeForm.tenantId,
      amount: Math.round(rechargeForm.amountYuan * 100),
      note: rechargeForm.note || undefined,
    })
    ElMessage.success('充值成功')
    rechargeVisible.value = false
    tableRef.value?.reload()
    loadSummary()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '充值失败')
  } finally {
    submitting.value = false
  }
}

// ===== 流水 =====
const historyVisible = ref(false)
const historyLoading = ref(false)
const historyRows = ref<any[]>([])
const historyAccount = ref<any>(null)
const historyTenantName = ref('')

const openHistory = async (row: any) => {
  historyTenantName.value = row.tenant?.name || String(row.tenant_id)
  historyVisible.value = true
  historyLoading.value = true
  try {
    const res = await axios.get('/api/v1/admin/commerce/prepay/transactions', {
      params: { tenant_id: row.tenant_id, per_page: 50 },
    })
    historyAccount.value = res.data?.data?.account || null
    historyRows.value = res.data?.data?.transactions || []
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '流水加载失败')
  } finally {
    historyLoading.value = false
  }
}

const txLabel = (t: string) =>
  ({ recharge: '充值', consume: '结算', refund: '补偿', release: '退还', gift: '赠送', transfer: '转账', expire: '过期' }[t] || t)
const txTag = (t: string) =>
  ({ recharge: 'success', consume: 'warning', refund: 'info', release: 'info' }[t] || '')
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-desc { color: var(--text-color-secondary, #64748b); font-size: 13px; margin-top: 4px; }
.summary-row { margin-bottom: 16px; }
.stat-label { font-size: 12px; color: var(--text-color-secondary, #64748b); }
.stat-value { font-size: 22px; font-weight: 600; margin-top: 6px; }
.drawer-balance { margin-bottom: 12px; font-size: 14px; }
.muted { color: var(--text-color-secondary, #94a3b8); font-size: 12px; }
.amount-pos { color: #67c23a; }
.amount-neg { color: #f56c6c; }
</style>
