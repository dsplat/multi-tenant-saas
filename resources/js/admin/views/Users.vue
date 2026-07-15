<template>
  <div class="page">
    <div class="page-header"><h2>用户管理</h2></div>

    <div class="panel">
      <div class="filter-bar">
        <select v-model="selectedTenantId" @change="fetchUsers">
          <option value="">选择租户</option>
          <option v-for="t in tenants" :key="t.tenant_id" :value="t.tenant_id">{{ t.name }} ({{ t.tenant_id }})</option>
        </select>
        <input v-if="selectedTenantId" v-model="searchQuery" placeholder="搜索用户..." @keyup.enter="fetchUsers" />
      </div>

      <div v-if="selectedTenantId">
        <table class="data-table">
          <thead><tr><th>ID</th><th>姓名</th><th>邮箱</th><th>状态</th><th>创建时间</th><th>操作</th></tr></thead>
          <tbody>
            <tr v-for="u in users" :key="u.user_id">
              <td>{{ u.user_id }}</td><td>{{ u.name }}</td><td>{{ u.email }}</td>
              <td><span :class="['badge', u.is_active ? 'badge-success' : 'badge-danger']">{{ u.is_active ? '激活' : '未激活' }}</span></td>
              <td>{{ formatDate(u.created_at) }}</td>
              <td>
                <button class="link-btn" @click="openEdit(u)">编辑</button>
                <button class="link-btn danger" @click="handleDelete(u)">删除</button>
              </td>
            </tr>
            <tr v-if="users.length === 0"><td colspan="6" class="empty-row">暂无用户</td></tr>
          </tbody>
        </table>

        <div v-if="totalPages > 1" class="pagination">
          <button :disabled="currentPage <= 1" @click="goPage(currentPage - 1)">上一页</button>
          <span>{{ currentPage }} / {{ totalPages }}</span>
          <button :disabled="currentPage >= totalPages" @click="goPage(currentPage + 1)">下一页</button>
        </div>
      </div>
      <div v-else class="empty-row">请先选择租户</div>
    </div>

    <div class="modal-backdrop" v-if="dialogVisible" @click="dialogVisible = false">
      <div class="modal-content" @click.stop>
        <h3>编辑用户</h3>
        <form @submit.prevent="handleSubmit">
          <div class="form-group"><label>姓名</label><input v-model="form.name" required /></div>
          <div class="form-group"><label>邮箱</label><input :value="form.email" disabled /></div>
          <div class="form-group"><label>手机号</label><input v-model="form.phone" /></div>
          <div class="form-actions">
            <button type="button" @click="dialogVisible = false">取消</button>
            <button type="submit" class="primary-btn">保存</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const tenants = ref<any[]>([])
const selectedTenantId = ref('')
const users = ref<any[]>([])
const searchQuery = ref('')
const currentPage = ref(1)
const totalPages = ref(1)
const perPage = 20
const dialogVisible = ref(false)
const editId = ref('')
const form = ref({ name: '', email: '', phone: '' })

const formatDate = (d: string) => d ? d.substring(0, 10) : '-'

const fetchTenants = async () => {
  try { const r = await axios.get('/v1/admin/tenants', { params: { per_page: 100 } }); tenants.value = r.data.data || [] } catch {}
}

const fetchUsers = async (page = 1) => {
  if (!selectedTenantId.value) return
  try {
    const params: any = { page, per_page: perPage }
    if (searchQuery.value) params.search = searchQuery.value
    const r = await axios.get(`/v1/admin/tenants/${selectedTenantId.value}/users`, { params })
    users.value = r.data.data || []
    totalPages.value = r.data.meta?.last_page ?? r.data.last_page ?? 1
    currentPage.value = page
  } catch { users.value = [] }
}

const goPage = (p: number) => fetchUsers(p)

const openEdit = (u: any) => { editId.value = u.user_id; form.value = { name: u.name, email: u.email, phone: u.phone || '' }; dialogVisible.value = true }

const handleSubmit = async () => {
  try {
    await axios.put(`/v1/admin/tenants/${selectedTenantId.value}/users/${editId.value}`, { name: form.value.name, phone: form.value.phone })
    dialogVisible.value = false; await fetchUsers(currentPage.value)
  } catch (e: any) { alert(e.response?.data?.message || '操作失败') }
}

const handleDelete = async (u: any) => {
  if (!confirm(`确定删除用户 ${u.name}？`)) return
  try { await axios.delete(`/v1/admin/tenants/${selectedTenantId.value}/users/${u.user_id}`); await fetchUsers(currentPage.value) } catch (e: any) { alert(e.response?.data?.message || '删除失败') }
}

onMounted(fetchTenants)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.filter-bar { display: flex; gap: 12px; margin-bottom: 16px; }
.filter-bar select, .filter-bar input { padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; min-width: 200px; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.empty-row { text-align: center; color: var(--text-color-secondary, #999); padding: 24px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-success { background: #e6ffed; color: #52c41a; }
.badge-danger { background: #fff1f0; color: #f5222d; }
.link-btn { background: none; border: none; color: var(--primary-color, #409eff); cursor: pointer; font-size: 13px; padding: 0 4px; }
.link-btn.danger { color: #f5222d; }
.pagination { display: flex; align-items: center; justify-content: center; gap: 16px; margin-top: 16px; }
.pagination button { padding: 6px 14px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; background: #fff; cursor: pointer; font-size: 13px; }
.pagination button:disabled { opacity: 0.4; cursor: not-allowed; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-content { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; min-width: 400px; }
.modal-content h3 { margin: 0 0 20px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; box-sizing: border-box; }
.form-group input:disabled { background: #f5f5f5; }
.form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
.form-actions button { padding: 8px 16px; border-radius: 6px; border: 1px solid var(--border-color, #ddd); background: #fff; cursor: pointer; }
</style>
