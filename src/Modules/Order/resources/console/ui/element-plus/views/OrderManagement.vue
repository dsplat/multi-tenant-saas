<template>
  <div class="page-container">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>订单中心</span>
        </div>
      </template>

      <ProTable
        ref="tableRef"
        :columns="columns"
        :request="handleRequest"
        :search-config="searchConfig"
        :actions="actions"
      />
    </el-card>

    <!-- 订单详情 -->
    <el-dialog v-model="detailVisible" title="订单详情" width="720px">
      <template v-if="detailOrder">
        <el-descriptions :column="2" border>
          <el-descriptions-item label="订单号">{{ detailOrder.order_no }}</el-descriptions-item>
          <el-descriptions-item label="订单类型">{{ orderTypeLabel(detailOrder.order_type) }}</el-descriptions-item>
          <el-descriptions-item label="支付方式">{{ payMethodLabel(detailOrder.pay_method) }}</el-descriptions-item>
          <el-descriptions-item label="订单状态">{{ statusLabel(detailOrder.status) }}</el-descriptions-item>
          <el-descriptions-item label="现金金额">¥{{ Number(detailOrder.total_amount).toFixed(2) }}</el-descriptions-item>
          <el-descriptions-item label="积分">{{ detailOrder.points_amount || 0 }}</el-descriptions-item>
          <el-descriptions-item label="用户ID">{{ detailOrder.user_id ?? '-' }}</el-descriptions-item>
          <el-descriptions-item label="支付时间">{{ detailOrder.paid_at || '-' }}</el-descriptions-item>
          <el-descriptions-item label="创建时间" :span="2">{{ detailOrder.created_at }}</el-descriptions-item>
        </el-descriptions>

        <el-table :data="detailOrder.items || []" style="margin-top: 16px" size="small">
          <el-table-column prop="item_name" label="商品名称" min-width="140" />
          <el-table-column prop="spec" label="规格" width="120">
            <template #default="{ row }">{{ row.spec || '-' }}</template>
          </el-table-column>
          <el-table-column prop="quantity" label="数量" width="70" />
          <el-table-column label="单价" width="110">
            <template #default="{ row }">
              <span v-if="Number(row.unit_price) > 0">¥{{ Number(row.unit_price).toFixed(2) }}</span>
              <span v-if="Number(row.points_unit_price) > 0">{{ row.points_unit_price }}积分</span>
            </template>
          </el-table-column>
          <el-table-column label="小计" width="110">
            <template #default="{ row }">¥{{ Number(row.amount).toFixed(2) }}</template>
          </el-table-column>
        </el-table>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, h } from 'vue'
import { ElMessage, ElMessageBox, ElTag } from 'element-plus'
import ProTable from '@/components/common/ProTable/ProTable.vue'
import type {
  ColumnConfig,
  SearchConfig,
  ActionConfig,
  RequestParams,
  RequestResult,
} from '@/components/common/ProTable/ProTable.vue'
import {
  getOrderList,
  getOrderDetail,
  refundOrder,
  type Order,
  type OrderListParams,
} from '@modules/Order/api/order'

defineOptions({ name: 'OrderManagement' })

const tableRef = ref<InstanceType<typeof ProTable>>()
const detailVisible = ref(false)
const detailOrder = ref<Order | null>(null)

const orderTypeOptions = [
  { label: '活动报名', value: 'registration' },
  { label: '商品订单', value: 'product' },
  { label: '课程订单', value: 'course' },
  { label: '积分兑换', value: 'exchange' },
]

const statusOptions = [
  { label: '待支付', value: 'pending' },
  { label: '已支付', value: 'paid' },
  { label: '已退款', value: 'refunded' },
  { label: '已取消', value: 'cancelled' },
]

const statusTagType: Record<string, string> = {
  pending: 'warning',
  paid: 'success',
  refunded: 'info',
  cancelled: 'danger',
}

function orderTypeLabel(value: string) {
  return orderTypeOptions.find((o) => o.value === value)?.label ?? value
}

function payMethodLabel(value: string) {
  const map: Record<string, string> = { cash: '现金', points: '积分', mixed: '混合支付' }
  return map[value] ?? value
}

function statusLabel(value: string) {
  return statusOptions.find((o) => o.value === value)?.label ?? value
}

const searchConfig: SearchConfig[] = [
  {
    prop: 'order_type',
    label: '订单类型',
    type: 'select',
    placeholder: '请选择订单类型',
    options: orderTypeOptions,
  },
  {
    prop: 'status',
    label: '订单状态',
    type: 'select',
    placeholder: '请选择状态',
    options: statusOptions,
  },
]

const columns: ColumnConfig[] = [
  { prop: 'order_no', label: '订单号', minWidth: 180 },
  {
    prop: 'order_type',
    label: '类型',
    width: 110,
    render: (row: Order) => h(ElTag, { type: 'info' }, () => orderTypeLabel(row.order_type)),
  },
  {
    prop: 'pay_method',
    label: '支付方式',
    width: 100,
    render: (row: Order) => h('span', null, payMethodLabel(row.pay_method)),
  },
  {
    prop: 'total_amount',
    label: '现金金额',
    width: 110,
    render: (row: Order) => h('span', null, `¥${Number(row.total_amount).toFixed(2)}`),
  },
  { prop: 'points_amount', label: '积分', width: 90 },
  {
    prop: 'status',
    label: '状态',
    width: 100,
    render: (row: Order) =>
      h(ElTag, { type: (statusTagType[row.status] || 'info') as any }, () => statusLabel(row.status)),
  },
  { prop: 'user_id', label: '用户ID', width: 90 },
  { prop: 'created_at', label: '创建时间', width: 170, sortable: true },
]

const actions: ActionConfig[] = [
  { label: '详情', type: 'primary', onClick: (row) => handleDetail(row as Order) },
  {
    label: '退款',
    type: 'danger',
    visible: (row) => (row as Order).status === 'paid',
    onClick: (row) => handleRefund(row as Order),
  },
]

async function handleRequest(params: RequestParams): Promise<RequestResult> {
  try {
    const query: OrderListParams = { page: params.page, pageSize: params.pageSize }
    if (params.order_type) query.order_type = params.order_type
    if (params.status) query.status = params.status
    const res = await getOrderList(query)
    return { data: res.data ?? [], total: res.total ?? 0 }
  } catch (e: any) {
    ElMessage.error(e.message || '获取订单列表失败')
    return { data: [], total: 0 }
  }
}

async function handleDetail(row: Order) {
  try {
    detailOrder.value = await getOrderDetail(row.order_no)
    detailVisible.value = true
  } catch (e: any) {
    ElMessage.error(e.message || '获取订单详情失败')
  }
}

async function handleRefund(row: Order) {
  try {
    const { value } = await ElMessageBox.prompt('请输入退款原因', `退款订单 ${row.order_no}`, {
      confirmButtonText: '确认退款',
      cancelButtonText: '取消',
      inputPlaceholder: '退款原因（可选）',
      type: 'warning',
    })
    await refundOrder(row.order_no, value || undefined)
    ElMessage.success('退款成功（现金原路退回，积分已返还）')
    tableRef.value?.refresh()
  } catch (e: any) {
    if (e !== 'cancel') {
      ElMessage.error(e.message || '退款失败')
    }
  }
}
</script>

<style scoped>
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
</style>
