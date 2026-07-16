<template>
  <div class="page">
    <div class="page-header"><h2>数据保留策略</h2><button class="primary-btn" @click="openCreate">+ 创建策略</button></div>
    <div class="panel">
      <table class="data-table">
        <thead><tr><th>数据类型</th><th>保留天数</th><th>清理策略</th><th>自动清理</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="p in policies" :key="p.id ?? p.retention_policy_id">
            <td>{{ p.data_type }}</td><td>{{ p.retention_days }}天</td>
            <td><span class="badge badge-info">{{ p.cleanup_strategy }}</span></td>
            <td><span :class="['badge', p.auto_cleanup ? 'badge-success' : 'badge-danger']">{{ p.auto_cleanup ? '是' : '否' }}</span></td>
            <td>
              <button class="link-btn" @click="openEdit(p)">编辑</button>
              <button class="link-btn danger" @click="handleDelete(p)">删除</button>
            </td>
          </tr>
          <tr v-if="policies.length === 0"><td colspan="5" class="empty-row">暂无策略</td></tr>
        </tbody>
      </table>
    </div>

    <div class="modal-backdrop" v-if="dialog" @click="dialog = false">
      <div class="modal-content" @click.stop>
        <h3>{{ isEdit ? '编辑策略' : '创建策略' }}</h3>
        <form @submit.prevent="handleSubmit">
          <div class="form-group"><label>数据类型</label><input v-model="form.data_type" required :disabled="isEdit" placeholder="audit_logs" /></div>
          <div class="form-group"><label>保留天数</label><input v-model.number="form.retention_days" type="number" min="1" required /></div>
          <div class="form-group"><label>清理策略</label><select v-model="form.cleanup_strategy"><option value="delete">删除</option><option value="anonymize">匿名化</option></select></div>
          <div class="form-group"><label><input type="checkbox" v-model="form.auto_cleanup" /> 自动清理</label></div>
          <div class="form-actions"><button type="button" @click="dialog = false">取消</button><button type="submit" class="primary-btn">确定</button></div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const API = '/api/v1/admin/retention-policies'
const policies = ref<any[]>([])
const dialog = ref(false)
const isEdit = ref(false)
const editId = ref('')
const form = ref({ data_type: '', retention_days: 365, cleanup_strategy: 'anonymize', auto_cleanup: true })

const fetch = async () => { try { const r = await axios.get(API); policies.value = r.data.data || [] } catch {} }
const openCreate = () => { isEdit.value = false; form.value = { data_type: '', retention_days: 365, cleanup_strategy: 'anonymize', auto_cleanup: true }; dialog.value = true }
const openEdit = (p: any) => { isEdit.value = true; editId.value = p.id ?? p.retention_policy_id; form.value = { data_type: p.data_type, retention_days: p.retention_days, cleanup_strategy: p.cleanup_strategy, auto_cleanup: p.auto_cleanup }; dialog.value = true }

const handleSubmit = async () => {
  try {
    if (isEdit.value) await axios.put(`${API}/${editId.value}`, form.value)
    else await axios.post(API, form.value)
    dialog.value = false; await fetch()
  } catch {}
}

const handleDelete = async (p: any) => { if (!confirm('确定删除？')) return; try { await axios.delete(`${API}/${p.id ?? p.retention_policy_id}`); await fetch() } catch {} }

onMounted(fetch)
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
.badge-info { background: var(--badge-info-bg); color: var(--badge-info-fg); }
.badge-success { background: var(--badge-success-bg); color: var(--badge-success-fg); }
.badge-danger { background: var(--badge-danger-bg); color: var(--badge-danger-fg); }
.link-btn { background: none; border: none; color: var(--link-color); cursor: pointer; font-size: 13px; padding: 0 4px; }
.link-btn.danger { color: var(--link-danger); }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-content { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; min-width: 400px; }
.modal-content h3 { margin: 0 0 20px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input, .form-group select { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; box-sizing: border-box; }
.form-group input[type="checkbox"] { width: auto; }
.form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
.form-actions button { padding: 8px 16px; border-radius: 6px; border: 1px solid var(--border-color, #ddd); background: #fff; cursor: pointer; }
</style>
