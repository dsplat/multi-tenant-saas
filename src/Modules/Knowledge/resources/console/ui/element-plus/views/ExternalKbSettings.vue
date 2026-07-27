<template>
  <div class="page">
    <div class="page-header"><h2>外部知识库</h2></div>

    <el-card shadow="never" style="max-width: 860px">
      <template #header>
        <div class="config-header">
          <span style="font-size: 15px; font-weight: 500">知识库连接</span>
          <div class="header-actions">
            <el-tag :type="statusTagType" size="small">{{ statusLabel }}</el-tag>
            <el-button type="primary" size="small" @click="openCreate">添加连接</el-button>
          </div>
        </div>
      </template>

      <el-alert
        v-if="status.source !== 'tenant'"
        type="info"
        :closable="false"
        show-icon
        style="margin-bottom: 16px"
        :title="status.source === 'platform' ? '当前使用平台提供的知识库服务' : '尚未接入外部知识库'"
        description="未配置自有知识库连接时，AI 检索将回退到平台默认知识库（如平台已启用）。添加并激活连接后优先使用您自己的知识库。"
      />

      <el-table :data="connections" style="width: 100%">
        <el-table-column prop="name" label="名称" min-width="140" />
        <el-table-column prop="provider_type" label="服务商" width="110">
          <template #default="{ row }">
            <el-tag size="small">{{ providerLabel(row.provider_type) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="api_url" label="API 地址" min-width="200" show-overflow-tooltip />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 'active' ? 'success' : 'info'" size="small">
              {{ row.status === 'active' ? '激活' : '停用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="最近测试" width="110">
          <template #default="{ row }">
            <el-tag v-if="row.last_test" :type="row.last_test.success ? 'success' : 'danger'" size="small">
              {{ row.last_test.success ? '通过' : '失败' }}
            </el-tag>
            <span v-else style="color: #999">—</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" :loading="testingId === row.connection_id" @click="handleTest(row)">测试</el-button>
            <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
            <el-popconfirm title="确认删除该连接？" @confirm="handleDelete(row)">
              <template #reference>
                <el-button link type="danger" size="small">删除</el-button>
              </template>
            </el-popconfirm>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialogVisible" :title="editingId ? '编辑连接' : '添加连接'" width="560px">
      <el-form :model="form" label-width="120px">
        <el-form-item label="名称" required><el-input v-model="form.name" placeholder="如：企业知识库" /></el-form-item>
        <el-form-item label="服务商" required>
          <el-select v-model="form.provider_type" style="width: 100%">
            <el-option v-for="p in providers" :key="p" :label="providerLabel(p)" :value="p" />
          </el-select>
        </el-form-item>
        <el-form-item label="API 地址" required><el-input v-model="form.api_url" :placeholder="isBailian ? 'https://bailian.cn-beijing.aliyuncs.com' : 'https://api.dify.ai'" /></el-form-item>
        <template v-if="isBailian">
          <el-form-item label="AccessKey ID" required><el-input v-model="form.access_key_id" /></el-form-item>
          <el-form-item label="AccessKey Secret"><el-input v-model="form.api_key" type="password" placeholder="********" show-password /></el-form-item>
          <el-form-item label="业务空间 ID" required><el-input v-model="form.workspace_id" placeholder="llm-xxxx" /></el-form-item>
          <el-form-item label="知识库 ID" required><el-input v-model="form.index_id" placeholder="CreateIndex 返回的索引 ID" /></el-form-item>
        </template>
        <template v-else>
          <el-form-item label="API Key"><el-input v-model="form.api_key" type="password" placeholder="********" show-password /></el-form-item>
          <el-form-item label="数据集 ID"><el-input v-model="form.dataset_id" placeholder="知识库/数据集 ID" /></el-form-item>
        </template>
        <el-form-item label="状态">
          <el-switch v-model="formActive" active-text="激活" inactive-text="停用" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'

const PROVIDER_LABELS: Record<string, string> = { dify: 'Dify', ragflow: 'RAGFlow', fastgpt: 'FastGPT', bailian: '阿里云百炼' }

const connections = ref<any[]>([])
const providers = ref<string[]>(['dify', 'ragflow', 'fastgpt', 'bailian'])
const status = reactive({ configured: false, source: null as string | null, provider_type: null as string | null })

const dialogVisible = ref(false)
const saving = ref(false)
const testingId = ref<number | null>(null)
const editingId = ref<number | null>(null)
const formActive = ref(true)

const form = reactive({
  name: '',
  provider_type: 'dify',
  api_url: '',
  api_key: '',
  dataset_id: '',
  access_key_id: '',
  workspace_id: '',
  index_id: '',
})

const isBailian = computed(() => form.provider_type === 'bailian')

const providerLabel = (type: string) => PROVIDER_LABELS[type] || type

const statusLabel = computed(() =>
  status.source === 'tenant' ? '使用自有知识库'
    : status.source === 'platform' ? '使用平台默认知识库'
    : '未配置')

const statusTagType = computed(() =>
  status.source === 'tenant' ? 'success' : status.source === 'platform' ? 'warning' : 'info')

const loadData = async () => {
  try {
    const res = await axios.get('/api/v1/tenant/external-kb/connections')
    const data = res.data.data || {}
    connections.value = data.connections || []
    if (data.status) Object.assign(status, data.status)
    if (data.providers?.length) providers.value = data.providers
  } catch {}
}

const openCreate = () => {
  editingId.value = null
  Object.assign(form, { name: '', provider_type: 'dify', api_url: '', api_key: '', dataset_id: '', access_key_id: '', workspace_id: '', index_id: '' })
  formActive.value = true
  dialogVisible.value = true
}

const openEdit = (row: any) => {
  editingId.value = row.connection_id
  Object.assign(form, {
    name: row.name,
    provider_type: row.provider_type,
    api_url: row.api_url,
    api_key: row.api_key,
    dataset_id: row.config?.dataset_id || '',
    access_key_id: row.config?.access_key_id || '',
    workspace_id: row.config?.workspace_id || '',
    index_id: row.config?.index_id || '',
  })
  formActive.value = row.status === 'active'
  dialogVisible.value = true
}

const handleSave = async () => {
  saving.value = true
  try {
    const payload = {
      name: form.name,
      provider_type: form.provider_type,
      api_url: form.api_url,
      api_key: form.api_key,
      status: formActive.value ? 'active' : 'disabled',
      config: isBailian.value
        ? { access_key_id: form.access_key_id, workspace_id: form.workspace_id, index_id: form.index_id }
        : { dataset_id: form.dataset_id },
    }
    if (editingId.value) {
      await axios.put(`/api/v1/tenant/external-kb/connections/${editingId.value}`, payload)
    } else {
      await axios.post('/api/v1/tenant/external-kb/connections', payload)
    }
    ElMessage.success('保存成功')
    dialogVisible.value = false
    loadData()
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

const handleTest = async (row: any) => {
  testingId.value = row.connection_id
  try {
    const res = await axios.post(`/api/v1/tenant/external-kb/connections/${row.connection_id}/test`)
    if (res.data.success) {
      ElMessage.success(res.data.message || '连接测试通过')
    } else {
      ElMessage.error(res.data.message || '连接测试失败')
    }
    loadData()
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '连接测试失败')
  } finally {
    testingId.value = null
  }
}

const handleDelete = async (row: any) => {
  try {
    await axios.delete(`/api/v1/tenant/external-kb/connections/${row.connection_id}`)
    ElMessage.success('已删除')
    loadData()
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '删除失败')
  }
}

onMounted(loadData)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.config-header { display: flex; justify-content: space-between; align-items: center; }
.header-actions { display: flex; align-items: center; gap: 12px; }
</style>
