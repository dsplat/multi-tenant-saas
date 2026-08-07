<template>
  <div class="page">
    <div class="page-header">
      <h2>SKU 商品池</h2>
      <p class="page-desc">平台统一 SKU 目录：租户在 console 选品下单 / 平台划拨供给的货源</p>
    </div>

    <el-card shadow="never">
      <CrudTable ref="tableRef" fetch-api="/api/v1/admin/commerce/skus" :columns="columns"
        :search-fields="searchFields" :actions-width="180">
        <template #toolbar>
          <el-button type="primary" @click="openForm()">新建 SKU</el-button>
        </template>
        <template #col-type="{ row }">
          <el-tag size="small">{{ typeLabel(row.type) }}</el-tag>
        </template>
        <template #col-role="{ row }">{{ row.role === 'supply' ? '供给型' : '消费型' }}</template>
        <template #col-price="{ row }">¥{{ Number(row.price).toFixed(2) }}</template>
        <template #col-billing_cycle="{ row }">{{ cycleLabel(row.billing_cycle) }}</template>
        <template #col-refundable="{ row }">{{ row.refundable ? '可退' : '不可退' }}</template>
        <template #col-status="{ row }">
          <el-tag :type="statusTag(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
        </template>
        <template #actions="{ row }">
          <el-button size="small" @click="openForm(row)">编辑</el-button>
          <el-button v-if="row.status !== 'retired'" size="small" type="danger"
            @click="retireSku(row)">下架</el-button>
        </template>
      </CrudTable>
    </el-card>

    <el-dialog v-model="dialog" :title="model.sku_id ? '编辑 SKU' : '新建 SKU'" width="640px">
      <CrudForm ref="formRef" :fields="fields" :model="model">
        <el-form-item label="payload">
          <el-input v-model="payloadText" type="textarea" :rows="4" :placeholder="payloadHint" />
        </el-form-item>
      </CrudForm>
      <template #footer>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="save">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'
import CrudTable from '@multi-tenant-saas/ui-core/components/CrudTable.vue'
import CrudForm from '@multi-tenant-saas/ui-core/components/CrudForm.vue'

const tableRef = ref()
const formRef = ref()
const saving = ref(false)

const columns = [
  { prop: 'name', label: '名称', minWidth: 180 },
  { prop: 'type', label: '类型', width: 110 },
  { prop: 'role', label: '角色', width: 90 },
  { prop: 'price', label: '价格', width: 100 },
  { prop: 'billing_cycle', label: '计费周期', width: 90 },
  { prop: 'lifecycle', label: '生命周期', width: 100 },
  { prop: 'fulfill_handler', label: '履约器', width: 120 },
  { prop: 'refundable', label: '退款', width: 80 },
  { prop: 'status', label: '状态', width: 90 },
  { prop: 'sort_order', label: '排序', width: 70 },
]

const searchFields = [
  {
    prop: 'type', label: '类型', type: 'select' as const, options: [
      { label: '套餐', value: 'plan' },
      { label: '模块开通', value: 'module' },
      { label: '积分包', value: 'credit_pack' },
      { label: '内容包', value: 'content_pack' },
      { label: '商城供货', value: 'mall_supply' },
    ],
  },
  {
    prop: 'role', label: '角色', type: 'select' as const, options: [
      { label: '消费型', value: 'consumer' },
      { label: '供给型', value: 'supply' },
    ],
  },
  {
    prop: 'status', label: '状态', type: 'select' as const, options: [
      { label: '草稿', value: 'draft' },
      { label: '生效', value: 'active' },
      { label: '已下架', value: 'retired' },
    ],
  },
]

