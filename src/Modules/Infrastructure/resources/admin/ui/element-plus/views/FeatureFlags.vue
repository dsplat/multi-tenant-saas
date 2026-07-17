<template>
  <div class="page">
    <div class="page-header">
      <h2>功能开关</h2>
      <el-button type="primary" :icon="Plus" @click="openCreate">创建开关</el-button>
    </div>

    <el-card shadow="never">
      <div class="filter-bar">
        <el-select v-model="filters.scope" placeholder="全部范围" clearable style="width: 140px" @change="fetch">
          <el-option label="全局" value="global" />
          <el-option label="租户" value="tenant" />
        </el-select>
        <el-select v-model="filters.status" placeholder="全部状态" clearable style="width: 140px" @change="fetch">
          <el-option label="启用" value="active" />
          <el-option label="停用" value="inactive" />
        </el-select>
      </div>

      <el-table :data="flags" stripe style="width: 100%" empty-text="暂无功能开关">
        <el-table-column label="名称">
          <template #default="{ row }"><strong>{{ row.name }}</strong></template>
        </el-table-column>
        <el-table-column label="描述">
          <template #default="{ row }">{{ row.description || '-' }}</template>
        </el-table-column>
        <el-table-column label="范围" width="100">
          <template #default="{ row }"><el-tag size="small">{{ row.scope }}</el-tag></template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.status === 'active' ? 'success' : 'danger'" size="small">{{ row.status === 'active' ? '启用' : '停用' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="灰度%" width="80">
          <template #default="{ row }">{{ row.rollout_percentage ?? 100 }}%</template>
        </el-table-column>
        <el-table-column label="操作" width="180">
          <template #default="{ row }">
            <el-button link size="small" @click="toggleFlag(row)">{{ row.status === 'active' ? '停用' : '启用' }}</el-button>
            <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialog" :title="isEdit ? '编辑功能开关' : '创建功能开关'" width="440px">
      <el-form :model="form" label-width="100px">
        <el-form-item label="名称"><el-input v-model="form.name" :disabled="isEdit" /></el-form-item>
        <el-form-item label="描述"><el-input v-model="form.description" /></el-form-item>
        <el-form-item label="范围">
          <el-select v-model="form.scope" style="width: 100%">
            <el-option label="全局" value="global" />
            <el-option label="租户" value="tenant" />
          </el-select>
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="form.status" style="width: 100%">
            <el-option label="启用" value="active" />
            <el-option label="停用" value="inactive" />
          </el-select>
        </el-form-item>
        <el-form-item label="灰度比例 (%)">
          <el-input-number v-model="form.rollout_percentage" :min="0" :max="100" style="width: 100%" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" @click="handleSubmit">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { Plus } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from 'element-plus'

const API = '/api/v1/admin/feature-flags'
const flags = ref<any[]>([])
const dialog = ref(false)
const isEdit = ref(false)
const editId = ref('')
const filters = ref({ scope: '', status: '' })
const form = ref({ name: '', description: '', scope: 'global', status: 'active', rollout_percentage: 100 })

const fetch = async () => { try { const r = await axios.get(API, { params: filters.value }); flags.value = r.data.data || [] } catch {} }
const openCreate = () => { isEdit.value = false; form.value = { name: '', description: '', scope: 'global', status: 'active', rollout_percentage: 100 }; dialog.value = true }
const openEdit = (f: any) => { isEdit.value = true; editId.value = f.id ?? f.feature_flag_id; form.value = { name: f.name, description: f.description || '', scope: f.scope, status: f.status, rollout_percentage: f.rollout_percentage ?? 100 }; dialog.value = true }

const handleSubmit = async () => {
  try {
    if (isEdit.value) await axios.put(`${API}/${editId.value}`, form.value)
    else await axios.post(API, form.value)
    dialog.value = false; await fetch()
    ElMessage.success(isEdit.value ? '更新成功' : '创建成功')
  } catch {}
}

const toggleFlag = async (f: any) => {
  try { await axios.post(`${API}/${f.id ?? f.feature_flag_id}/toggle`); await fetch() } catch {}
}

const handleDelete = async (f: any) => {
  try {
    await ElMessageBox.confirm(`确定删除功能开关 ${f.name}？`, '警告', { type: 'error' })
    await axios.delete(`${API}/${f.id ?? f.feature_flag_id}`)
    await fetch()
    ElMessage.success('删除成功')
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '删除失败')
  }
}

onMounted(fetch)
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.filter-bar { display: flex; gap: 12px; margin-bottom: 16px; }
</style>
