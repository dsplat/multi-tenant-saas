<template>
  <div class="page">
    <div class="page-header">
      <h2>租户管理</h2>
      <el-button type="primary" :icon="Plus" @click="openCreate">创建租户</el-button>
    </div>

    <el-card shadow="never">
      <div class="filter-bar">
        <el-input v-model="filters.search" placeholder="搜索租户名称..." clearable
          style="width: 240px" @keyup.enter="fetchTenants" />
        <el-select v-model="filters.status" placeholder="全部状态" clearable
          style="width: 140px" @change="fetchTenants">
          <el-option label="全部状态" value="" />
          <el-option label="活跃" value="active" />
          <el-option label="未激活" value="inactive" />
          <el-option label="已暂停" value="suspended" />
        </el-select>
        <el-button type="primary" @click="fetchTenants">查询</el-button>
      </div>

      <el-table :data="tenants" stripe style="width: 100%" empty-text="暂无租户">
        <el-table-column prop="tenant_id" label="ID" width="80" />
        <el-table-column prop="name" label="名称" />
        <el-table-column prop="slug" label="标识" />
        <el-table-column label="自定义域名">
          <template #default="{ row }">{{ row.custom_domain || '-' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="statusTagType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="套餐" width="100">
          <template #default="{ row }">{{ row.subscription_plan || '-' }}</template>
        </el-table-column>
        <el-table-column label="创建时间" width="120">
          <template #default="{ row }">{{ formatDate(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
            <el-button v-if="row.status !== 'suspended'" link type="warning" size="small" @click="handleSuspend(row)">暂停</el-button>
            <el-button v-if="row.status === 'suspended'" link type="success" size="small" @click="handleActivate(row)">激活</el-button>
            <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination v-if="totalPages > 1" v-model:current-page="currentPage" :page-size="perPage"
        :total="totalPages * perPage" layout="prev, pager, next" style="margin-top: 16px; justify-content: center"
        @current-change="fetchTenants" />
    </el-card>

    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑租户' : '创建租户'" width="480px">
      <el-form :model="form" label-width="100px">
        <el-form-item label="名称"><el-input v-model="form.name" /></el-form-item>
        <el-form-item label="标识"><el-input v-model="form.slug" :disabled="isEdit" /></el-form-item>
        <el-form-item label="自定义域名"><el-input v-model="form.custom_domain" placeholder="example.com" /></el-form-item>
        <el-form-item label="套餐">
          <el-select v-model="form.subscription_plan" style="width: 100%">
            <el-option label="免费版" value="free" />
            <el-option label="基础版" value="basic" />
            <el-option label="专业版" value="pro" />
            <el-option label="企业版" value="enterprise" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="form.status" style="width: 100%">
            <el-option label="活跃" value="active" />
            <el-option label="未激活" value="inactive" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" @click="handleSubmit">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'
import { Plus } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from 'element-plus'

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

const statusTagType = (s: string) => ({ active: 'success', inactive: 'info', suspended: 'danger' }[s] || 'info') as any
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

const openCreate = () => {
  isEdit.value = false
  Object.assign(form, { name: '', slug: '', custom_domain: '', status: 'active', subscription_plan: 'free' })
  dialogVisible.value = true
}

const openEdit = (t: any) => {
  isEdit.value = true
  editId.value = t.tenant_id
  Object.assign(form, { name: t.name, slug: t.slug, custom_domain: t.custom_domain || '', status: t.status, subscription_plan: t.subscription_plan || 'free' })
  dialogVisible.value = true
}

const handleSubmit = async () => {
  try {
    if (isEdit.value) await axios.put(`${API}/${editId.value}`, form)
    else await axios.post(API, form)
    dialogVisible.value = false
    await fetchTenants(currentPage.value)
    ElMessage.success(isEdit.value ? '更新成功' : '创建成功')
  } catch (e: any) { ElMessage.error(e.response?.data?.message || '操作失败') }
}

const handleSuspend = async (t: any) => {
  try {
    await ElMessageBox.confirm(`确定暂停租户 ${t.name}？`, '提示', { type: 'warning' })
    await axios.post(`${API}/${t.tenant_id}/suspend`)
    await fetchTenants(currentPage.value)
    ElMessage.success('已暂停')
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '操作失败')
  }
}

const handleActivate = async (t: any) => {
  try {
    await axios.post(`${API}/${t.tenant_id}/activate`)
    await fetchTenants(currentPage.value)
    ElMessage.success('已激活')
  } catch (e: any) { ElMessage.error(e.response?.data?.message || '操作失败') }
}

const handleDelete = async (t: any) => {
  try {
    await ElMessageBox.confirm(`确定删除租户 ${t.name}？此操作不可恢复！`, '警告', { type: 'error' })
    await axios.delete(`${API}/${t.tenant_id}`)
    await fetchTenants(currentPage.value)
    ElMessage.success('删除成功')
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '删除失败')
  }
}

onMounted(() => fetchTenants())
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.filter-bar { display: flex; gap: 12px; margin-bottom: 16px; }
</style>
