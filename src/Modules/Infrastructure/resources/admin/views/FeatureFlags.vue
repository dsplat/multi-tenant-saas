<template>
  <div class="page">
    <div class="page-header"><h2>功能开关</h2><button class="primary-btn" @click="openCreate">+ 创建开关</button></div>
    <div class="panel">
      <div class="filter-bar">
        <select v-model="filters.scope" @change="fetch"><option value="">全部范围</option><option value="global">全局</option><option value="tenant">租户</option></select>
        <select v-model="filters.status" @change="fetch"><option value="">全部状态</option><option value="active">启用</option><option value="inactive">停用</option></select>
      </div>
      <table class="data-table">
        <thead><tr><th>名称</th><th>描述</th><th>范围</th><th>状态</th><th>灰度%</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="f in flags" :key="f.id ?? f.feature_flag_id">
            <td><strong>{{ f.name }}</strong></td><td>{{ f.description || '-' }}</td>
            <td><span class="badge badge-info">{{ f.scope }}</span></td>
            <td><span :class="['badge', f.status === 'active' ? 'badge-success' : 'badge-danger']">{{ f.status === 'active' ? '启用' : '停用' }}</span></td>
            <td>{{ f.rollout_percentage ?? 100 }}%</td>
            <td>
              <button class="link-btn" @click="toggleFlag(f)">{{ f.status === 'active' ? '停用' : '启用' }}</button>
              <button class="link-btn" @click="openEdit(f)">编辑</button>
              <button class="link-btn danger" @click="handleDelete(f)">删除</button>
            </td>
          </tr>
          <tr v-if="flags.length === 0"><td colspan="6" class="empty-row">暂无功能开关</td></tr>
        </tbody>
      </table>
    </div>

    <div class="modal-backdrop" v-if="dialog" @click="dialog = false">
      <div class="modal-content" @click.stop>
        <h3>{{ isEdit ? '编辑功能开关' : '创建功能开关' }}</h3>
        <form @submit.prevent="handleSubmit">
          <div class="form-group"><label>名称</label><input v-model="form.name" required :disabled="isEdit" /></div>
          <div class="form-group"><label>描述</label><input v-model="form.description" /></div>
          <div class="form-group"><label>范围</label><select v-model="form.scope"><option value="global">全局</option><option value="tenant">租户</option></select></div>
          <div class="form-group"><label>状态</label><select v-model="form.status"><option value="active">启用</option><option value="inactive">停用</option></select></div>
          <div class="form-group"><label>灰度比例 (%)</label><input v-model.number="form.rollout_percentage" type="number" min="0" max="100" /></div>
          <div class="form-actions"><button type="button" @click="dialog = false">取消</button><button type="submit" class="primary-btn">确定</button></div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const API = '/api/v1/admin/feature-flags'
const flags = ref<any[]>([])
const dialog = ref(false)
const isEdit = ref(false)
const editId = ref('')
const filters = ref({ scope: '', status: '' })
const form = ref({ name: '', description: '', scope: 'global', status: 'active', rollout_percentage: 100 })

const fetch = async () => { try { const r = await axios.get(API, { params: filters.value }); flags.value = r.data.data || [] } catch {} }
const openCreate = () => { isEdit.value = false; form.value = { name: '', description: '', scope: 'global', status: 'active', rollout_percentage: 100 }; dialog.value = true }
const openEdit = (f: any) => { isEdit.value = true; editId.value = f.id ?? f.feature_flag_id; form.value = { name: f.name, description: f.description || '', scope: f.scope, status: f.status, rollout_percentage: f.rollout_percentage ?? 100 }; dialog.value = true }

const handleSubmit = async () => {
  try {
    if (isEdit.value) await axios.put(`${API}/${editId.value}`, form.value)
    else await axios.post(API, form.value)
    dialog.value = false; await fetch()
  } catch {}
}

const toggleFlag = async (f: any) => {
  try { await axios.post(`${API}/${f.id ?? f.feature_flag_id}/toggle`); await fetch() } catch {}
}

const handleDelete = async (f: any) => {
  if (!confirm(`确定删除功能开关 ${f.name}？`)) return
  try { await axios.delete(`${API}/${f.id ?? f.feature_flag_id}`); await fetch() } catch (e: any) { alert(e.response?.data?.message || '删除失败') }
}

onMounted(fetch)
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.primary-btn { padding: 8px 16px; background: var(--primary-color, #409eff); color: #fff; border: none; border-radius: 6px; cursor: pointer; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.filter-bar { display: flex; gap: 12px; margin-bottom: 16px; }
.filter-bar select { padding: 6px 10px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.empty-row { text-align: center; color: var(--text-color-secondary, #999); padding: 24px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-info { background: var(--badge-info-bg); color: var(--badge-info-fg); }
.badge-success { background: var(--badge-success-bg); color: var(--badge-success-fg); }
.badge-danger { background: var(--badge-danger-bg); color: var(--badge-danger-fg); }
.link-btn { background: none; border: none; color: var(--link-color); cursor: pointer; font-size: 13px; padding: 0 4px; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-content { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; min-width: 420px; }
.modal-content h3 { margin: 0 0 20px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input, .form-group select { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; box-sizing: border-box; }
.form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
.form-actions button { padding: 8px 16px; border-radius: 6px; border: 1px solid var(--border-color, #ddd); background: #fff; cursor: pointer; }
</style>
