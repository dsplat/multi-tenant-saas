<template>
  <div class="page">
    <div class="page-header">
      <h2>角色权限</h2>
      <el-button type="primary" :icon="Plus" @click="showCreate = true">创建角色</el-button>
    </div>

    <el-card shadow="never">
      <el-table :data="roles" stripe style="width: 100%" empty-text="暂无角色">
        <el-table-column prop="role_id" label="ID" width="80" />
        <el-table-column prop="name" label="角色名" />
        <el-table-column prop="display_name" label="显示名" />
        <el-table-column label="描述">
          <template #default="{ row }">{{ row.description || '-' }}</template>
        </el-table-column>
        <el-table-column label="权限数" width="100">
          <template #default="{ row }">{{ row.permissions_count ?? '-' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="140">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="editPerms(row)">权限</el-button>
            <el-button link type="danger" size="small" @click="deleteRole(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="showCreate" title="创建角色" width="420px">
      <el-form :model="form" label-width="80px">
        <el-form-item label="角色名"><el-input v-model="form.name" /></el-form-item>
        <el-form-item label="显示名"><el-input v-model="form.display_name" /></el-form-item>
        <el-form-item label="描述"><el-input v-model="form.description" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="showCreate = false">取消</el-button>
        <el-button type="primary" @click="handleCreate">创建</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="permVisible" :title="`编辑权限 — ${permRole?.display_name || ''}`" width="600px">
      <el-checkbox-group v-model="selectedPerms" class="perm-list">
        <el-checkbox v-for="p in allPermissions" :key="p.permission_id" :value="p.permission_id" class="perm-item">
          {{ p.name }}
        </el-checkbox>
      </el-checkbox-group>
      <template #footer>
        <el-button @click="permVisible = false">取消</el-button>
        <el-button type="primary" @click="savePerms">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { Plus } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from 'element-plus'

const roles = ref<any[]>([])
const allPermissions = ref<any[]>([])
const showCreate = ref(false)
const form = ref({ name: '', display_name: '', description: '' })
const permRole = ref<any>(null)
const selectedPerms = ref<number[]>([])

const permVisible = computed({
  get: () => !!permRole.value,
  set: (v) => { if (!v) permRole.value = null }
})

const fetchRoles = async () => {
  try { const r = await axios.get('/v1/admin/auth/roles'); roles.value = r.data.data || [] } catch {}
}

const fetchPerms = async () => {
  try { const r = await axios.get('/v1/admin/auth/permissions'); allPermissions.value = r.data.data?.flat?.() || r.data.data || [] } catch {}
}

const handleCreate = async () => {
  try {
    await axios.post('/v1/admin/auth/roles', form.value)
    showCreate.value = false
    form.value = { name: '', display_name: '', description: '' }
    await fetchRoles()
    ElMessage.success('创建成功')
  } catch {}
}

const deleteRole = async (r: any) => {
  try {
    await ElMessageBox.confirm(`确定删除角色 ${r.display_name}？`, '警告', { type: 'error' })
    await axios.delete(`/v1/admin/auth/roles/${r.role_id}`)
    await fetchRoles()
    ElMessage.success('删除成功')
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '删除失败')
  }
}

const editPerms = (r: any) => {
  permRole.value = r
  selectedPerms.value = (r.permissions || []).map((p: any) => p.permission_id ?? p)
  fetchPerms()
}

const savePerms = async () => {
  try {
    await axios.put(`/v1/admin/auth/roles/${permRole.value.role_id}/permissions`, { permissions: selectedPerms.value })
    permRole.value = null
    await fetchRoles()
    ElMessage.success('权限已更新')
  } catch {}
}

onMounted(() => { fetchRoles(); fetchPerms() })
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.perm-list { display: flex; flex-wrap: wrap; gap: 8px; max-height: 300px; overflow-y: auto; }
.perm-item { margin-right: 8px; }
</style>
