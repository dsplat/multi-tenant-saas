<template>
  <div class="operators-page">
    <div class="page-header">
      <h2>运营人员</h2>
      <button class="primary-btn" @click="showInvite = true">+ 邀请运营人员</button>
    </div>

    <div class="panel">
      <div class="filter-bar">
        <select v-model="filterScope" @change="fetchOperators">
          <option value="">全部范围</option>
          <option value="platform">平台级</option>
          <option value="tenant">租户级</option>
        </select>
      </div>

      <table class="data-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>姓名</th>
            <th>邮箱</th>
            <th>范围</th>
            <th>状态</th>
            <th>创建时间</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="op in operators" :key="op.operator_id">
            <td>{{ op.operator_id }}</td>
            <td>{{ op.name }}</td>
            <td>{{ op.email }}</td>
            <td><span class="badge" :class="op.scope === 'platform' ? 'badge-warning' : 'badge-info'">{{ op.scope }}</span></td>
            <td><span :class="['badge', op.is_active ? 'badge-success' : 'badge-danger']">{{ op.is_active ? '活跃' : '禁用' }}</span></td>
            <td>{{ op.created_at }}</td>
            <td>
              <button class="link-btn" @click="toggleStatus(op)">{{ op.is_active ? '禁用' : '启用' }}</button>
              <button class="link-btn danger" @click="handleDelete(op)">删除</button>
            </td>
          </tr>
          <tr v-if="operators.length === 0"><td colspan="7" class="empty-row">暂无运营人员</td></tr>
        </tbody>
      </table>
    </div>

    <div class="modal-backdrop" v-if="showInvite" @click="showInvite = false">
      <div class="modal-content" @click.stop>
        <h3>邀请运营人员</h3>
        <form @submit.prevent="handleInvite">
          <div class="form-group"><label>邮箱</label><input v-model="inviteForm.email" type="email" required /></div>
          <div class="form-group"><label>姓名</label><input v-model="inviteForm.name" required /></div>
          <div class="form-group">
            <label>范围</label>
            <select v-model="inviteForm.scope">
              <option value="platform">平台级</option>
              <option value="tenant">租户级</option>
            </select>
          </div>
          <div class="form-actions">
            <button type="button" @click="showInvite = false">取消</button>
            <button type="submit" class="primary-btn">邀请</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const operators = ref<any[]>([])
const filterScope = ref('')
const showInvite = ref(false)
const inviteForm = ref({ email: '', name: '', scope: 'platform' })

const fetchOperators = async () => {
  try {
    const res = await axios.get('/api/v1/operators', { params: { scope: filterScope.value || undefined } })
    operators.value = res.data.data || []
  } catch {}
}

const handleInvite = async () => {
  try {
    await axios.post('/api/v1/operators/invite', inviteForm.value)
    showInvite.value = false
    inviteForm.value = { email: '', name: '', scope: 'platform' }
    await fetchOperators()
  } catch {}
}

const toggleStatus = async (op: any) => {
  try {
    await axios.put(`/v1/admin/operators/${op.operator_id}`, { is_active: !op.is_active })
    await fetchOperators()
  } catch {}
}

const handleDelete = async (op: any) => {
  if (!confirm(`确定删除运营人员 ${op.name}？`)) return
  try { await axios.delete(`/v1/admin/operators/${op.operator_id}`); await fetchOperators() } catch (e: any) { alert(e.response?.data?.message || '删除失败') }
}

onMounted(fetchOperators)
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
.badge-info { background: #eee; color: #666; }
.badge-success { background: #e6ffed; color: #52c41a; }
.badge-warning { background: #fff7e6; color: #fa8c16; }
.badge-danger { background: #fff1f0; color: #f5222d; }
.link-btn { background: none; border: none; color: var(--primary-color, #409eff); cursor: pointer; font-size: 13px; padding: 0 4px; }
.link-btn.danger { color: #f5222d; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-content { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; min-width: 400px; max-width: 500px; }
.modal-content h3 { margin: 0 0 20px; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input, .form-group select { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; box-sizing: border-box; }
.form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
.form-actions button { padding: 8px 16px; border-radius: 6px; border: 1px solid var(--border-color, #ddd); background: #fff; cursor: pointer; }
</style>
