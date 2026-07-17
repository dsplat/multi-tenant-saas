<template>
  <div class="page">
    <div class="page-header">
      <h2>租户密钥</h2>
      <el-button type="primary" :icon="Plus" @click="handleGenerate">生成密钥</el-button>
    </div>

    <el-card shadow="never">
      <el-table :data="keys" stripe style="width: 100%" empty-text="暂无密钥">
        <el-table-column label="ID" width="80">
          <template #default="{ row }">{{ row.id ?? row.tenant_key_id }}</template>
        </el-table-column>
        <el-table-column label="名称">
          <template #default="{ row }">{{ row.name || '-' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.status === 'active' ? 'success' : 'danger'" size="small">{{ row.status }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="创建时间" width="120" />
        <el-table-column label="操作" width="80">
          <template #default="{ row }">
            <el-button v-if="row.status === 'active'" link type="danger" size="small" @click="handleRevoke(row)">吊销</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { Plus } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from 'element-plus'

const API = '/api/v1/admin/tenant-keys'
const keys = ref<any[]>([])

const fetch = async () => { try { const r = await axios.get(API); keys.value = r.data.data || [] } catch {} }
const handleGenerate = async () => {
  try { await axios.post(API); await fetch(); ElMessage.success('密钥已生成') } catch {}
}
const handleRevoke = async (k: any) => {
  try {
    await ElMessageBox.confirm('确定吊销此密钥？', '警告', { type: 'error' })
    await axios.delete(`${API}/${k.id ?? k.tenant_key_id}`)
    await fetch()
    ElMessage.success('已吊销')
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '操作失败')
  }
}

onMounted(fetch)
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
</style>
