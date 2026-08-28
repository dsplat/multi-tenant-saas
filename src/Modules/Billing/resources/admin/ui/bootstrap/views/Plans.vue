<template>
  <div class="page">
    <div class="page-header"><h2>订阅计划</h2><button class="primary-btn" @click="openCreate">+ 创建计划</button></div>
    <div class="panel">
      <table class="data-table">
        <thead>
          <tr><th>标识</th><th>名称</th><th>月价</th><th>年价</th><th>试用</th><th>状态</th><th>操作</th></tr>
        </thead>
        <tbody>
          <tr v-for="p in plans" :key="p.subscription_plan_id ?? p.id ?? p.plan_id">
            <td>{{ p.name }}</td>
            <td>
              <div>{{ p.display_name || p.name }}</div>
              <div v-if="p.description" style="font-size: 12px; color: var(--text-color-secondary, #999)">{{ p.description }}</div>
            </td>
            <td>¥{{ fen(p.price_monthly) }}</td>
            <td>¥{{ fen(p.price_yearly) }}</td>
            <td>{{ p.trial_days > 0 ? `${p.trial_days} 天` : '无' }}</td>
            <td><span :class="['badge', p.is_active ? 'badge-success' : 'badge-danger']">{{ p.is_active ? '启用' : '禁用' }}</span></td>
            <td>
              <button class="link-btn" @click="openEdit(p)">编辑</button>
              <button class="link-btn danger" @click="handleDelete(p)">删除</button>
            </td>
          </tr>
          <tr v-if="plans.length === 0"><td colspan="7" class="empty-row">暂无订阅计划</td></tr>
        </tbody>
      </table>
    </div>

    <div class="modal-backdrop" v-if="dialogVisible" @click="dialogVisible = false">
      <div class="modal-content modal-wide" @click.stop>
        <h3>{{ isEdit ? '编辑计划' : '创建计划' }}</h3>
        <form @submit.prevent="handleSubmit">
          <div class="form-grid">
            <div class="form-group"><label>标识</label><input v-model="form.name" required :disabled="isEdit" placeholder="free/basic/pro/enterprise" /></div>
            <div class="form-group"><label>名称</label><input v-model="form.display_name" /></div>
            <div class="form-group"><label>月价（元）</label><input v-model.number="priceMonthly" type="number" min="0" step="0.01" /></div>
            <div class="form-group"><label>年价（元）</label><input v-model.number="priceYearly" type="number" min="0" step="0.01" /></div>
            <div class="form-group"><label>试用天数</label><input v-model.number="form.trial_days" type="number" min="0" /></div>
            <div class="form-group"><label>限流（rpm）</label><input v-model.number="form.rate_limit_rpm" type="number" min="0" /></div>
          </div>
          <div class="form-group"><label>描述</label><textarea v-model="form.description" rows="2"></textarea></div>
          <div class="form-group"><label>功能特性（逗号分隔）</label><input v-model="featuresInput" placeholder="feature1,feature2（如 wechat_work_base,wechat_work_intercom）" /></div>
          <div class="form-group"><label>配额 limits（JSON）</label><textarea v-model="limitsInput" rows="3" placeholder='如 {"max_users":20,"wechat_work_license_basic":20}'></textarea></div>
          <div class="form-group"><label>计量规则 metered_price（JSON，留空不计量）</label><textarea v-model="meteredInput" rows="2" placeholder='如 {"limit":20,"overage_price":50,"hard_limit":false}'></textarea></div>
          <div class="form-grid">
            <div class="form-group"><label>计量单位</label><input v-model="form.metered_unit" placeholder="如 wechat_work_license" /></div>
            <div class="form-group"><label>超额单价（元）</label><input v-model.number="overagePrice" type="number" min="0" step="0.01" /></div>
            <div class="form-group"><label>排序</label><input v-model.number="form.sort_order" type="number" min="0" /></div>
            <div class="form-group"><label><input type="checkbox" v-model="form.overage_allowed" /> 超额放行</label></div>
            <div class="form-group"><label><input type="checkbox" v-model="form.is_active" /> 启用</label></div>
          </div>
          <div class="form-actions"><button type="button" @click="dialogVisible = false">取消</button><button type="submit" class="primary-btn">确定</button></div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const API = '/api/v1/admin/billing/plans'
const plans = ref<any[]>([])
const dialogVisible = ref(false)
const isEdit = ref(false)
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
const yuanFloat = (v: number) => Number((v ?? 0).toFixed(2))

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
    alert(e.response?.data?.message || '加载失败')
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
    alert(e.message)
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
  try {
    if (isEdit.value) await axios.put(`${API}/${editId.value}`, payload)
    else await axios.post(API, payload)
    dialogVisible.value = false
    await fetchPlans()
    alert(isEdit.value ? '更新成功' : '创建成功')
  } catch (e: any) {
    alert(e.response?.data?.message || '操作失败')
  }
}

const handleDelete = async (p: any) => {
  if (!confirm(`确定删除计划 ${p.display_name || p.name}？`)) return
  try {
    await axios.delete(`${API}/${p.subscription_plan_id ?? p.id ?? p.plan_id}`)
    await fetchPlans()
    alert('删除成功')
  } catch (e: any) {
    alert(e.response?.data?.message || '删除失败')
  }
}

onMounted(fetchPlans)
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.primary-btn { padding: 8px 16px; background: var(--primary-color, #409eff); color: #fff; border: none; border-radius: 6px; cursor: pointer; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.empty-row { text-align: center; color: var(--text-color-secondary, #999); padding: 24px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-success { background: var(--badge-success-bg); color: var(--badge-success-fg); }
.badge-danger { background: var(--badge-danger-bg); color: var(--badge-danger-fg); }
.link-btn { background: none; border: none; color: var(--link-color); cursor: pointer; font-size: 13px; padding: 0 4px; }
.link-btn.danger { color: var(--link-danger); }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-content { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; max-width: 520px; }
.modal-content.modal-wide { min-width: 560px; }
.modal-content h3 { margin: 0 0 20px; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; box-sizing: border-box; font-family: inherit; }
.form-group input[type="checkbox"] { width: auto; }
.form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
.form-actions button { padding: 8px 16px; border-radius: 6px; border: 1px solid var(--border-color, #ddd); background: #fff; cursor: pointer; }
</style>