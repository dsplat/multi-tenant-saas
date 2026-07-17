<template>
  <div class="page">
    <div class="page-header"><h2>用户管理</h2></div>

    <el-empty v-if="!tenantStore.hasTenant" description="请先选择租户">
      <template #image>
        <el-icon :size="48"><OfficeBuilding /></el-icon>
      </template>
      <p style="color: var(--text-color-secondary); font-size: 13px">在顶部导航栏选择一个租户后，即可管理该租户的用户。</p>
    </el-empty>

    <el-card v-else shadow="never">
      <div class="filter-bar">
        <el-input v-model="searchQuery" placeholder="搜索用户..." clearable
          style="width: 240px" @keyup.enter="fetchUsers" />
        <el-button type="primary" @click="fetchUsers">查询</el-button>
      </div>

      <el-table :data="users" stripe style="width: 100%" empty-text="暂无用户">
        <el-table-column prop="user_id" label="ID" width="80" />
        <el-table-column prop="name" label="姓名" />
        <el-table-column prop="email" label="邮箱" />
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="row.is_active ? 'success' : 'danger'" size="small">
              {{ row.is_active ? '激活' : '未激活' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="创建时间" width="120">
          <template #default="{ row }">{{ formatDate(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="120">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination v-if="totalPages > 1" v-model:current-page="currentPage" :page-size="perPage"
        :total="totalPages * perPage" layout="prev, pager, next" style="margin-top: 16px; justify-content: center"
        @current-change="fetchUsers" />
    </el-card>

    <el-dialog v-model="dialogVisible" title="编辑用户" width="420px">
      <el-form :model="form" label-width="80px">
        <el-form-item label="姓名"><el-input v-model="form.name" /></el-form-item>
        <el-form-item label="邮箱"><el-input :model-value="form.email" disabled /></el-form-item>
        <el-form-item label="手机号"><el-input v-model="form.phone" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" @click="handleSubmit">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, watch, onMounted } from 'vue'
import axios from 'axios'
import { OfficeBuilding } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useTenantStore } from '@/admin/stores/tenant'

const tenantStore = useTenantStore()
const users = ref<any[]>([])
const searchQuery = ref('')
const currentPage = ref(1)
const totalPages = ref(1)
const perPage = 20
const dialogVisible = ref(false)
const editId = ref('')
const form = ref({ name: '', email: '', phone: '' })

const formatDate = (d: string) => d ? d.substring(0, 10) : '-'

const fetchUsers = async (page = 1) => {
  if (!tenantStore.hasTenant) return
  try {
    const params: any = { page, per_page: perPage }
    if (searchQuery.value) params.search = searchQuery.value
    const r = await axios.get(`/api/v1/admin/tenants/${tenantStore.tenantId}/users`, { params })
    users.value = r.data.data || []
    totalPages.value = r.data.meta?.last_page ?? r.data.last_page ?? 1
    currentPage.value = page
  } catch { users.value = [] }
}

const openEdit = (u: any) => {
  editId.value = u.user_id
  form.value = { name: u.name, email: u.email, phone: u.phone || '' }
  dialogVisible.value = true
}

const handleSubmit = async () => {
  try {
    await axios.put(`/api/v1/admin/tenants/${tenantStore.tenantId}/users/${editId.value}`, { name: form.value.name, phone: form.value.phone })
    dialogVisible.value = false
    await fetchUsers(currentPage.value)
    ElMessage.success('保存成功')
  } catch (e: any) { ElMessage.error(e.response?.data?.message || '操作失败') }
}

const handleDelete = async (u: any) => {
  try {
    await ElMessageBox.confirm(`确定删除用户 ${u.name}？`, '警告', { type: 'error' })
    await axios.delete(`/api/v1/admin/tenants/${tenantStore.tenantId}/users/${u.user_id}`)
    await fetchUsers(currentPage.value)
    ElMessage.success('删除成功')
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '删除失败')
  }
}

onMounted(() => { if (tenantStore.hasTenant) fetchUsers() })
watch(() => tenantStore.tenantId, () => { if (tenantStore.hasTenant) fetchUsers(); else users.value = [] })
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.filter-bar { display: flex; gap: 12px; margin-bottom: 16px; }
</style>
