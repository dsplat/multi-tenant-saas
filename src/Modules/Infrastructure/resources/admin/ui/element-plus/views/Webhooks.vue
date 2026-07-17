<template>
  <div class="page">
    <div class="page-header">
      <h2>Webhooks</h2>
      <el-button type="primary" :icon="Plus" @click="openCreate">创建 Webhook</el-button>
    </div>

    <el-card shadow="never">
      <el-table :data="webhooks" stripe style="width: 100%" empty-text="暂无 Webhooks">
        <el-table-column label="ID" width="80">
          <template #default="{ row }">{{ row.webhook_id ?? row.id }}</template>
        </el-table-column>
        <el-table-column prop="url" label="URL" min-width="200" show-overflow-tooltip />
        <el-table-column label="事件" width="200">
          <template #default="{ row }">
            <el-tag v-for="e in (row.events || []).slice(0,3)" :key="e" size="small" style="margin-right: 4px">{{ e }}</el-tag>
            <el-tag v-if="(row.events||[]).length > 3" size="small" type="info">+{{ (row.events||[]).length - 3 }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.is_active !== false ? 'success' : 'danger'" size="small">{{ row.is_active !== false ? '活跃' : '禁用' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="180">
          <template #default="{ row }">
            <el-button link size="small" @click="testWebhook(row)">测试</el-button>
            <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑 Webhook' : '创建 Webhook'" width="460px">
      <el-form :model="form" label-width="100px">
        <el-form-item label="URL"><el-input v-model="form.url" placeholder="https://example.com/webhook" /></el-form-item>
        <el-form-item label="事件"><el-input v-model="eventsInput" placeholder="tenant.created,user.registered" /></el-form-item>
        <el-form-item label="描述"><el-input v-model="form.description" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" @click="handleSubmit">确定</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="testResultVisible" title="测试结果" width="560px">
      <pre class="test-output">{{ JSON.stringify(testResult, null, 2) }}</pre>
      <template #footer>
        <el-button @click="testResultVisible = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { Plus } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from 'element-plus'

const API = '/api/v1/admin/webhooks'
const webhooks = ref<any[]>([])
const dialogVisible = ref(false)
const isEdit = ref(false)
const editId = ref<string|number>('')
const form = ref({ url: '', events: [] as string[], description: '' })
const eventsInput = ref('')
const testResult = ref<any>(null)
const testResultVisible = ref(false)

const fetchWebhooks = async () => { try { const r = await axios.get(API); webhooks.value = r.data.data || [] } catch {} }

const openCreate = () => { isEdit.value = false; form.value = { url: '', events: [], description: '' }; eventsInput.value = ''; dialogVisible.value = true }
const openEdit = (w: any) => { isEdit.value = true; editId.value = w.webhook_id ?? w.id; form.value = { url: w.url, events: w.events || [], description: w.description || '' }; eventsInput.value = (w.events || []).join(','); dialogVisible.value = true }

const handleSubmit = async () => {
  const payload = { ...form.value, events: eventsInput.value.split(',').map(s => s.trim()).filter(Boolean) }
  try {
    if (isEdit.value) await axios.put(`${API}/${editId.value}`, payload)
    else await axios.post(API, payload)
    dialogVisible.value = false; await fetchWebhooks()
    ElMessage.success(isEdit.value ? '更新成功' : '创建成功')
  } catch {}
}

const handleDelete = async (w: any) => {
  try {
    await ElMessageBox.confirm('确定删除该 Webhook？', '警告', { type: 'error' })
    await axios.delete(`${API}/${w.webhook_id ?? w.id}`)
    await fetchWebhooks()
    ElMessage.success('删除成功')
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '删除失败')
  }
}

const testWebhook = async (w: any) => {
  try { const r = await axios.post(`${API}/${w.webhook_id ?? w.id}/test`); testResult.value = r.data; testResultVisible.value = true }
  catch (e: any) { testResult.value = { error: e.response?.data?.message || e.message }; testResultVisible.value = true }
}

onMounted(fetchWebhooks)
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.test-output { background: #f5f5f5; padding: 12px; border-radius: 6px; font-size: 12px; overflow-x: auto; max-height: 300px; }
</style>
