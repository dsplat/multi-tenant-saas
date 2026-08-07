<template>
  <div class="page">
    <div class="page-header">
      <h2>供给授权</h2>
      <p class="page-desc">租户选品 / 平台划拨产生的供给授权总览；停供不停兑，恢复后继续供给</p>
    </div>

    <el-card shadow="never">
      <CrudTable ref="tableRef" fetch-api="/api/v1/admin/commerce/supply-grants"
        :columns="columns" :search-fields="searchFields" :extra-params="{ per_page: 100 }"
        :actions-width="160">
        <template #col-sku="{ row }">{{ row.sku?.name || row.sku_id }}</template>
        <template #col-status="{ row }">
          <el-tag :type="statusTag(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
        </template>
        <template #col-valid="{ row }">
          {{ fmtDate(row.valid_from) }} ~ {{ row.valid_until ? fmtDate(row.valid_until) : '永久' }}
        </template>
        <template #col-settlement="{ row }">
          <span class="settlement">{{ settlementSummary(row.settlement) }}</span>
        </template>
        <template #actions="{ row }">
          <el-button v-if="row.status === 'active'" size="small" type="warning"
            @click="suspend(row)">停供</el-button>
          <el-button v-else-if="row.status === 'suspended'" size="small" type="success"
            @click="resume(row)">恢复</el-button>
          <span v-else class="muted">-</span>
        </template>
      </CrudTable>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'
import CrudTable from '@multi-tenant-saas/ui-core/components/CrudTable.vue'

const tableRef = ref()

const columns = [
  { prop: 'grant_id', label: '授权ID', width: 150 },
  { prop: 'tenant_id', label: '租户', width: 110 },
  { prop: 'sku', label: 'SKU', minWidth: 160 },
  { prop: 'source_order_id', label: '来源订单', width: 150 },
  { prop: 'status', label: '状态', width: 90 },
  { prop: 'valid', label: '有效期', width: 200 },
  { prop: 'settlement', label: '结算口径（仅记账）', minWidth: 160 },
  { prop: 'created_at', label: '创建时间', width: 160 },
]

const searchFields = [
  { prop: 'tenant_id', label: '租户ID' },
  {
    prop: 'status', label: '状态', type: 'select' as const, options: [
      { label: '生效', value: 'active' },
      { label: '已停供', value: 'suspended' },
      { label: '已过期', value: 'expired' },
      { label: '已撤销', value: 'revoked' },
    ],
  },
]

const statusLabel = (s: string) =>
  ({ active: '生效', suspended: '已停供', expired: '已过期', revoked: '已撤销' }[s] || s)
const statusTag = (s: string) =>
  ({ active: 'success', suspended: 'warning', expired: 'info', revoked: 'danger' }[s] || 'info')
const fmtDate = (d: string) => (d ? d.substring(0, 10) : '-')

// settlement 仅作记账口径展示，不做资金执行
const settlementSummary = (s: any) => {
  if (!s) return '-'
  try {
    const obj = typeof s === 'string' ? JSON.parse(s) : s
    const parts: string[] = []
    if (obj.mode) parts.push(`模式:${obj.mode}`)
    if (obj.supply_price !== undefined) parts.push(`供货价:¥${obj.supply_price}`)
    if (obj.share_ratio !== undefined) parts.push(`分成:${obj.share_ratio}%`)
    return parts.length ? parts.join(' / ') : JSON.stringify(obj)
  } catch {
    return '-'
  }
}

const suspend = async (row: any) => {
  try {
    await ElMessageBox.confirm(
      `确定停供授权 ${row.grant_id}（租户 ${row.tenant_id}）？停供不停兑，已有权益不受影响。`,
      '警告', { type: 'warning' })
    await axios.post(`/api/v1/admin/commerce/supply-grants/${row.grant_id}/suspend`)
    ElMessage.success('已停供')
    tableRef.value?.reload()
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '停供失败')
  }
}

const resume = async (row: any) => {
  try {
    await axios.post(`/api/v1/admin/commerce/supply-grants/${row.grant_id}/resume`)
    ElMessage.success('已恢复供给')
    tableRef.value?.reload()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '恢复失败')
  }
}
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-desc { color: var(--text-color-secondary, #64748b); font-size: 13px; margin-top: 4px; }
.settlement { font-size: 12px; }
.muted { color: var(--text-color-secondary, #94a3b8); }
</style>
