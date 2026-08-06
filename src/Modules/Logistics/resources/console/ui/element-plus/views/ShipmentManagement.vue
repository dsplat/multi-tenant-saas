<template>
  <div class="shipment-management">
    <div class="page-header">
      <h2>发货管理</h2>
      <el-button type="primary" @click="openCreateDialog">登记发货单</el-button>
    </div>

    <div class="filter-bar">
      <el-input v-model="filters.order_no" placeholder="订单号" clearable style="width: 220px" @keyup.enter="loadList(1)" />
      <el-input v-model="filters.tracking_no" placeholder="运单号" clearable style="width: 220px" @keyup.enter="loadList(1)" />
      <el-select v-model="filters.status" placeholder="状态" clearable style="width: 140px">
        <el-option label="待发货" value="pending" />
        <el-option label="已发货" value="shipped" />
        <el-option label="已签收" value="delivered" />
        <el-option label="已取消" value="cancelled" />
      </el-select>
      <el-button type="primary" @click="loadList(1)">查询</el-button>
    </div>

    <el-table v-loading="loading" :data="list" border stripe>
      <el-table-column prop="order_no" label="订单号" min-width="180" show-overflow-tooltip />
      <el-table-column prop="carrier" label="承运方" min-width="120" />
      <el-table-column prop="tracking_no" label="运单号" min-width="160" show-overflow-tooltip />
      <el-table-column prop="receiver_name" label="收件人" width="110" />
      <el-table-column label="状态" width="100">
        <template #default="{ row }">
          <el-tag :type="statusTagType(row.status)">{{ statusLabel(row.status) }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="shipped_at" label="发货时间" width="170" />
      <el-table-column prop="created_at" label="登记时间" width="170" />
      <el-table-column label="操作" width="180" fixed="right">
        <template #default="{ row }">
          <el-button v-if="row.status === 'pending'" link type="primary" @click="openShipDialog(row)">发货</el-button>
          <el-button v-if="row.status === 'shipped'" link type="success" @click="handleDeliver(row)">签收</el-button>
          <el-button v-if="row.status === 'pending' || row.status === 'shipped'" link type="danger" @click="handleCancel(row)">取消</el-button>
        </template>
      </el-table-column>
    </el-table>

    <el-pagination
      v-model:current-page="page"
      v-model:page-size="pageSize"
      :total="total"
      layout="total, prev, pager, next"
      style="margin-top: 16px; justify-content: flex-end"
      @current-change="loadList"
    />

    <!-- 登记发货单 -->
    <el-dialog v-model="createVisible" title="登记发货单" width="520px">
      <el-form :model="createForm" label-width="90px">
        <el-form-item label="订单号" required>
          <el-input v-model="createForm.order_no" placeholder="已支付订单的订单号" />
        </el-form-item>
        <el-form-item label="承运方">
          <el-input v-model="createForm.carrier" placeholder="如：顺丰速运" />
        </el-form-item>
        <el-form-item label="运单号">
          <el-input v-model="createForm.tracking_no" />
        </el-form-item>
        <el-form-item label="收件人">
          <el-input v-model="createForm.receiver_name" />
        </el-form-item>
        <el-form-item label="联系电话">
          <el-input v-model="createForm.receiver_phone" />
        </el-form-item>
        <el-form-item label="收件地址">
          <el-input v-model="createForm.receiver_address" type="textarea" :rows="2" />
        </el-form-item>
        <el-form-item label="备注">
          <el-input v-model="createForm.remark" type="textarea" :rows="2" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="createVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleCreate">确定</el-button>
      </template>
    </el-dialog>

    <!-- 发货（填运单号） -->
    <el-dialog v-model="shipVisible" title="发货" width="440px">
      <el-form :model="shipForm" label-width="80px">
        <el-form-item label="承运方">
          <el-input v-model="shipForm.carrier" />
        </el-form-item>
        <el-form-item label="运单号" required>
          <el-input v-model="shipForm.tracking_no" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="shipVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleShip">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import {
  type Shipment,
  getShipmentList,
  createShipment,
  shipShipment,
  deliverShipment,
  cancelShipment,
} from '@modules/Logistics/api/shipment'

const loading = ref(false)
const saving = ref(false)
const list = ref<Shipment[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)

const filters = reactive({
  order_no: '',
  tracking_no: '',
  status: '',
})

// ========== 列表 ==========

async function loadList(p?: number): Promise<void> {
  if (p) page.value = p
  loading.value = true
  try {
    const result = await getShipmentList({
      page: page.value,
      pageSize: pageSize.value,
      order_no: filters.order_no || undefined,
      tracking_no: filters.tracking_no || undefined,
      status: filters.status || undefined,
    })
    list.value = result.data
    total.value = result.total
  } catch (e) {
    ElMessage.error('加载发货单失败')
  } finally {
    loading.value = false
  }
}

function statusLabel(status: string): string {
  return ({ pending: '待发货', shipped: '已发货', delivered: '已签收', cancelled: '已取消' } as Record<string, string>)[status] ?? status
}

function statusTagType(status: string): 'info' | 'warning' | 'primary' | 'success' | 'danger' {
  return ({ pending: 'warning', shipped: 'primary', delivered: 'success', cancelled: 'info' } as Record<string, 'info' | 'warning' | 'primary' | 'success'>)[status] ?? 'info'
}

// ========== 登记 ==========

const createVisible = ref(false)
const createForm = reactive({
  order_no: '',
  carrier: '',
  tracking_no: '',
  receiver_name: '',
  receiver_phone: '',
  receiver_address: '',
  remark: '',
})

function openCreateDialog(): void {
  Object.assign(createForm, {
    order_no: '',
    carrier: '',
    tracking_no: '',
    receiver_name: '',
    receiver_phone: '',
    receiver_address: '',
    remark: '',
  })
  createVisible.value = true
}

async function handleCreate(): Promise<void> {
  if (!createForm.order_no.trim()) {
    ElMessage.warning('请填写订单号')
    return
  }
  saving.value = true
  try {
    await createShipment({
      order_no: createForm.order_no.trim(),
      carrier: createForm.carrier || undefined,
      tracking_no: createForm.tracking_no || undefined,
      receiver_name: createForm.receiver_name || undefined,
      receiver_phone: createForm.receiver_phone || undefined,
      receiver_address: createForm.receiver_address || undefined,
      remark: createForm.remark || undefined,
    })
    ElMessage.success('发货单已登记')
    createVisible.value = false
    loadList(1)
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '登记失败')
  } finally {
    saving.value = false
  }
}

