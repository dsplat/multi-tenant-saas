<template>
  <div class="page">
    <div class="page-header">
      <h2>沙箱环境</h2>
      <el-button type="primary" :icon="Plus" @click="handleCreate">创建沙箱</el-button>
    </div>

    <el-card shadow="never">
      <el-table :data="sandboxes" stripe style="width: 100%" empty-text="暂无沙箱环境">
        <el-table-column label="ID" width="80">
          <template #default="{ row }">{{ row.id ?? row.sandbox_environment_id }}</template>
        </el-table-column>
        <el-table-column prop="developer_id" label="开发者ID" />
        <el-table-column prop="sandbox_tenant_id" label="沙箱租户" />
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.status === 'active' ? 'success' : 'danger'" size="small">{{ row.status }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="expires_at" label="过期时间" width="120" />
        <el-table-column label="操作" width="80">
          <template #default="{ row }">
            <el-button link type="danger" size="small" @click="handleCleanup(row)">清理</el-button>
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

const API = '/v1/admin/admin/developer-portal/sandbox'
const sandboxes = ref<any[]>([])

const fetch = async () => { try { const r = await axios.get(API); sandboxes.value = r.data.data || [] } catch {} }
const handleCreate = async () => { try { await axios.post(API); await fetch(); ElMessage.success('沙箱已创建') } catch {} }
const handleCleanup = async (s: any) => {
  try {
    await ElMessageBox.confirm('确定清理此沙箱？', '警告', { type: 'warning' })
    /* cleanup endpoint not exposed yet */
    ElMessage.info('清理功能暂未开放')
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '操作失败')
  }
}

onMounted(fetch)
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
</style>
