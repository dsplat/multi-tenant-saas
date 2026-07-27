<template>
  <div class="kb-page">
    <div class="page-header"><h2>外部知识库</h2></div>

    <div class="panel">
      <div class="status-bar">
        <span class="status-badge" :class="status.source || 'none'">{{ statusLabel }}</span>
        <button type="button" class="primary-btn" @click="openCreate">添加连接</button>
      </div>

      <p v-if="status.source !== 'tenant'" class="hint-box">
        未配置自有知识库连接时，AI 检索将回退到平台默认知识库（如平台已启用）。添加并激活连接后优先使用您自己的知识库。
      </p>

      <table class="kb-table">
        <thead>
          <tr>
            <th>名称</th><th>服务商</th><th>API 地址</th><th>状态</th><th>最近测试</th><th>操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in connections" :key="row.connection_id">
            <td>{{ row.name }}</td>
            <td>{{ providerLabel(row.provider_type) }}</td>
            <td class="url-cell">{{ row.api_url }}</td>
            <td>{{ row.status === 'active' ? '激活' : '停用' }}</td>
            <td>{{ row.last_test ? (row.last_test.success ? '通过' : '失败') : '—' }}</td>
            <td class="actions-cell">
              <button type="button" class="link-btn" :disabled="testingId === row.connection_id" @click="handleTest(row)">测试</button>
              <button type="button" class="link-btn" @click="openEdit(row)">编辑</button>
              <button type="button" class="link-btn danger" @click="handleDelete(row)">删除</button>
            </td>
          </tr>
          <tr v-if="!connections.length">
            <td colspan="6" class="empty-cell">暂无连接</td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="dialogVisible" class="modal-mask" @click.self="dialogVisible = false">
      <div class="modal">
        <h3>{{ editingId ? '编辑连接' : '添加连接' }}</h3>
        <form @submit.prevent="handleSave">
          <div class="form-group">
            <label>名称</label>
            <input v-model="form.name" required placeholder="如：企业知识库" />
          </div>
          <div class="form-group">
            <label>服务商</label>
            <select v-model="form.provider_type">
              <option v-for="p in providers" :key="p" :value="p">{{ providerLabel(p) }}</option>
            </select>
          </div>
          <div class="form-group">
            <label>API 地址</label>
            <input v-model="form.api_url" required placeholder="https://api.dify.ai" />
          </div>
          <div class="form-group">
            <label>API Key</label>
            <input v-model="form.api_key" type="password" placeholder="********" />
          </div>
          <div class="form-group">
            <label>数据集 ID</label>
            <input v-model="form.dataset_id" placeholder="知识库/数据集 ID" />
          </div>
          <div class="form-group">
            <label><input type="checkbox" v-model="formActive" /> 激活</label>
          </div>
          <div class="form-actions">
            <button type="button" class="secondary-btn" @click="dialogVisible = false">取消</button>
            <button type="submit" class="primary-btn" :disabled="saving">保存</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import axios from 'axios'

const PROVIDER_LABELS: Record<string, string> = { dify: 'Dify', ragflow: 'RAGFlow', fastgpt: 'FastGPT' }

const connections = ref<any[]>([])
const providers = ref<string[]>(['dify', 'ragflow', 'fastgpt'])
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
})

const providerLabel = (type: string) => PROVIDER_LABELS[type] || type

const statusLabel = computed(() =>
  status.source === 'tenant' ? '使用自有知识库'
    : status.source === 'platform' ? '使用平台默认知识库'
    : '未配置')

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
  Object.assign(form, { name: '', provider_type: 'dify', api_url: '', api_key: '', dataset_id: '' })
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
      config: { dataset_id: form.dataset_id },
    }
    if (editingId.value) {
      await axios.put(`/api/v1/tenant/external-kb/connections/${editingId.value}`, payload)
    } else {
      await axios.post('/api/v1/tenant/external-kb/connections', payload)
    }
    alert('保存成功')
    dialogVisible.value = false
    loadData()
  } catch (e: any) {
    alert(e.response?.data?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

const handleTest = async (row: any) => {
  testingId.value = row.connection_id
  try {
    const res = await axios.post(`/api/v1/tenant/external-kb/connections/${row.connection_id}/test`)
    alert(res.data.message || (res.data.success ? '连接测试通过' : '连接测试失败'))
    loadData()
  } catch (e: any) {
    alert(e.response?.data?.message || '连接测试失败')
  } finally {
    testingId.value = null
  }
}

const handleDelete = async (row: any) => {
  if (!confirm('确认删除该连接？')) return
  try {
    await axios.delete(`/api/v1/tenant/external-kb/connections/${row.connection_id}`)
    loadData()
  } catch (e: any) {
    alert(e.response?.data?.message || '删除失败')
  }
}

onMounted(loadData)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; max-width: 860px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.status-bar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.status-badge { display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 12px; background: #f0f0f0; color: #666; }
.status-badge.tenant { background: #e7f6e7; color: #2f9e44; }
.status-badge.platform { background: #fff4e0; color: #e8930c; }
.hint-box { font-size: 13px; color: var(--text-color-secondary, #666); background: #f7f9fc; border-radius: 6px; padding: 10px 12px; }
.kb-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.kb-table th, .kb-table td { text-align: left; padding: 10px 8px; border-bottom: 1px solid var(--border-color, #eee); }
.kb-table th { color: var(--text-color-secondary, #666); font-weight: 500; }
.url-cell { max-width: 220px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.empty-cell { text-align: center; color: #999; padding: 24px 0; }
.actions-cell { white-space: nowrap; }
.link-btn { border: none; background: none; color: var(--primary-color, #409eff); cursor: pointer; font-size: 13px; padding: 0 6px; }
.link-btn.danger { color: #e55353; }
.link-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.modal-mask { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 100; }
.modal { background: #fff; border-radius: 8px; padding: 24px; width: 480px; max-width: 92vw; }
.modal h3 { margin: 0 0 16px; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 6px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input:not([type="checkbox"]), .form-group select { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 14px; box-sizing: border-box; }
.form-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 20px; }
.primary-btn { padding: 8px 20px; border: none; border-radius: 6px; background: var(--primary-color, #409eff); color: #fff; cursor: pointer; font-size: 13px; }
.secondary-btn { padding: 8px 16px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; background: var(--bg-color, #fff); cursor: pointer; font-size: 13px; }
.primary-btn:disabled { opacity: 0.6; cursor: not-allowed; }
</style>
