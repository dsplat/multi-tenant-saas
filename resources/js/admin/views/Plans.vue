<template>
  <div class="page">
    <div class="page-header"><h2>订阅计划</h2><button class="primary-btn" @click="openCreate">+ 创建计划</button></div>
    <div class="panel">
      <table class="data-table">
        <thead><tr><th>ID</th><th>名称</th><th>标识</th><th>价格</th><th>计费周期</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="p in plans" :key="p.id ?? p.plan_id">
            <td>{{ p.id ?? p.plan_id }}</td><td>{{ p.name }}</td><td>{{ p.slug }}</td>
            <td>¥{{ p.price }}</td><td>{{ p.billing_cycle === 'monthly' ? '月付' : '年付' }}</td>
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
      <div class="modal-content" @click.stop>
        <h3>{{ isEdit ? '编辑计划' : '创建计划' }}</h3>
        <form @submit.prevent="handleSubmit">
          <div class="form-group"><label>名称</label><input v-model="form.name" required /></div>
          <div class="form-group"><label>标识</label><input v-model="form.slug" required :disabled="isEdit" /></div>
          <div class="form-group"><label>价格</label><input v-model.number="form.price" type="number" min="0" step="0.01" required /></div>
          <div class="form-group"><label>计费周期</label><select v-model="form.billing_cycle"><option value="monthly">月付</option><option value="yearly">年付</option></select></div>
          <div class="form-group"><label>功能特性（逗号分隔）</label><input v-model="featuresInput" placeholder="feature1,feature2" /></div>
          <div class="form-group"><label><input type="checkbox" v-model="form.is_active" /> 启用</label></div>
          <div class="form-actions"><button type="button" @click="dialogVisible = false">取消</button><button type="submit" class="primary-btn">确定</button></div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'

const API = '/v1/admin/admin/billing/plans'
const plans = ref<any[]>([])
const dialogVisible = ref(false)
const isEdit = ref(false)
const editId = ref<string|number>('')
const form = ref({ name: '', slug: '', price: 0, billing_cycle: 'monthly', features: [] as string[], is_active: true })
const featuresInput = ref('')

const fetchPlans = async () => { try { const r = await axios.get(API); plans.value = r.data.data || [] } catch {} }

const openCreate = () => { isEdit.value = false; form.value = { name: '', slug: '', price: 0, billing_cycle: 'monthly', features: [], is_active: true }; featuresInput.value = ''; dialogVisible.value = true }

const openEdit = (p: any) => {
  isEdit.value = true; editId.value = p.id ?? p.plan_id
  form.value = { name: p.name, slug: p.slug, price: p.price, billing_cycle: p.billing_cycle, features: p.features || [], is_active: p.is_active ?? true }
  featuresInput.value = (p.features || []).join(','); dialogVisible.value = true
}

const handleSubmit = async () => {
  const payload = { ...form.value, features: featuresInput.value ? featuresInput.value.split(',').map(s => s.trim()) : [] }
  try {
    if (isEdit.value) await axios.put(`${API}/${editId.value}`, payload)
    else await axios.post(API, payload)
    dialogVisible.value = false; await fetchPlans()
  } catch {}
}

const handleDelete = async (p: any) => {
  if (!confirm(`确定删除计划 ${p.name}？`)) return
  try { await axios.delete(`${API}/${p.id ?? p.plan_id}`); await fetchPlans() } catch {}
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
.badge-success { background: #e6ffed; color: #52c41a; }
.badge-danger { background: #fff1f0; color: #f5222d; }
.link-btn { background: none; border: none; color: var(--primary-color, #409eff); cursor: pointer; font-size: 13px; padding: 0 4px; }
.link-btn.danger { color: #f5222d; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-content { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; min-width: 420px; max-width: 520px; }
.modal-content h3 { margin: 0 0 20px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input, .form-group select { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; box-sizing: border-box; }
.form-group input[type="checkbox"] { width: auto; }
.form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
.form-actions button { padding: 8px 16px; border-radius: 6px; border: 1px solid var(--border-color, #ddd); background: #fff; cursor: pointer; }
</style>