const fields = [
  { prop: 'name', label: '名称', required: true },
  {
    prop: 'type', label: '类型', type: 'select' as const, required: true, options: [
      { label: '套餐（plan）', value: 'plan' },
      { label: '模块开通（module）', value: 'module' },
      { label: '积分包（credit_pack）', value: 'credit_pack' },
      { label: '内容包（content_pack）', value: 'content_pack' },
      { label: '商城供货（mall_supply）', value: 'mall_supply' },
    ],
  },
  {
    prop: 'role', label: '角色', type: 'select' as const, required: true, options: [
      { label: '消费型（租户购买自用）', value: 'consumer' },
      { label: '供给型（划拨给租户销售）', value: 'supply' },
    ],
  },
  { prop: 'price', label: '价格', type: 'number' as const, required: true, min: 0 },
  {
    prop: 'billing_cycle', label: '计费周期', type: 'select' as const, options: [
      { label: '月付', value: 'monthly' },
      { label: '年付', value: 'yearly' },
    ],
  },
  {
    prop: 'lifecycle', label: '生命周期', type: 'select' as const, options: [
      { label: '订阅（subscription）', value: 'subscription' },
      { label: '一次性（one_time）', value: 'one_time' },
      { label: '消耗型（consumable）', value: 'consumable' },
      { label: '供给授权（grant）', value: 'grant' },
    ],
  },
  {
    prop: 'fulfill_handler', label: '履约器', type: 'select' as const, required: true, options: [
      { label: 'plan', value: 'plan' },
      { label: 'module', value: 'module' },
      { label: 'credit_pack', value: 'credit_pack' },
      { label: 'content_pack', value: 'content_pack' },
      { label: 'mall_supply', value: 'mall_supply' },
    ],
  },
  { prop: 'refundable', label: '可退款', type: 'switch' as const },
  {
    prop: 'status', label: '状态', type: 'select' as const, options: [
      { label: '草稿', value: 'draft' },
      { label: '生效', value: 'active' },
      { label: '已下架', value: 'retired' },
    ],
  },
  { prop: 'sort_order', label: '排序', type: 'number' as const, min: 0 },
]

const typeLabel = (t: string) =>
  ({ plan: '套餐', module: '模块开通', credit_pack: '积分包', content_pack: '内容包', mall_supply: '商城供货' }[t] || t)
const cycleLabel = (c: string) => ({ monthly: '月付', yearly: '年付' }[c] || c || '-')
const statusLabel = (s: string) => ({ draft: '草稿', active: '生效', retired: '已下架' }[s] || s)
const statusTag = (s: string) => ({ draft: 'info', active: 'success', retired: 'danger' }[s] || 'info')

const PAYLOAD_HINTS: Record<string, string> = {
  credit_pack: '{"amount": 1000, "gift": 100}',
  content_pack: '{"pack_id": 123}',
  module: '{"module_key": "xxx"}',
  plan: '{"plan_code": "basic"}',
  mall_supply: '{"supply_mode": "free|credit|share"}',
}

const dialog = ref(false)
const model = reactive<Record<string, any>>({})
const payloadText = ref('')

const payloadHint = computed(() =>
  model.type ? `示例：${PAYLOAD_HINTS[model.type] || '{}'}` : '先选择类型后显示示例')

const openForm = (row?: any) => {
  Object.keys(model).forEach(k => delete model[k])
  if (row) Object.assign(model, row)
  payloadText.value = model.payload ? JSON.stringify(model.payload, null, 2) : ''
  dialog.value = true
}

const save = async () => {
  try {
    await formRef.value?.validate()
    let payload: any = null
    if (payloadText.value.trim()) {
      try {
        payload = JSON.parse(payloadText.value)
      } catch {
        ElMessage.error('payload 不是合法 JSON')
        return
      }
    }
    const body = { ...model, payload }
    if (!body.billing_cycle) delete body.billing_cycle
    saving.value = true
    if (model.sku_id) {
      await axios.put(`/api/v1/admin/commerce/skus/${model.sku_id}`, body)
    } else {
      await axios.post('/api/v1/admin/commerce/skus', body)
    }
    ElMessage.success('已保存')
    dialog.value = false
    tableRef.value?.reload()
  } catch (e: any) {
    if (e?.response) {
      const msg = e.response.data?.message
      ElMessage.error(typeof msg === 'string' ? msg : '保存失败')
    }
  } finally {
    saving.value = false
  }
}

const retireSku = async (row: any) => {
  try {
    await ElMessageBox.confirm(
      `确定下架「${row.name}」？该 SKU 的全部生效供给授权将同步失效。`,
      '警告', { type: 'warning' })
    await axios.delete(`/api/v1/admin/commerce/skus/${row.sku_id}`)
    ElMessage.success('已下架')
    tableRef.value?.reload()
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '下架失败')
  }
}
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-desc { color: var(--text-color-secondary, #64748b); font-size: 13px; margin-top: 4px; }
</style>
