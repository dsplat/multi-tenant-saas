<template>
  <div class="page">
    <div class="page-header">
      <h2>成员管理</h2>
      <el-button type="primary" @click="showInvite = true">+ 邀请成员</el-button>
    </div>

    <el-card shadow="never">
      <el-table :data="members" stripe style="width: 100%" empty-text="暂无成员">
        <el-table-column prop="user_id" label="用户ID" width="100" />
        <el-table-column prop="name" label="姓名" width="120" />
        <el-table-column prop="email" label="邮箱" />
        <el-table-column label="角色" width="100">
          <template #default="{ row }">
            <el-tag :type="row.role === 'tenant_admin' ? 'warning' : 'info'" size="small">{{ roleLabel(row.role) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.is_active ? 'success' : 'danger'" size="small">{{ row.is_active ? '激活' : '未激活' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="加入时间" width="120">
          <template #default="{ row }">{{ formatDate(row.joined_at ?? row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="130">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" size="small" @click="handleRemove(row)">移除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="showInvite" title="邀请成员" width="420px">
      <el-form :model="inviteForm" label-width="80px">
        <el-form-item label="邮箱"><el-input v-model="inviteForm.email" type="email" /></el-form-item>
        <el-form-item label="角色">
          <el-select v-model="inviteForm.role" style="width: 100%">
            <el-option label="成员" value="end_user" />
            <el-option label="管理员" value="tenant_admin" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="showInvite = false">取消</el-button>
        <el-button type="primary" @click="handleInvite">发送邀请</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="showEdit" :title="`编辑成员 — ${editMember?.name ?? ''}`" width="420px">
      <el-form :model="editForm" label-width="80px">
        <el-form-item label="角色">
          <el-select v-model="editForm.role" style="width: 100%">
            <el-option label="成员" value="end_user" />
            <el-option label="管理员" value="tenant_admin" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="showEdit = false">取消</el-button>
        <el-button type="primary" @click="handleUpdate">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useUserStore } from '@stores/user'

const userStore = useUserStore()
const API = computed(() => `/api/v1/tenants/${userStore.tenantId}/members`)
const members = ref<any[]>([])
const showInvite = ref(false)
const inviteForm = reactive({ email: '', role: 'end_user' })
const showEdit = ref(false)
const editMember = ref<any>(null)
const editForm = reactive({ role: 'end_user' })

const formatDate = (d: string) => d ? d.substring(0, 10) : '-'
const roleLabel = (r: string) => ({ tenant_admin: '管理员', end_user: '成员', super_admin: '超级管理员' }[r] || r)

const fetchMembers = async () => {
  try {
    const r = await axios.get(API.value)
    members.value = r.data.data || []
  } catch {
    members.value = []
  }
}

const handleInvite = async () => {
  try {
    await axios.post(API.value, inviteForm)
    showInvite.value = false
    inviteForm.email = ''
    inviteForm.role = 'end_user'
    await fetchMembers()
    ElMessage.success('邀请已发送')
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '邀请失败')
  }
}

const openEdit = (m: any) => {
  editMember.value = m
  editForm.role = typeof m.role === 'string' ? m.role : m.role?.name || 'end_user'
  showEdit.value = true
}

const handleUpdate = async () => {
  try {
    await axios.put(`${API.value}/${editMember.value.user_id}`, { role: editForm.role })
    showEdit.value = false
    await fetchMembers()
    ElMessage.success('更新成功')
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '更新失败')
  }
}

const handleRemove = async (m: any) => {
  try {
    await ElMessageBox.confirm(`确定移除成员 ${m.name}？`, '警告', { type: 'warning' })
    await axios.delete(`${API.value}/${m.user_id}`)
    await fetchMembers()
    ElMessage.success('已移除')
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '移除失败')
  }
}

onMounted(fetchMembers)
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
</style>
