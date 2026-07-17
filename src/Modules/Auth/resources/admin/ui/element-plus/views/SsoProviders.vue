<template>
  <div class="page">
    <div class="page-header">
      <h2>SSO 提供商</h2>
      <el-button type="primary" :icon="Plus" @click="openCreate">添加提供商</el-button>
    </div>

    <el-card shadow="never">
      <el-table :data="providers" stripe style="width: 100%" empty-text="暂无 SSO 提供商">
        <el-table-column label="名称">
          <template #default="{ row }"><strong>{{ row.name }}</strong></template>
        </el-table-column>
        <el-table-column label="类型" width="120">
          <template #default="{ row }"><el-tag size="small">{{ row.type || row.provider_type || '-' }}</el-tag></template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.is_active !== false ? 'success' : 'danger'" size="small">{{ row.is_active !== false ? '启用' : '禁用' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="80">
          <template #default="{ row }">
            <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialog" title="添加 SSO 提供商" width="460px">
      <el-form :model="form" label-width="100px">
        <el-form-item label="名称"><el-input v-model="form.name" placeholder="google / github / saml" /></el-form-item>
        <el-form-item label="类型">
          <el-select v-model="form.type" style="width: 100%">
            <el-option label="OIDC" value="oidc" />
            <el-option label="SAML" value="saml" />
            <el-option label="OAuth2" value="oauth2" />
          </el-select>
        </el-form-item>
        <el-form-item label="Client ID"><el-input v-model="form.client_id" /></el-form-item>
        <el-form-item label="Client Secret"><el-input v-model="form.client_secret" type="password" /></el-form-item>
        <el-form-item label="配置 (JSON)">
          <el-input v-model="configInput" type="textarea" :rows="4" placeholder='{"redirect_uri":"..."}' />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialog = false">取消</el-button>
        <el-button type="primary" @click="handleSubmit">添加</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { Plus } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from 'element-plus'

const API = '/v1/admin/auth/sso/providers'
const providers = ref<any[]>([])
const dialog = ref(false)
const form = ref({ name: '', type: 'oidc', client_id: '', client_secret: '', config: {} as any })
const configInput = ref('{}')

const fetch = async () => { try { const r = await axios.get(API); providers.value = r.data.data || [] } catch {} }
const openCreate = () => { form.value = { name: '', type: 'oidc', client_id: '', client_secret: '', config: {} }; configInput.value = '{}'; dialog.value = true }

const handleSubmit = async () => {
  try {
    let config: any = {}
    try { config = JSON.parse(configInput.value) } catch { ElMessage.error('JSON 格式错误'); return }
    await axios.post(API, { ...form.value, config })
    dialog.value = false; await fetch()
    ElMessage.success('添加成功')
  } catch {}
}

const handleDelete = async (p: any) => {
  try {
    await ElMessageBox.confirm(`确定删除 ${p.name}？`, '警告', { type: 'error' })
    await axios.delete(`${API}/${p.name}`)
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
