<template>
  <div class="page">
    <div class="page-header"><h2>API Token 管理</h2></div>

    <el-card shadow="never">
      <el-empty v-if="!tenantStore.hasTenant" description="请先在页面右上角选择团队" />

      <template v-else>
        <div style="margin-bottom: 16px">
          <el-button type="primary" @click="showCreate = true">创建 Token</el-button>
        </div>

        <el-table :data="tokens" stripe style="width: 100%" empty-text="暂无 Token">
          <el-table-column prop="name" label="名称" />
          <el-table-column prop="created_at" label="创建时间" width="180" />
          <el-table-column label="最后使用" width="120">
            <template #default="{ row }">{{ row.last_used_at || '从未使用' }}</template>
          </el-table-column>
          <el-table-column label="状态" width="90">
            <template #default="{ row }">
              <el-tag :type="row.is_active ? 'success' : 'info'" size="small">{{ row.is_active ? '有效' : '已禁用' }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="80">
            <template #default="{ row }">
              <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
      </template>
    </el-card>

    <el-dialog v-model="showCreate" title="创建 API Token" width="440px">
      <el-form :model="createForm" label-width="80px">
        <el-form-item label="名称">
          <el-input v-model="createForm.name" placeholder="输入 Token 名称" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="showCreate = false">取消</el-button>
        <el-button type="primary" @click="handleCreate">创建</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="showTokenResult" title="Token 已创建" width="500px">
      <el-alert title="请立即复制此 Token，关闭后将无法再次查看" type="warning" :closable="false" show-icon style="margin-bottom: 12px" />
      <el-input v-model="createdToken" readonly type="textarea" :rows="3" style="font-family: monospace" />
      <template #footer>
        <el-button type="primary" @click="handleCopy">复制</el-button>
        <el-button @click="showTokenResult = false">关闭</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted, watch } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'
// 租户上下文统一走头部团队选择器（tenantStore），页面内不再自建选择器
import { useTenantStore } from '@/admin/stores/tenant'

const tenantStore = useTenantStore()
const tokens = ref<any[]>([])
const showCreate = ref(false)
const showTokenResult = ref(false)
const createdToken = ref('')
const createForm = reactive({ name: '' })

const loadTokens = async () => {
  if (!tenantStore.hasTenant) return
  try {
    const res = await axios.get(`/api/v1/tenants/${tenantStore.tenantId}/api-tokens`)
    tokens.value = res.data.data || []
  } catch {
    tokens.value = []
  }
}

const handleCreate = async () => {
  try {
    const res = await axios.post(`/api/v1/tenants/${tenantStore.tenantId}/api-tokens`, createForm)
    showCreate.value = false
    createdToken.value = res.data.data?.token || ''
    showTokenResult.value = true
    createForm.name = ''
    loadTokens()
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '创建失败')
  }
}

const handleDelete = async (token: any) => {
  try {
    await ElMessageBox.confirm(`确定删除 Token「${token.name}」？`, '警告', { type: 'warning' })
    await axios.delete(`/api/v1/tenants/${tenantStore.tenantId}/api-tokens/${token.id}`)
    loadTokens()
    ElMessage.success('删除成功')
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '删除失败')
  }
}

const handleCopy = () => {
  navigator.clipboard.writeText(createdToken.value)
  ElMessage.success('已复制到剪贴板')
}

onMounted(() => { if (tenantStore.hasTenant) loadTokens() })
watch(() => tenantStore.tenantId, () => { if (tenantStore.hasTenant) loadTokens(); else tokens.value = [] })
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
</style>
