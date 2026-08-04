<template>
  <div class="page">
    <div class="page-header"><h2>商品目录</h2></div>

    <el-card shadow="never">
      <CrudTable ref="tableRef" fetch-api="/api/v1/commerce/skus" :columns="columns"
        :search-fields="searchFields">
        <template #col-status="{ row }">
          <el-tag :type="skuStatusTag(row.status)" size="small">{{ skuStatusLabel(row.status) }}</el-tag>
        </template>
        <template #col-price="{ row }">¥{{ Number(row.price).toFixed(2) }}</template>
        <template #col-type="{ row }">{{ typeLabel(row.type) }}</template>
        <template #actions="{ row }">
          <el-button size="small" @click="showDetail(row)">详情</el-button>
          <el-button size="small" type="primary" :disabled="row.status !== 'active'"
            @click="buyNow(row)">购买</el-button>
        </template>
      </CrudTable>
    </el-card>

    <DetailPanel v-model:visible="detailVisible" title="商品详情" :items="detailItems" />
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'
import CrudTable from '@multi-tenant-saas/ui-core/components/CrudTable.vue'
import DetailPanel from '@multi-tenant-saas/ui-core/components/DetailPanel.vue'

const tableRef = ref()
const detailVisible = ref(false)
const currentSku = ref<any>(null)

const columns = [
  { prop: 'name', label: '商品名称', minWidth: 180 },
  { prop: 'type', label: '类型', width: 110 },
  { prop: 'price', label: '价格', width: 100 },
  { prop: 'billing_cycle', label: '计费周期', width: 100 },
  { prop: 'status', label: '状态', width: 90 },
]

const searchFields = [
  { prop: 'keyword', label: '名称' },
  {
    prop: 'type', label: '类型', type: 'select' as const, options: [
      { label: '订阅套餐', value: 'plan' },
      { label: '模块', value: 'module' },
      { label: '积分包', value: 'credit_pack' },
      { label: '内容包', value: 'content_pack' },
      { label: '商城供给', value: 'mall_supply' },
    ],
  },
]

const typeLabel = (t: string) =>
  ({ plan: '订阅套餐', module: '模块', credit_pack: '积分包', content_pack: '内容包', mall_supply: '商城供给' }[t] || t)
const skuStatusLabel = (s: string) => ({ draft: '草稿', active: '在售', retired: '已下架' }[s] || s)
const skuStatusTag = (s: string) => ({ draft: 'info', active: 'success', retired: 'danger' }[s] || 'info')

const detailItems = computed(() => {
  const s = currentSku.value
  if (!s) return []
  return [
    { label: '商品名称', value: s.name },
    { label: '类型', value: typeLabel(s.type) },
    { label: '角色', value: s.role === 'supply' ? '供给' : '消费' },
    { label: '价格', value: `¥${Number(s.price).toFixed(2)}` },
    { label: '计费周期', value: s.billing_cycle || '一次性' },
    { label: '可退款', value: s.refundable ? '是' : '否' },
    { label: '状态', value: skuStatusLabel(s.status) },
  ]
})

const showDetail = (row: any) => {
  currentSku.value = row
  detailVisible.value = true
}

const buyNow = async (row: any) => {
  try {
    await axios.post('/api/v1/commerce/orders', { sku_id: row.sku_id, quantity: 1 })
    ElMessage.success('订单已创建，请前往订单列表完成支付')
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '下单失败')
  }
}
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
</style>
