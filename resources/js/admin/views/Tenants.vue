<template>
  <div class="page">
    <div class="page-header">
      <h2>租户管理</h2>
      <button class="primary-btn" @click="openCreate">+ 创建租户</button>
    </div>

    <div class="panel">
      <div class="filter-bar">
        <input v-model="filters.search" placeholder="搜索租户名称..." @keyup.enter="fetchTenants" />
        <select v-model="filters.status" @change="fetchTenants">
          <option value="">全部状态</option>
          <option value="active">活跃</option>
          <option value="inactive">未激活</option>
          <option value="suspended">已暂停</option>
        </select>
        <button @click="fetchTenants">查询</button>
      </div>

      <table class="data-table">
        <thead>
          <tr><th>ID</th><th>名称</th><th>标识</th><th>自定义域名</th><th>状态</th><th>套餐</th><th>创建时间</th><th>操作</th></tr>
        </thead>
        <tbody>
          <tr v-for="t in tenants" :key="t.tenant_id">
            <td>{{ t.tenant_id }}</td><td>{{ t.name }}</td><td>{{ t.slug }}</td>
            <td>{{ t.custom_domain || '-' }}</td>
            <td><span :class="['badge', statusClass(t.status)]">{{ statusLabel(t.status) }}</span></td>
            <td>{{ t.subscription_plan || '-' }}</td><td>{{ formatDate(t.created_at) }}</td>
            <td>
              <button class="link-btn" @click="openEdit(t)">编辑</button>
              <button v-if="t.status !== 'suspended'" class="link-btn warning" @click="handleSuspend(t)">暂停</button>
              <button v-if="t.status === 'suspended'" class="link-btn" @click="handleActivate(t)">激活</button>
              <button class="link-btn danger" @click="handleDelete(t)">删除</button>
            </td>
          </tr>
          <tr v-if="tenants.length === 0"><td colspan="8" class="empty-row">暂无租户</td></tr>
        </tbody>
      </table>

      <div v-if="totalPages > 1" class="pagination">
        <button :disabled="currentPage <= 1" @click="goPage(currentPage - 1)">上一页</button>
        <span>{{ currentPage }} / {{ totalPages }}</span>
        <button :disabled="currentPage >= totalPages" @click="goPage(currentPage + 1)">下一页</button>
      </div>
    </div>

    <div class="modal-backdrop" v-if="dialogVisible" @click="dialogVisible = false">
      <div class="modal-content" @click.stop>
        <h3>{{ isEdit ? '编辑租户' : '创建租户' }}</h3>
        <form @submit.prevent="handleSubmit">
          <div class="form-group"><label>名称</label><input v-model="form.name" required /></div>
          <div class="form-group"><label>标识</label><input v-model="form.slug" required :disabled="isEdit" /></div>
          <div class="form-group"><label>自定义域名</label><input v-model="form.custom_domain" placeholder="example.com" /></div>
          <div class="form-group"><label>套餐</label>
            <select v-model="form.subscription_plan">
              <option value="free">免费版</option><option value="basic">基础版</option>
              <option value="pro">专业版</option><option value="enterprise">企业版</option>
            </select>
          </div>
          <div class="form-group"><label>状态</label>
            <select v-model="form.status">
              <option value="active">活跃</option><option value="inactive">未激活</option>
            </select>
          </div>
          <div class="form-actions">
            <button type="button" @click="dialogVisible = false">取消</button>
            <button type="submit" class="primary-btn">确定</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'

const API = '/api/v1/tenants'
const tenants = ref<any[]>([])
const dialogVisible = ref(false)
const isEdit = ref(false)
const editId = ref('')
const currentPage = ref(1)
const totalPages = ref(1)
const perPage = 20

const filters = reactive({ search: '', status: '' })
const form = reactive({ name: '', slug: '', custom_domain: '', status: 'active', subscription_plan: 'free' })

