<template>
  <div class="page">
    <div class="page-header">
      <h2>工作流管理</h2>
      <el-button type="primary" @click="openCreate">+ 创建工作流</el-button>
    </div>

    <el-card shadow="never">
      <el-table :data="workflows" stripe style="width: 100%" empty-text="暂无工作流">
        <el-table-column label="ID" width="80">
          <template #default="{ row }">{{ row.workflow_id ?? row.id }}</template>
        </el-table-column>
        <el-table-column prop="name" label="名称" />
        <el-table-column label="类型" width="100">
          <template #default="{ row }">{{ row.type || '-' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="statusType(row.status)" size="small">{{ row.status || 'draft' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="创建时间" width="180" />
        <el-table-column label="操作" width="170">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="executeWorkflow(row)">执行</el-button>
            <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑工作流' : '创建工作流'" width="560px">
      <el-form :model="form" label-width="100px">
        <el-form-item label="名称"><el-input v-model="form.name" /></el-form-item>
        <el-form-item label="描述"><el-input v-model="form.description" /></el-form-item>
        <el-form-item label="定义（JSON）">
          <el-input v-model="definitionInput" type="textarea" :rows="8" placeholder='[{"type":"action","config":{}}]' />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" @click="handleSubmit">确定</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="showExecResult" title="执行结果" width="500px">
      <el-input v-if="execResult" :model-value="JSON.stringify(execResult, null, 2)" type="textarea" :rows="10" readonly style="font-family: monospace" />
      <template #footer>
        <el-button @click="showExecResult = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'

const API = '/tenant/workflows'
const workflows = ref<any[]>([])
const dialogVisible = ref(false)
const isEdit = ref(false)
const editId = ref('')
const form = ref({ name: '', description: '', steps: [] as any[] })
const definitionInput = ref('[]')
const execResult = ref<any>(null)
const showExecResult = ref(false)

const statusType = (s: string) => ({ active: 'success', draft: 'info', paused: 'warning', error: 'danger' }[s] || 'info')

const fetchWorkflows = async () => {
  try {
    const r = await axios.get(API)
    workflows.value = r.data.data || []
  } catch {}
}

const openCreate = () => {
  isEdit.value = false
  form.value = { name: '', description: '', steps: [] }
  definitionInput.value = '[]'
  dialogVisible.value = true
}

const openEdit = (w: any) => {
  isEdit.value = true
  editId.value = w.workflow_id ?? w.id
  form.value = { name: w.name, description: w.description || '', steps: w.steps || [] }
  definitionInput.value = JSON.stringify(w.steps || w.definition || [], null, 2)
  dialogVisible.value = true
}

const handleSubmit = async () => {
  try {
    let steps: any[]
    try {
      steps = JSON.parse(definitionInput.value)
    } catch {
      ElMessage.error('JSON 格式错误')
      return
    }
    const payload = { ...form.value, steps, definition: steps }
    if (isEdit.value) {
      await axios.put(`${API}/${editId.value}`, payload)
    } else {
      await axios.post(API, payload)
    }
    dialogVisible.value = false
    await fetchWorkflows()
    ElMessage.success(isEdit.value ? '更新成功' : '创建成功')
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '操作失败')
  }
}

const handleDelete = async (w: any) => {
  try {
    await ElMessageBox.confirm(`确定删除工作流 ${w.name}？`, '警告', { type: 'warning' })
    await axios.delete(`${API}/${w.workflow_id ?? w.id}`)
    await fetchWorkflows()
    ElMessage.success('已删除')
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '删除失败')
  }
}

const executeWorkflow = async (w: any) => {
  try {
    const r = await axios.post(`${API}/${w.workflow_id ?? w.id}/execute`, {})
    execResult.value = r.data
  } catch (e: any) {
    execResult.value = { error: e.response?.data?.message || e.message }
  }
  showExecResult.value = true
}

onMounted(fetchWorkflows)
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
</style>
