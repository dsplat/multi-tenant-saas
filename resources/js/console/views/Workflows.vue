<template>
  <div class="page">
    <div class="page-header"><h2>工作流管理</h2><button class="primary-btn" @click="openCreate">+ 创建工作流</button></div>
    <div class="panel">
      <table class="data-table">
        <thead><tr><th>ID</th><th>名称</th><th>类型</th><th>状态</th><th>创建时间</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="w in workflows" :key="w.workflow_id ?? w.id">
            <td>{{ w.workflow_id ?? w.id }}</td><td>{{ w.name }}</td>
            <td>{{ w.type || '-' }}</td>
            <td><span :class="['badge', statusClass(w.status)]">{{ w.status || 'draft' }}</span></td>
            <td>{{ w.created_at }}</td>
            <td>
              <button class="link-btn" @click="executeWorkflow(w)">执行</button>
              <button class="link-btn" @click="openEdit(w)">编辑</button>
              <button class="link-btn danger" @click="handleDelete(w)">删除</button>
            </td>
          </tr>
          <tr v-if="workflows.length === 0"><td colspan="6" class="empty-row">暂无工作流</td></tr>
        </tbody>
      </table>
    </div>

    <div class="modal-backdrop" v-if="dialogVisible" @click="dialogVisible = false">
      <div class="modal-content" @click.stop>
        <h3>{{ isEdit ? '编辑工作流' : '创建工作流' }}</h3>
        <form @submit.prevent="handleSubmit">
          <div class="form-group"><label>名称</label><input v-model="form.name" required /></div>
          <div class="form-group"><label>描述</label><input v-model="form.description" /></div>
          <div class="form-group"><label>定义（JSON）</label><textarea v-model="definitionInput" rows="8" placeholder='[{"type":"action","config":{}}]'></textarea></div>
          <div class="form-actions"><button type="button" @click="dialogVisible = false">取消</button><button type="submit" class="primary-btn">确定</button></div>
        </form>
      </div>
    </div>

    <div class="modal-backdrop" v-if="execResult" @click="execResult = null">
      <div class="modal-content" @click.stop>
        <h3>执行结果</h3>
        <pre class="result-output">{{ JSON.stringify(execResult, null, 2) }}</pre>
        <div class="form-actions"><button @click="execResult = null">关闭</button></div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const API = '/tenant/workflows'
const workflows = ref<any[]>([])
const dialogVisible = ref(false)
const isEdit = ref(false)
const editId = ref('')
const form = ref({ name: '', description: '', steps: [] as any[] })
const definitionInput = ref('[]')
const execResult = ref<any>(null)

const statusClass = (s: string) => ({ active: 'badge-success', draft: 'badge-info', paused: 'badge-warning', error: 'badge-danger' }[s] || 'badge-info')

const fetchWorkflows = async () => { try { const r = await axios.get(API); workflows.value = r.data.data || [] } catch {} }

const openCreate = () => { isEdit.value = false; form.value = { name: '', description: '', steps: [] }; definitionInput.value = '[]'; dialogVisible.value = true }
const openEdit = (w: any) => { isEdit.value = true; editId.value = w.workflow_id ?? w.id; form.value = { name: w.name, description: w.description || '', steps: w.steps || [] }; definitionInput.value = JSON.stringify(w.steps || w.definition || [], null, 2); dialogVisible.value = true }

const handleSubmit = async () => {
  try {
    let steps: any[]
    try { steps = JSON.parse(definitionInput.value) } catch { alert('JSON 格式错误'); return }
    const payload = { ...form.value, steps, definition: steps }
    if (isEdit.value) await axios.put(`${API}/${editId.value}`, payload)
    else await axios.post(API, payload)
    dialogVisible.value = false; await fetchWorkflows()
  } catch {}
}

const handleDelete = async (w: any) => {
  if (!confirm(`确定删除工作流 ${w.name}？`)) return
  try { await axios.delete(`${API}/${w.workflow_id ?? w.id}`); await fetchWorkflows() } catch {}
}

const executeWorkflow = async (w: any) => {
  try { const r = await axios.post(`${API}/${w.workflow_id ?? w.id}/execute`, {}); execResult.value = r.data } catch (e: any) { execResult.value = { error: e.response?.data?.message || e.message } }
}

onMounted(fetchWorkflows)
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
.badge-info { background: #eee; color: #666; }
.badge-success { background: #e6ffed; color: #52c41a; }
.badge-warning { background: #fff7e6; color: #fa8c16; }
.badge-danger { background: #fff1f0; color: #f5222d; }
.link-btn { background: none; border: none; color: var(--primary-color, #409eff); cursor: pointer; font-size: 13px; padding: 0 4px; }
.link-btn.danger { color: #f5222d; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-content { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; min-width: 460px; max-width: 600px; }
.modal-content h3 { margin: 0 0 20px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input, .form-group textarea { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; box-sizing: border-box; }
.form-group textarea { font-family: monospace; font-size: 12px; resize: vertical; }
.form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
.form-actions button { padding: 8px 16px; border-radius: 6px; border: 1px solid var(--border-color, #ddd); background: #fff; cursor: pointer; }
.result-output { background: #f5f5f5; padding: 12px; border-radius: 6px; font-size: 12px; overflow-x: auto; max-height: 300px; }
</style>