const statusClass = (s: string) => ({ active: 'badge-success', inactive: 'badge-info', suspended: 'badge-danger' }[s] || 'badge-info')
const statusLabel = (s: string) => ({ active: '活跃', inactive: '未激活', suspended: '已暂停' }[s] || s)
const formatDate = (d: string) => d ? d.substring(0, 10) : '-'

const fetchTenants = async (page = 1) => {
  try {
    const res = await axios.get(API, { params: { ...filters, page, per_page: perPage } })
    tenants.value = res.data.data || []
    totalPages.value = res.data.meta?.last_page ?? res.data.last_page ?? 1
    currentPage.value = page
  } catch { tenants.value = [] }
}

const goPage = (p: number) => fetchTenants(p)

const openCreate = () => { isEdit.value = false; Object.assign(form, { name: '', slug: '', custom_domain: '', status: 'active', subscription_plan: 'free' }); dialogVisible.value = true }
const openEdit = (t: any) => { isEdit.value = true; editId.value = t.tenant_id; Object.assign(form, { name: t.name, slug: t.slug, custom_domain: t.custom_domain || '', status: t.status, subscription_plan: t.subscription_plan || 'free' }); dialogVisible.value = true }

const handleSubmit = async () => {
  try {
    if (isEdit.value) await axios.put(`${API}/${editId.value}`, form)
    else await axios.post(API, form)
    dialogVisible.value = false; await fetchTenants(currentPage.value)
  } catch (e: any) { alert(e.response?.data?.message || '操作失败') }
}

const handleSuspend = async (t: any) => {
  if (!confirm(`确定暂停租户 ${t.name}？`)) return
  try { await axios.post(`${API}/${t.tenant_id}/suspend`); await fetchTenants(currentPage.value) } catch (e: any) { alert(e.response?.data?.message || '操作失败') }
}

const handleActivate = async (t: any) => {
  try { await axios.post(`${API}/${t.tenant_id}/activate`); await fetchTenants(currentPage.value) } catch (e: any) { alert(e.response?.data?.message || '操作失败') }
}

const handleDelete = async (t: any) => {
  if (!confirm(`确定删除租户 ${t.name}？此操作不可恢复！`)) return
  try { await axios.delete(`${API}/${t.tenant_id}`); await fetchTenants(currentPage.value) } catch (e: any) { alert(e.response?.data?.message || '删除失败') }
}

onMounted(() => fetchTenants())
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.primary-btn { padding: 8px 16px; background: var(--primary-color, #409eff); color: #fff; border: none; border-radius: 6px; cursor: pointer; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.filter-bar { display: flex; gap: 12px; margin-bottom: 16px; }
.filter-bar input, .filter-bar select { padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; }
.filter-bar button { padding: 8px 16px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; background: #fff; cursor: pointer; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.empty-row { text-align: center; color: var(--text-color-secondary, #999); padding: 24px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-success { background: #e6ffed; color: #52c41a; }
.badge-info { background: #eee; color: #666; }
.badge-danger { background: #fff1f0; color: #f5222d; }
.link-btn { background: none; border: none; color: var(--primary-color, #409eff); cursor: pointer; font-size: 13px; padding: 0 4px; }
.link-btn.danger { color: #f5222d; }
.link-btn.warning { color: #fa8c16; }
.pagination { display: flex; align-items: center; justify-content: center; gap: 16px; margin-top: 16px; }
.pagination button { padding: 6px 14px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; background: #fff; cursor: pointer; font-size: 13px; }
.pagination button:disabled { opacity: 0.4; cursor: not-allowed; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-content { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; min-width: 460px; }
.modal-content h3 { margin: 0 0 20px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input, .form-group select { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; box-sizing: border-box; }
.form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
.form-actions button { padding: 8px 16px; border-radius: 6px; border: 1px solid var(--border-color, #ddd); background: #fff; cursor: pointer; }
</style>
