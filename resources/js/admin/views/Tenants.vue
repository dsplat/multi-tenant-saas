<template>
  <div class="tenants-page">
    <div class="page-header">
      <h2>租户管理</h2>
      <button class="primary-btn" @click="handleCreate">+ 创建租户</button>
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
          <tr>
            <th>ID</th>
            <th>名称</th>
            <th>标识</th>
            <th>自定义域名</th>
            <th>状态</th>
            <th>套餐</th>
            <th>创建时间</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="t in tenants" :key="t.tenant_id">
            <td>{{ t.tenant_id }}</td>
            <td>{{ t.name }}</td>
            <td>{{ t.slug }}</td>
            <td>{{ t.custom_domain || '-' }}</td>
            <td>
              <span :class="['badge', statusClass(t.status)]">{{ statusLabel(t.status) }}</span>
            </td>
            <td>{{ t.subscription_plan }}</td>
            <td>{{ t.created_at }}</td>
            <td>
              <button class="link-btn" @click="handleEdit(t)">编辑</button>
              <button class="link-btn danger" @click="handleDelete(t)">删除</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- 简单对话框 -->
    <div class="modal-backdrop" v-if="dialogVisible" @click="dialogVisible = false">
      <div class="modal-content" @click.stop>
        <h3>{{ isEdit ? '编辑租户' : '创建租户' }}</h3>
        <form @submit.prevent="handleSubmit">
          <div class="form-group">
            <label>名称</label>
            <input v-model="form.name" required />
          </div>
          <div class="form-group">
            <label>标识</label>
            <input v-model="form.slug" required :disabled="isEdit" />
          </div>
          <div class="form-group">
            <label>自定义域名</label>
            <input v-model="form.custom_domain" />
          </div>
          <div class="form-group">
            <label>状态</label>
            <select v-model="form.status">
              <option value="active">活跃</option>
              <option value="inactive">未激活</option>
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

const loading = ref(false)
const dialogVisible = ref(false)
const isEdit = ref(false)
const tenants = ref<any[]>([])

const filters = reactive({ search: '', status: '' })
const form = reactive({ tenant_id: '', name: '', slug: '', custom_domain: '', status: 'active', subscription_plan: 'free', total_credits: 0 })

const statusClass = (s: string) => ({ active: 'badge-success', inactive: 'badge-info', suspended: 'badge-danger' }[s] || 'badge-info')
const statusLabel = (s: string) => ({ active: '活跃', inactive: '未激活', suspended: '已暂停' }[s] || s)

const fetchTenants = async () => {
  loading.value = true
  try {
    const res = await axios.get('/api/v1/tenants', { params: { ...filters, per_page: 20 } })
    tenants.value = res.data.data || []
  } catch { tenants.value = [] }
  finally { loading.value = false }
}

const handleCreate = () => {
  isEdit.value = false
  Object.assign(form, { tenant_id: '', name: '', slug: '', custom_domain: '', status: 'active', subscription_plan: 'free', total_credits: 0 })
  dialogVisible.value = true
}

const handleEdit = (row: any) => {
  isEdit.value = true
  Object.assign(form, row)
  dialogVisible.value = true
}

const handleDelete = async (row: any) => {
  if (!confirm(`确定删除 ${row.name}？`)) return
  await axios.delete(`/api/v1/tenants/${row.tenant_id}`)
  fetchTenants()
}

const handleSubmit = async () => {
  if (isEdit.value) await axios.put(`/api/v1/tenants/${form.tenant_id}`, form)
  else await axios.post('/api/v1/tenants', form)
  dialogVisible.value = false
  fetchTenants()
}

onMounted(fetchTenants)
</script>

<style scoped>
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 20px;
}

.page-header h2 { margin: 0; font-size: 18px; }

.primary-btn {
  padding: 8px 16px;
  border: none;
  border-radius: 6px;
  background: var(--primary-color, #409eff);
  color: #fff;
  cursor: pointer;
  font-size: 13px;
}

.panel {
  background: var(--bg-color, #fff);
  border-radius: 8px;
  padding: 20px;
  box-shadow: 0 1px 4px rgba(0,0,0,0.08);
}

.filter-bar {
  display: flex;
  gap: 12px;
  margin-bottom: 16px;
}

.filter-bar input, .filter-bar select {
  padding: 8px 12px;
  border: 1px solid var(--border-color, #ddd);
  border-radius: 6px;
  font-size: 13px;
  background: var(--bg-color, #fff);
  color: var(--text-color-primary, #333);
}

.filter-bar button {
  padding: 8px 16px;
  border: 1px solid var(--border-color, #ddd);
  border-radius: 6px;
  background: var(--bg-color, #fff);
  cursor: pointer;
}

.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.data-table th { color: var(--text-color-secondary, #999); font-weight: 500; }

.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-success { background: #e8f5e9; color: #2e7d32; }
.badge-info { background: #eee; color: #666; }
.badge-danger { background: #fce4ec; color: #c62828; }

.link-btn { background: none; border: none; color: var(--primary-color, #409eff); cursor: pointer; font-size: 13px; margin-right: 8px; }
.link-btn.danger { color: #f56c6c; }

.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 3000; }
.modal-content { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; width: 480px; max-height: 80vh; overflow-y: auto; }
.modal-content h3 { margin: 0 0 20px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input, .form-group select { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 13px; box-sizing: border-box; background: var(--bg-color, #fff); color: var(--text-color-primary, #333); }
.form-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 20px; }
.form-actions button { padding: 8px 16px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; cursor: pointer; }
</style>
