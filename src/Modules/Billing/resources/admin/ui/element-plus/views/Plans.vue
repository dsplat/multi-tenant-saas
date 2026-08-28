<template>
  <div class="page">
    <div class="page-header">
      <h2>订阅计划</h2>
      <el-button type="primary" :icon="Plus" @click="openCreate">创建计划</el-button>
    </div>

    <el-card shadow="never">
      <el-table :data="plans" stripe style="width: 100%" empty-text="暂无订阅计划">
        <el-table-column prop="name" label="标识" width="110" />
        <el-table-column label="名称" min-width="140">
          <template #default="{ row }">
            <div>{{ row.display_name || row.name }}</div>
            <div v-if="row.description" style="font-size: 12px; color: #909399">{{ row.description }}</div>
          </template>
        </el-table-column>
        <el-table-column label="月价" width="100">
          <template #default="{ row }">¥{{ fen(row.price_monthly) }}</template>
        </el-table-column>
        <el-table-column label="年价" width="100">
          <template #default="{ row }">¥{{ fen(row.price_yearly) }}</template>
        </el-table-column>
        <el-table-column label="试用" width="80">
          <template #default="{ row }">{{ row.trial_days > 0 ? `${row.trial_days} 天` : '无' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.is_active ? 'success' : 'danger'" size="small">
              {{ row.is_active ? '启用' : '禁用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="120">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑计划' : '创建计划'" width="640px" top="6vh">
      <el-form :model="form" label-width="120px">
        <el-row :gutter="12">
          <el-col :span="12"><el-form-item label="标识"><el-input v-model="form.name" :disabled="isEdit" placeholder="free/basic/pro/enterprise" /></el-form-item></el-col>
          <el-col :span="12"><el-form-item label="名称"><el-input v-model="form.display_name" /></el-form-item></el-col>
        </el-row>
        <el-form-item label="描述"><el-input v-model="form.description" type="textarea" :rows="2" /></el-form-item>
        <el-row :gutter="12">
          <el-col :span="8"><el-form-item label="月价（元）"><el-input-number v-model="priceMonthly" :min="0" :precision="2" controls-position="right" style="width: 100%" /></el-form-item></el-col>
          <el-col :span="8"><el-form-item label="年价（元）"><el-input-number v-model="priceYearly" :min="0" :precision="2" controls-position="right" style="width: 100%" /></el-form-item></el-col>
          <el-col :span="8"><el-form-item label="试用天数"><el-input-number v-model="form.trial_days" :min="0" controls-position="right" style="width: 100%" /></el-form-item></el-col>
        </el-row>
        <el-form-item label="功能特性">
          <el-input v-model="featuresInput" placeholder="feature1,feature2（如 wechat_work_base,wechat_work_intercom）" />
        </el-form-item>
        <el-form-item label="配额 limits">
          <el-input v-model="limitsInput" type="textarea" :rows="3" placeholder='JSON，如 {"max_users":20,"wechat_work_license_basic":20}' />
        </el-form-item>
        <el-form-item label="计量规则">
          <el-input v-model="meteredInput" type="textarea" :rows="2" placeholder='JSON，如 {"limit":20,"overage_price":50,"hard_limit":false}；留空表示不计量' />
        </el-form-item>
        <el-row :gutter="12">
          <el-col :span="12"><el-form-item label="计量单位"><el-input v-model="form.metered_unit" placeholder="如 wechat_work_license" /></el-form-item></el-col>
          <el-col :span="12"><el-form-item label="限流（rpm）"><el-input-number v-model="form.rate_limit_rpm" :min="0" controls-position="right" style="width: 100%" /></el-form-item></el-col>
        </el-row>
        <el-row :gutter="12">
          <el-col :span="8"><el-form-item label="超额放行"><el-switch v-model="form.overage_allowed" /></el-form-item></el-col>
          <el-col :span="8"><el-form-item label="超额单价（元）"><el-input-number v-model="overagePrice" :min="0" :precision="2" controls-position="right" style="width: 100%" /></el-form-item></el-col>
          <el-col :span="8"><el-form-item label="排序"><el-input-number v-model="form.sort_order" :min="0" controls-position="right" style="width: 100%" /></el-form-item></el-col>
        </el-row>
        <el-form-item label="启用"><el-switch v-model="form.is_active" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { Plus } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from 'element-plus'

const API = '/api/v1/admin/billing/plans'
const plans = ref<any[]>([])
const dialogVisible = ref(false)
const isEdit = ref(false)
const submitting = ref(false)
const editId = ref<string | number>('')
const featuresInput = ref('')
const limitsInput = ref('')
const meteredInput = ref('')

const form = ref({
  name: '',
  display_name: '',
  description: '',
  trial_days: 0,
  metered_unit: '',
  overage_allowed: false,
  rate_limit_rpm: 60,
  sort_order: 0,
  is_active: true,
})
const priceMonthly = ref(0)
const priceYearly = ref(0)
const overagePrice = ref(0)

// 分 → 元（后端价格以「分」存储）
const fen = (v: number) => ((v ?? 0) / 100).toFixed(2)
const yuan = (v: number) => Math.round(((v ?? 0) * 100))
const yuanFloat = (v: number) => Number(((v ?? 0)).toFixed(2))

const emptyForm = () => {
  form.value = { name: '', display_name: '', description: '', trial_days: 0, metered_unit: '', overage_allowed: false, rate_limit_rpm: 60, sort_order: 0, is_active: true }
  priceMonthly.value = 0
  priceYearly.value = 0
  overagePrice.value = 0
  featuresInput.value = ''
  limitsInput.value = ''
  meteredInput.value = ''
}

const parseJson = (raw: string, label: string): any => {
  const t = raw.trim()
  if (!t) return null
  try {
    return JSON.parse(t)
  } catch {
    throw new Error(`${label}必须是合法 JSON`)
  }
}

const fetchPlans = async () => {
  try {
    const r = await axios.get(API)
    plans.value = r.data.data || []
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '加载失败')
  }
}

const openCreate = () => {
  isEdit.value = false
  emptyForm()
  dialogVisible.value = true
}

const openEdit = (p: any) => {
  isEdit.value = true
  editId.value = p.subscription_plan_id ?? p.id ?? p.plan_id
  form.value = {
    name: p.name,
    display_name: p.display_name || '',
    description: p.description || '',
    trial_days: p.trial_days ?? 0,
    metered_unit: p.metered_unit || '',
    overage_allowed: !!p.overage_allowed,
    rate_limit_rpm: p.rate_limit_rpm ?? 60,
    sort_order: p.sort_order ?? 0,
    is_active: p.is_active ?? true,
  }
  priceMonthly.value = yuanFloat((p.price_monthly ?? 0) / 100)
  priceYearly.value = yuanFloat((p.price_yearly ?? 0) / 100)
  overagePrice.value = Number(p.overage_price ?? 0)
  featuresInput.value = (p.features || []).join(',')
  limitsInput.value = p.limits ? JSON.stringify(p.limits) : ''
  meteredInput.value = p.metered_price ? JSON.stringify(p.metered_price) : ''
  dialogVisible.value = true
}

const handleSubmit = async () => {
  let limits: any = null
  let metered: any = null
  try {
    limits = parseJson(limitsInput.value, 'limits')
    metered = parseJson(meteredInput.value, '计量规则')
  } catch (e: any) {
    ElMessage.error(e.message)
    return
  }

  const payload = {
    ...form.value,
    features: featuresInput.value ? featuresInput.value.split(',').map(s => s.trim()).filter(Boolean) : [],
    limits,
    metered_price: metered,
    price_monthly: yuan(priceMonthly.value),
    price_yearly: yuan(priceYearly.value),
    overage_price: yuanFloat(overagePrice.value),
  }
  submitting.value = true
  try {
    if (isEdit.value) await axios.put(`${API}/${editId.value}`, payload)
    else await axios.post(API, payload)
    dialogVisible.value = false
    await fetchPlans()
    ElMessage.success(isEdit.value ? '更新成功' : '创建成功')
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '操作失败')
  } finally {
    submitting.value = false
  }
}

const handleDelete = async (p: any) => {
  try {
    await ElMessageBox.confirm(`确定删除计划 ${p.display_name || p.name}？`, '警告', { type: 'error' })
    await axios.delete(`${API}/${p.subscription_plan_id ?? p.id ?? p.plan_id}`)
    await fetchPlans()
    ElMessage.success('删除成功')
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '删除失败')
  }
}

onMounted(fetchPlans)
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
</style>