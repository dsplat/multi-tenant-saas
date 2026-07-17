<template>
  <div class="page">
    <div class="page-header">
      <h2>Webhooks</h2>
      <el-button type="primary" @click="openCreate">+ 创建 Webhook</el-button>
    </div>

    <el-card shadow="never">
      <el-table :data="webhooks" stripe style="width: 100%" empty-text="暂无 Webhooks">
        <el-table-column label="ID" width="80">
          <template #default="{ row }">{{ row.webhook_id ?? row.id }}</template>
        </el-table-column>
        <el-table-column label="URL" show-overflow-tooltip>
          <template #default="{ row }">{{ row.url }}</template>
        </el-table-column>
        <el-table-column label="事件" width="200">
          <template #default="{ row }">
            <el-tag v-for="e in (row.events || []).slice(0,3)" :key="e" size="small" style="margin-right: 4px">{{ e }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.is_active !== false ? 'success' : 'danger'" size="small">{{ row.is_active !== false ? '活跃' : '禁用' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="130">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="testWebhook(row)">测试</el-button>
            <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialogVisible" title="创建 Webhook" width="440px">
      <el-form :model="form" label-width="100px">
        <el-form-item label="URL"><el-input v-model="form.url" placeholder="https://example.com/webhook" /></el-form-item>
        <el-form-item label="事件"><el-input v-model="eventsInput" placeholder="tenant.created,user.registered" /></el-form-item>
        <el-form-item label="描述"><el-input v-model="form.description" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" @click="handleSubmit">创建</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="showTestResult" title="测试结果" width="500px">
      <el-input v-if="testResult" :model-value="JSON.stringify(testResult, null, 2)" type="textarea" :rows="10" readonly style="font-family: monospace" />
      <template #footer>
        <el-button @click="showTestResult = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useUserStore } from '@stores/user'

const userStore = useUserStore()
const apiBase = () => `/api/v1/tenants/${userStore.tenantId}/webhooks`
const webhooks = ref<any[]>([])
const dialogVisible = ref(false)
const form = ref({ url: '', events: [] as string[], description: '' })
const eventsInput = ref('')
const testResult = ref<any>(null)
const showTestResult = ref(false)

const fetchWebhooks = async () => {
  try {
    const r = await axios.get(apiBase())
    webhooks.value = r.data.data || []
  } catch {}
}

const openCreate = () => {
  form.value = { url: '', events: [], description: '' }
  eventsInput.value = ''
  dialogVisible.value = true
}

const handleSubmit = async () => {
  const payload = { ...form.value, events: eventsInput.value.split(',').map(s => s.trim()).filter(Boolean) }
  try {
    await axios.post(apiBase(), payload)
    dialogVisible.value = false
    await fetchWebhooks()
    ElMessage.success('创建成功')
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '创建失败')
  }
}

const handleDelete = async (w: any) => {
  try {
    await ElMessageBox.confirm('确定删除该 Webhook？', '警告', { type: 'warning' })
    await axios.delete(`${apiBase()}/${w.webhook_id ?? w.id}`)
    await fetchWebhooks()
    ElMessage.success('已删除')
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '删除失败')
  }
}

const testWebhook = async (w: any) => {
  try {
    const r = await axios.post(`${apiBase()}/${w.webhook_id ?? w.id}/test`)
    testResult.value = r.data
  } catch (e: any) {
    testResult.value = { error: e.response?.data?.message || e.message }
  }
  showTestResult.value = true
}

onMounted(fetchWebhooks)
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
</style>
