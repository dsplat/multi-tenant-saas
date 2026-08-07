<template>
  <div class="page">
    <div class="page-header"><h2>订阅总览</h2></div>

    <div class="summary-cards">
      <el-card shadow="never"><div class="num">{{ summary.total_tenants }}</div><div class="lbl">租户总数</div></el-card>
      <el-card shadow="never"><div class="num ok">{{ summary.subscribed }}</div><div class="lbl">订阅中（付费档）</div></el-card>
      <el-card shadow="never"><div class="num warn">{{ summary.expiring_soon }}</div><div class="lbl">30 天内到期</div></el-card>
      <el-card shadow="never"><div class="num warn">{{ summary.pending_cancel }}</div><div class="lbl">到期不续费</div></el-card>
    </div>

    <el-card shadow="never">
      <div class="filter-bar">
        <el-input v-model="keyword" placeholder="租户名 / ID" clearable style="width: 200px" @keyup.enter="fetchList(1)" @clear="fetchList(1)" />
        <el-select v-model="planFilter" placeholder="全部套餐" clearable style="width: 160px" @change="fetchList(1)">
          <el-option v-for="p in plans" :key="p.subscription_plan_id" :label="p.display_name || p.name" :value="p.name" />
        </el-select>
        <el-select v-model="statusFilter" placeholder="全部状态" clearable style="width: 150px" @change="fetchList(1)">
          <el-option label="试用中" value="trial" />
          <el-option label="订阅中" value="active" />
          <el-option label="到期不续费" value="pending_cancel" />
          <el-option label="已过期" value="expired" />
        </el-select>
        <el-button type="primary" @click="fetchList(1)">查询</el-button>
      </div>

      <el-table :data="rows" stripe style="width: 100%" empty-text="暂无租户" v-loading="loading">
        <el-table-column label="租户" min-width="180">
          <template #default="{ row }">
            <div>{{ row.name }}</div>
            <div style="font-family: monospace; font-size: 12px; color: #909399">{{ row.tenant_id }}</div>
          </template>
        </el-table-column>
        <el-table-column label="当前套餐" width="120">
          <template #default="{ row }">{{ row.plan || 'free' }}</template>
        </el-table-column>
        <el-table-column label="订阅状态" width="110">
          <template #default="{ row }">
            <el-tag :type="subStatusType(row.sub_status)" size="small">{{ subStatusLabel(row.sub_status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="到期时间" width="160">
          <template #default="{ row }">
            <template v-if="row.subscription_expires_at">{{ formatDate(row.subscription_expires_at) }}</template>
            <el-tag v-else-if="row.plan && row.plan !== 'free'" type="success" size="small">永久</el-tag>
            <span v-else>-</span>
          </template>
        </el-table-column>
        <el-table-column label="试用截止" width="160">
          <template #default="{ row }">{{ formatDate(row.trial_ends_at) }}</template>
        </el-table-column>
        <el-table-column label="自动续费" width="90">
          <template #default="{ row }">
            <el-tag :type="row.auto_renew ? 'success' : 'info'" size="small">{{ row.auto_renew ? '是' : '否' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="230" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openChange(row)">变更套餐</el-button>
            <el-button v-if="row.auto_renew" link type="warning" size="small" @click="cancelSub(row)">取消续费</el-button>
            <el-button v-else link type="success" size="small" @click="resumeSub(row)">恢复续费</el-button>
            <el-button link size="small" @click="openHistory(row)">历史</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-if="total > perPage"
        v-model:current-page="currentPage"
        :page-size="perPage"
        :total="total"
        layout="prev, pager, next"
        style="margin-top: 16px; justify-content: center"
        @current-change="fetchList"
      />
    </el-card>

    <!-- 变更套餐弹窗 -->
    <el-dialog v-model="showChange" title="变更套餐" width="440px">
      <el-form label-width="90px">
        <el-form-item label="租户">
          <span>{{ changeTarget?.name }}（{{ changeTarget?.tenant_id }}）</span>
        </el-form-item>
        <el-form-item label="目标套餐">
          <el-select v-model="changeForm.plan_id" style="width: 100%">
            <el-option v-for="p in plans" :key="p.subscription_plan_id" :label="`${p.display_name || p.name}（¥${fen(p.price_monthly)}/月）`" :value="p.subscription_plan_id" />
          </el-select>
        </el-form-item>
        <el-form-item label="计费周期">
          <el-radio-group v-model="changeForm.billing_cycle">
            <el-radio value="monthly">按月</el-radio>
            <el-radio value="yearly">按年</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="showChange = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submitChange">确认变更</el-button>
      </template>
    </el-dialog>

    <!-- 订阅历史抽屉 -->
    <el-drawer v-model="showHistory" :title="`订阅历史 — ${historyTarget?.name || ''}`" size="520px">
      <el-table :data="historyRows" v-loading="historyLoading" empty-text="暂无记录">
        <el-table-column prop="action" label="动作" width="90">
          <template #default="{ row }"><el-tag size="small">{{ actionLabel(row.action) }}</el-tag></template>
        </el-table-column>
        <el-table-column label="变更" min-width="130">
          <template #default="{ row }">{{ row.from_plan || '-' }} → {{ row.to_plan || '-' }}</template>
        </el-table-column>
        <el-table-column label="备注" min-width="150" show-overflow-tooltip>
          <template #default="{ row }">{{ row.notes || '-' }}</template>
        </el-table-column>
        <el-table-column label="时间" width="150">
          <template #default="{ row }">{{ formatDate(row.created_at) }}</template>
        </el-table-column>
      </el-table>
    </el-drawer>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'

const BASE = '/api/v1/admin/billing/subscriptions'
const rows = ref<any[]>([])
const plans = ref<any[]>([])
const summary = ref<any>({ total_tenants: 0, subscribed: 0, expiring_soon: 0, pending_cancel: 0 })
const loading = ref(false)
const submitting = ref(false)
const keyword = ref('')
const planFilter = ref('')
const statusFilter = ref('')
const currentPage = ref(1)
const perPage = 20
const total = ref(0)

const showChange = ref(false)
const changeTarget = ref<any>(null)
const changeForm = reactive({ plan_id: null as number | null, billing_cycle: 'monthly' })

const showHistory = ref(false)
const historyTarget = ref<any>(null)
const historyRows = ref<any[]>([])
const historyLoading = ref(false)

const subStatusType = (s: string) => ({ active: 'success', trial: 'primary', pending_cancel: 'warning', expired: 'danger', free: 'info' }[s] || 'info')
const subStatusLabel = (s: string) => ({ active: '订阅中', trial: '试用中', pending_cancel: '到期不续费', expired: '已过期', free: '免费版' }[s] || s)
const actionLabel = (a: string) => ({ subscribe: '订阅', cancel: '取消', change: '变更', trial: '试用', renew: '续费', upgrade: '升级', downgrade: '降级' }[a] || a)
const formatDate = (d: string) => d ? d.substring(0, 16) : '-'
const fen = (v: any) => (Number(v || 0)).toFixed(2)

const fetchPlans = async () => {
  try {
    const r = await axios.get('/api/v1/admin/billing/plans', { params: { per_page: 100 } })
    plans.value = r.data.data || []
  } catch {}
}

const fetchList = async (page = 1) => {
  loading.value = true
  try {
    const params: any = { page, per_page: perPage }
    if (keyword.value) params.keyword = keyword.value
    if (planFilter.value) params.plan = planFilter.value
    if (statusFilter.value) params.status = statusFilter.value
    const r = await axios.get(BASE, { params })
    rows.value = r.data.data || []
    summary.value = r.data.summary || summary.value
    total.value = r.data.meta?.total ?? 0
    currentPage.value = page
  } catch {
    rows.value = []
  } finally {
    loading.value = false
  }
}

const openChange = (row: any) => {
  changeTarget.value = row
  changeForm.plan_id = row.subscription_plan_id || null
  changeForm.billing_cycle = 'monthly'
  showChange.value = true
}

const submitChange = async () => {
  if (!changeForm.plan_id) return ElMessage.warning('请选择目标套餐')
  submitting.value = true
  try {
    const r = await axios.post(`${BASE}/${changeTarget.value.tenant_id}/change-plan`, changeForm)
    ElMessage.success(r.data.message || '变更成功')
    showChange.value = false
    fetchList(currentPage.value)
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '变更失败')
  } finally {
    submitting.value = false
  }
}

const cancelSub = async (row: any) => {
  try {
    await ElMessageBox.confirm(`确认取消「${row.name}」的自动续费？到期后将降级为免费版。`, '取消订阅', { type: 'warning' })
  } catch { return }
  try {
    const r = await axios.post(`${BASE}/${row.tenant_id}/cancel`)
    ElMessage.success(r.data.message || '已取消')
    fetchList(currentPage.value)
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '操作失败')
  }
}

const resumeSub = async (row: any) => {
  try {
    const r = await axios.post(`${BASE}/${row.tenant_id}/resume`)
    ElMessage.success(r.data.message || '已恢复')
    fetchList(currentPage.value)
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '操作失败')
  }
}

const openHistory = async (row: any) => {
  historyTarget.value = row
  showHistory.value = true
  historyLoading.value = true
  try {
    const r = await axios.get(`${BASE}/${row.tenant_id}/history`, { params: { per_page: 50 } })
    historyRows.value = r.data.data || []
  } catch {
    historyRows.value = []
  } finally {
    historyLoading.value = false
  }
}

onMounted(() => {
  fetchPlans()
  fetchList()
})
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.summary-cards { display: flex; gap: 16px; margin-bottom: 16px; }
.summary-cards .el-card { flex: 1; text-align: center; }
.summary-cards .num { font-size: 26px; font-weight: 600; }
.summary-cards .num.ok { color: #67c23a; }
.summary-cards .num.warn { color: #e6a23c; }
.summary-cards .lbl { color: #909399; font-size: 13px; margin-top: 4px; }
.filter-bar { display: flex; gap: 12px; margin-bottom: 16px; }
</style>
