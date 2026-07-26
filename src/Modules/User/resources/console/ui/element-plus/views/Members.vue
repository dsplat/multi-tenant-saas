<template>
  <div class="page">
    <div class="page-header">
      <h2>成员管理</h2>
      <el-button type="primary" @click="showAdd = true">+ 添加成员</el-button>
    </div>

    <el-card shadow="never">
      <el-table :data="members" stripe style="width: 100%" empty-text="暂无成员">
        <el-table-column prop="user_id" label="用户ID" width="100" />
        <el-table-column prop="name" label="姓名" width="120" />
        <el-table-column prop="email" label="邮箱" />
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

    <el-dialog v-model="showAdd" title="添加成员" width="420px">
      <el-form :model="addForm" label-width="80px">
        <el-form-item label="用户ID"><el-input v-model="addForm.user_id" type="number" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="showAdd = false">取消</el-button>
        <el-button type="primary" @click="handleAdd">添加</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="showEdit" :title="`编辑成员 — ${editMember?.name ?? ''}`" width="420px">
      <el-form :model="editForm" label-width="80px">
        <el-form-item label="状态">
          <el-select v-model="editForm.is_active" style="width: 100%">
            <el-option label="激活" :value="true" />
            <el-option label="未激活" :value="false" />
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
const showAdd = ref(false)
const addForm = reactive({ user_id: '' })
const showEdit = ref(false)
const editMember = ref<any>(null)
const editForm = reactive({ is_active: true })

const formatDate = (d: string) => d ? d.substring(0, 10) : '-'

const fetchMembers = async () => {
  try {
    const r = await axios.get(API.value)
    members.value = r.data.data || []
  } catch {
    members.value = []
  }
}

const handleAdd = async () => {
  try {
    await axios.post(API.value, addForm)
    showAdd.value = false
    addForm.user_id = ''
    await fetchMembers()
    ElMessage.success('成员已添加')
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '添加失败')
  }
}

const openEdit = (m: any) => {
  editMember.value = m
  editForm.is_active = !!m.is_active
  showEdit.value = true
}

const handleUpdate = async () => {
  try {
    await axios.put(`${API.value}/${editMember.value.user_id}`, { is_active: editForm.is_active })
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
