<template>
  <div class="page">
    <div class="page-header">
      <h2>数据保留策略</h2>
      <el-button type="primary" :icon="Plus" @click="openCreate">创建策略</el-button>
    </div>

    <el-card shadow="never">
      <el-table :data="policies" stripe style="width: 100%" empty-text="暂无策略">
        <el-table-column prop="data_type" label="数据类型" />
        <el-table-column label="保留天数" width="100">
          <template #default="{ row }">{{ row.retention_days }}天</template>
        </el-table-column>
        <el-table-column label="清理策略" width="120">
          <template #default="{ row }"><el-tag size="small">{{ row.cleanup_strategy }}</el-tag></template>
        </el-table-column>
        <el-table-column label="自动清理" width="100">
          <template #default="{ row }">
            <el-tag :type="row.auto_cleanup ? 'success' : 'danger'" size="small">{{ row.auto_cleanup ? '是' : '否' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="120">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialog" :title="isEdit ? '编辑策略' : '创建策略'" width="440px">
      <el-form :model="form" label-width="100px">
        <el-form-item label="数据类型"><el-input v-model="form.data_type" :disabled="isEdit" placeholder="audit_logs" /></el-form-item>
        <el-form-item label="保留天数"><el-input-number v-model="form.retention_days" :min="1" style="width: 100%" /></el-form-item>
        <el-form-item label="清理策略">
          <el-select v-model="form.cleanup_strategy" style="width: 100%">
            <el-option label="删除" value="delete" />
            <el-option label="匿名化" value="anonymize" />
          </el-select>
        </el-form-item>
        <el-form-item label="自动清理"><el-switch v-model="form.auto_cleanup" /></el-form-item>
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

const API = '/api/v1/admin/retention-policies'
const policies = ref<any[]>([])
const dialog = ref(false)
const isEdit = ref(false)
const editId = ref('')
const form = ref({ data_type: '', retention_days: 365, cleanup_strategy: 'anonymize', auto_cleanup: true })

const fetch = async () => { try { const r = await axios.get(API); policies.value = r.data.data || [] } catch {} }
const openCreate = () => { isEdit.value = false; form.value = { data_type: '', retention_days: 365, cleanup_strategy: 'anonymize', auto_cleanup: true }; dialog.value = true }
const openEdit = (p: any) => { isEdit.value = true; editId.value = p.id ?? p.retention_policy_id; form.value = { data_type: p.data_type, retention_days: p.retention_days, cleanup_strategy: p.cleanup_strategy, auto_cleanup: p.auto_cleanup }; dialog.value = true }

const handleSubmit = async () => {
  try {
    if (isEdit.value) await axios.put(`${API}/${editId.value}`, form.value)
    else await axios.post(API, form.value)
    dialog.value = false; await fetch()
    ElMessage.success(isEdit.value ? '更新成功' : '创建成功')
  } catch {}
}

const handleDelete = async (p: any) => {
  try {
    await ElMessageBox.confirm('确定删除？', '警告', { type: 'error' })
    await axios.delete(`${API}/${p.id ?? p.retention_policy_id}`)
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
</style>
