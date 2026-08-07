<template>
  <div class="page">
    <div class="page-header">
      <h2>域名保证金</h2>
      <p class="page-desc">
        二级域名保证金（其他应付款，可退还负债）：开通锁定 → 退出退还 / 违规扣除，独立于预存货款台账
      </p>
    </div>

    <el-card shadow="never">
      <CrudTable ref="tableRef" fetch-api="/api/v1/admin/commerce/deposits"
        :columns="columns" :search-fields="searchFields" :extra-params="{ per_page: 100 }"
        :actions-width="240">
        <template #toolbar>
          <el-button type="primary" @click="openOp(null, 'lock')">新租户锁定保证金</el-button>
        </template>
        <template #col-tenant="{ row }">{{ row.tenant?.name || row.tenant_id }}</template>
        <template #col-balance="{ row }">¥{{ fen(row.balance) }}</template>
        <template #col-total_recharged="{ row }">¥{{ fen(row.total_recharged) }}</template>
        <template #col-total_consumed="{ row }">¥{{ fen(row.total_consumed) }}</template>
        <template #actions="{ row }">
          <el-button size="small" @click="openOp(row, 'lock')">追加锁定</el-button>
          <el-button size="small" type="success" @click="openOp(row, 'release')">退还</el-button>
          <el-button size="small" type="danger" @click="openOp(row, 'deduct')">违规扣除</el-button>
        </template>
      </CrudTable>
    </el-card>

    <el-dialog v-model="opVisible" :title="opTitle" width="440px">
      <el-form label-width="100px">
        <el-form-item label="租户ID">
          <el-input-number v-model="opForm.tenantId" :min="1" :controls="false" style="width: 100%"
            :disabled="opAction === 'release' || opAction === 'deduct'" />
        </el-form-item>
        <el-form-item label="金额（元）">
          <el-input-number v-model="opForm.amountYuan" :min="0.01" :precision="2" :step="100" style="width: 100%" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="opForm.note" :placeholder="opAction === 'deduct' ? '必填：违规事由' : '选填'" />
        </el-form-item>
        <el-alert v-if="opAction === 'deduct'" type="warning" :closable="false" show-icon
          title="违规扣除不退还，请确认已完成违规认定流程" style="margin-top: 8px" />
      </el-form>
      <template #footer>
        <el-button @click="opVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="doOperate">确认</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'
import CrudTable from '@multi-tenant-saas/ui-core/components/CrudTable.vue'

const tableRef = ref()

const columns = [
  { prop: 'tenant_id', label: '租户ID', width: 120 },
  { prop: 'tenant', label: '租户', minWidth: 180 },
  { prop: 'balance', label: '保证金余额', width: 130 },
  { prop: 'total_recharged', label: '累计锁定', width: 130 },
  { prop: 'total_consumed', label: '累计扣除/退还', width: 140 },
  { prop: 'created_at', label: '开户时间', width: 160 },
]

const searchFields = [{ prop: 'tenant_id', label: '租户ID' }]

const fen = (v: any) =>
  (Number(v || 0) / 100).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })

// ===== 操作 =====
const opVisible = ref(false)
const submitting = ref(false)
const opAction = ref<'lock' | 'release' | 'deduct'>('lock')
const opForm = reactive({ tenantId: 0, amountYuan: 500, note: '' })

const opTitle = computed(() =>
  ({ lock: '锁定保证金', release: '退还保证金', deduct: '违规扣除保证金' }[opAction.value]))

const openOp = (row: any, action: 'lock' | 'release' | 'deduct') => {
  opAction.value = action
  opForm.tenantId = row?.tenant_id || 0
  opForm.amountYuan = action === 'lock' ? 500 : Number(((row?.balance || 0) / 100).toFixed(2))
  opForm.note = ''
  opVisible.value = true
}

const doOperate = async () => {
  if (!opForm.tenantId) {
    ElMessage.warning('请输入租户ID')
    return
  }
  if (opAction.value === 'deduct' && !opForm.note.trim()) {
    ElMessage.warning('违规扣除必须填写事由')
    return
  }
  if (opAction.value !== 'lock') {
    try {
      await ElMessageBox.confirm(
        `确认对租户 ${opForm.tenantId} 执行「${opTitle.value}」¥${opForm.amountYuan.toFixed(2)}？`,
        '二次确认', { type: 'warning' })
    } catch {
      return
    }
  }
  submitting.value = true
  try {
    await axios.post(`/api/v1/admin/commerce/deposits/${opAction.value}`, {
      tenant_id: opForm.tenantId,
      amount: Math.round(opForm.amountYuan * 100),
      note: opForm.note || undefined,
    })
    ElMessage.success('操作成功')
    opVisible.value = false
    tableRef.value?.reload()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '操作失败')
  } finally {
    submitting.value = false
  }
}
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-desc { color: var(--text-color-secondary, #64748b); font-size: 13px; margin-top: 4px; }
</style>