// ========== 发货/签收/取消 ==========

const shipVisible = ref(false)
const shipTarget = ref<Shipment | null>(null)
const shipForm = reactive({ carrier: '', tracking_no: '' })

function openShipDialog(row: Shipment): void {
  shipTarget.value = row
  shipForm.carrier = row.carrier || ''
  shipForm.tracking_no = row.tracking_no || ''
  shipVisible.value = true
}

async function handleShip(): Promise<void> {
  if (!shipTarget.value) return
  if (!shipForm.tracking_no.trim()) {
    ElMessage.warning('请填写运单号')
    return
  }
  saving.value = true
  try {
    await shipShipment(shipTarget.value.shipment_id, {
      carrier: shipForm.carrier || undefined,
      tracking_no: shipForm.tracking_no.trim(),
    })
    ElMessage.success('已发货')
    shipVisible.value = false
    loadList()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '发货失败')
  } finally {
    saving.value = false
  }
}

async function handleDeliver(row: Shipment): Promise<void> {
  try {
    await ElMessageBox.confirm(`确认订单 ${row.order_no} 已签收？`, '签收确认', { type: 'warning' })
    await deliverShipment(row.shipment_id)
    ElMessage.success('已签收')
    loadList()
  } catch (e: any) {
    if (e !== 'cancel') ElMessage.error(e?.response?.data?.message || '操作失败')
  }
}

async function handleCancel(row: Shipment): Promise<void> {
  try {
    await ElMessageBox.confirm(`确认取消订单 ${row.order_no} 的发货单？`, '取消确认', { type: 'warning' })
    await cancelShipment(row.shipment_id)
    ElMessage.success('已取消')
    loadList()
  } catch (e: any) {
    if (e !== 'cancel') ElMessage.error(e?.response?.data?.message || '操作失败')
  }
}

onMounted(() => loadList(1))
</script>

<style scoped>
.shipment-management {
  padding: 20px;
}
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}
.page-header h2 {
  margin: 0;
  font-size: 18px;
}
.filter-bar {
  display: flex;
  gap: 12px;
  margin-bottom: 16px;
}
</style>
