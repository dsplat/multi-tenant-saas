<template>
  <div class="page">
    <div class="page-header">
      <h2>供给授权</h2>
      <p class="page-desc">租户选品 / 平台划拨产生的供给授权总览；停供不停兑，恢复后继续供给</p>
    </div>

    <el-card shadow="never">
      <CrudTable ref="tableRef" fetch-api="/api/v1/admin/commerce/supply-grants"
        :columns="columns" :search-fields="searchFields" :extra-params="{ per_page: 100 }"
        :actions-width="230">
        <template #toolbar>
          <el-button type="primary" @click="openGrant">发起划拨</el-button>
        </template>
        <template #col-sku="{ row }">{{ row.sku?.name || row.sku_id }}</template>
        <template #col-status="{ row }">
          <el-tag :type="statusTag(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
        </template>
        <template #col-stock="{ row }">
          <span v-if="row.allocated_qty > 0">
            {{ row.remaining_qty }}/{{ row.allocated_qty }}
            <el-tag v-if="row.locked_qty > 0" type="warning" size="small" effect="plain">锁{{ row.locked_qty }}</el-tag>
          </span>
          <span v-else class="muted">非库存型</span>
        </template>
        <template #col-valid="{ row }">
          {{ fmtDate(row.valid_from) }} ~ {{ row.valid_until ? fmtDate(row.valid_until) : '永久' }}
        </template>
        <template #col-settlement="{ row }">
          <span class="settlement">{{ settlementSummary(row.settlement) }}</span>
        </template>
        <template #actions="{ row }">
          <el-button v-if="row.allocated_qty > 0" size="small" @click="openAdjust(row)">调额</el-button>
          <el-button v-if="row.status === 'active'" size="small" type="warning"
            @click="suspend(row)">停供</el-button>
          <el-button v-else-if="row.status === 'suspended'" size="small" type="success"
            @click="resume(row)">恢复</el-button>
        </template>
      </CrudTable>
    </el-card>

    <!-- 划拨弹窗 -->
    <el-dialog v-model="grantVisible" title="发起划拨（平台 → 租户）" width="480px">
      <el-form label-width="110px">
        <el-form-item label="租户ID" required>
          <el-input-number v-model="grantForm.tenantId" :min="1" :controls="false" style="width: 100%" />
        </el-form-item>
        <el-form-item label="供货 SKU" required>
          <el-select v-model="grantForm.skuId" filterable style="width: 100%" placeholder="选择 supply SKU">
            <el-option v-for="s in supplySkus" :key="s.sku_id" :label="`${s.name}（${s.sku_id}）`" :value="s.sku_id" />
          </el-select>
        </el-form-item>
        <el-form-item label="划拨数量" required>
          <el-input-number v-model="grantForm.qty" :min="1" style="width: 100%" />
        </el-form-item>
        <el-form-item label="供货价（元）">
          <el-input-number v-model="grantForm.supplyPrice" :min="0" :precision="2" style="width: 100%" />
          <div class="form-hint">结算时从租户预存货款扣款；缺省用 SKU 价格</div>
        </el-form-item>
        <el-form-item label="有效期至">
          <el-date-picker v-model="grantForm.validUntil" type="date" value-format="YYYY-MM-DD"
            placeholder="空 = 永久" style="width: 100%" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="grantVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="doGrant">确认划拨</el-button>
      </template>
    </el-dialog>

    <!-- 调额弹窗 -->
    <el-dialog v-model="adjustVisible" title="调整划拨额度" width="420px">
      <el-form label-width="110px">
        <el-form-item label="当前额度">
          <span>划拨 {{ adjustForm.allocated }} / 余量 {{ adjustForm.remaining }} / 锁定 {{ adjustForm.locked }}</span>
        </el-form-item>
        <el-form-item label="调整数量">
          <el-input-number v-model="adjustForm.delta" :step="1" style="width: 100%" />
          <div class="form-hint">正数追加、负数缩减（不得超过可下发余量）</div>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="adjustVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="doAdjust">确认调整</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'
import CrudTable from '@multi-tenant-saas/ui-core/components/CrudTable.vue'

const tableRef = ref()
const submitting = ref(false)

const columns = [
  { prop: 'grant_id', label: '授权ID', width: 150 },
  { prop: 'tenant_id', label: '租户', width: 110 },
  { prop: 'sku', label: 'SKU', minWidth: 150 },
  { prop: 'stock', label: '余量/划拨', width: 130 },
  { prop: 'source_order_id', label: '来源订单', width: 150 },
  { prop: 'status', label: '状态', width: 90 },
  { prop: 'valid', label: '有效期', width: 200 },
  { prop: 'settlement', label: '结算口径（仅记账）', minWidth: 140 },
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

// ===== 划拨 =====
const grantVisible = ref(false)
const supplySkus = ref<any[]>([])
const grantForm = reactive({
  tenantId: 0,
  skuId: null as number | null,
  qty: 10,
  supplyPrice: 0,
  validUntil: '',
})

const openGrant = async () => {
  grantForm.tenantId = 0
  grantForm.skuId = null
  grantForm.qty = 10
  grantForm.supplyPrice = 0
  grantForm.validUntil = ''
  grantVisible.value = true
  try {
    const res = await axios.get('/api/v1/admin/commerce/skus', { params: { role: 'supply', status: 'active' } })
    supplySkus.value = res.data?.data || []
  } catch { /* 忽略 */ }
}

const doGrant = async () => {
  if (!grantForm.tenantId || !grantForm.skuId) {
    ElMessage.warning('请填写租户与 SKU')
    return
  }
  submitting.value = true
  try {
    await axios.post('/api/v1/admin/commerce/supply-grants', {
      tenant_id: grantForm.tenantId,
      sku_id: grantForm.skuId,
      allocated_qty: grantForm.qty,
      supply_price: grantForm.supplyPrice > 0 ? grantForm.supplyPrice : undefined,
      valid_until: grantForm.validUntil || undefined,
    })
    ElMessage.success('划拨成功')
    grantVisible.value = false
    tableRef.value?.reload()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '划拨失败')
  } finally {
    submitting.value = false
  }
}

// ===== 调额 =====
const adjustVisible = ref(false)
const adjustForm = reactive({ grantId: 0, allocated: 0, remaining: 0, locked: 0, delta: 0 })

const openAdjust = (row: any) => {
  adjustForm.grantId = row.grant_id
  adjustForm.allocated = row.allocated_qty
  adjustForm.remaining = row.remaining_qty
  adjustForm.locked = row.locked_qty
  adjustForm.delta = 0
  adjustVisible.value = true
}

const doAdjust = async () => {
  if (!adjustForm.delta) {
    ElMessage.warning('调整数量不能为 0')
    return
  }
  submitting.value = true
  try {
    await axios.post(`/api/v1/admin/commerce/supply-grants/${adjustForm.grantId}/adjust-qty`, {
      delta_qty: adjustForm.delta,
    })
    ElMessage.success('额度已调整')
    adjustVisible.value = false
    tableRef.value?.reload()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '调整失败')
  } finally {
    submitting.value = false
  }
}
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-desc { color: var(--text-color-secondary, #64748b); font-size: 13px; margin-top: 4px; }
.settlement { font-size: 12px; }
.muted { color: var(--text-color-secondary, #94a3b8); }
.form-hint { font-size: 12px; color: var(--text-color-secondary, #94a3b8); line-height: 1.4; }
</style>
