<template>
  <div class="page">
    <div class="page-header">
      <h2>API Token</h2>
      <el-button type="primary" @click="showCreate = true">+ 创建 Token</el-button>
    </div>

    <el-card shadow="never">
      <p class="hint">API Token 用于第三方系统调用接口，请妥善保管。</p>
      <el-table :data="tokens" stripe style="width: 100%" empty-text="暂无 Token">
        <el-table-column prop="name" label="名称" />
        <el-table-column label="创建时间" width="180">
          <template #default="{ row }">{{ row.created_at }}</template>
        </el-table-column>
        <el-table-column label="最后使用" width="180">
          <template #default="{ row }">{{ row.last_used_at || '-' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="100">
          <template #default="{ row }">
            <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="showCreate" title="创建 API Token" width="440px">
      <el-form :model="createForm" label-width="80px">
        <el-form-item label="名称">
          <el-input v-model="createForm.name" placeholder="如：订单系统对接" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="showCreate = false">取消</el-button>
        <el-button type="primary" :loading="creating" @click="handleCreate">创建</el-button>
      </template>
    </el-dialog>

    <el-dialog v-model="showToken" title="Token 已创建" width="500px" :close-on-click-modal="false">
      <el-alert type="warning" :closable="false" show-icon style="margin-bottom: 16px">
        请立即复制保存，关闭后无法再次查看！
      </el-alert>
      <el-input :model-value="newToken" readonly style="font-family: monospace">
        <template #append>
          <el-button @click="handleCopy">复制</el-button>
        </template>
      </el-input>
      <template #footer>
        <el-button type="primary" @click="showToken = false">我已保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useUserStore } from '@stores/user'

const userStore = useUserStore()
const tokens = ref<any[]>([])
const showCreate = ref(false)
const showToken = ref(false)
const creating = ref(false)
const newToken = ref('')
const createForm = reactive({ name: '' })

const fetchTokens = async () => {
  try {
    const res = await axios.get(`/api/v1/tenants/${userStore.tenantId}/api-tokens`)
    tokens.value = res.data.data || []
  } catch {}
}

const handleCreate = async () => {
  creating.value = true
  try {
    const res = await axios.post(`/api/v1/tenants/${userStore.tenantId}/api-tokens`, createForm)
    newToken.value = res.data.data?.token || ''
    showCreate.value = false
    showToken.value = true
    createForm.name = ''
    fetchTokens()
    ElMessage.success('Token 创建成功')
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '创建失败')
  } finally {
    creating.value = false
  }
}

const handleDelete = async (t: any) => {
  try {
    await ElMessageBox.confirm(`确定删除 Token "${t.name}"？`, '警告', { type: 'warning' })
    await axios.delete(`/api/v1/tenants/${userStore.tenantId}/api-tokens/${t.id}`)
    fetchTokens()
    ElMessage.success('已删除')
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '删除失败')
  }
}

const handleCopy = () => {
  navigator.clipboard?.writeText(newToken.value)
  ElMessage.success('已复制到剪贴板')
}

onMounted(fetchTokens)
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.hint { color: var(--el-text-color-secondary); font-size: 13px; margin: 0 0 16px; }
</style>
