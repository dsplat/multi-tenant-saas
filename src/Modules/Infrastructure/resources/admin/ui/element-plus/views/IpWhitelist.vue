<template>
  <div class="page">
    <div class="page-header">
      <h2>IP 白名单</h2>
      <el-button type="primary" :icon="Plus" @click="showAdd = true">添加 IP</el-button>
    </div>

    <el-card shadow="never">
      <div class="filter-bar">
        <el-select v-model="filterScope" placeholder="全部范围" clearable style="width: 160px" @change="fetch">
          <el-option label="全局" value="all" />
          <el-option label="API" value="api" />
          <el-option label="管理后台" value="admin" />
        </el-select>
      </div>

      <el-table :data="items" stripe style="width: 100%" empty-text="暂无白名单">
        <el-table-column label="IP" width="200">
          <template #default="{ row }"><code>{{ row.ip_address }}</code></template>
        </el-table-column>
        <el-table-column label="描述">
          <template #default="{ row }">{{ row.description || '-' }}</template>
        </el-table-column>
        <el-table-column label="范围" width="100">
          <template #default="{ row }"><el-tag size="small">{{ row.scope }}</el-tag></template>
        </el-table-column>
        <el-table-column label="操作" width="80">
          <template #default="{ row }">
            <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="showAdd" title="添加 IP 白名单" width="420px">
      <el-form :model="form" label-width="80px">
        <el-form-item label="IP 地址"><el-input v-model="form.ip_address" placeholder="192.168.1.1" /></el-form-item>
        <el-form-item label="描述"><el-input v-model="form.description" /></el-form-item>
        <el-form-item label="范围">
          <el-select v-model="form.scope" style="width: 100%">
            <el-option label="全局" value="all" />
            <el-option label="API" value="api" />
            <el-option label="管理后台" value="admin" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="showAdd = false">取消</el-button>
        <el-button type="primary" @click="handleAdd">添加</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { Plus } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from 'element-plus'

const API = '/api/v1/admin/ip-whitelist'
const items = ref<any[]>([])
const filterScope = ref('')
const showAdd = ref(false)
const form = ref({ ip_address: '', description: '', scope: 'all' })

const fetch = async () => { try { const r = await axios.get(API, { params: { scope: filterScope.value || undefined } }); items.value = r.data.data || [] } catch {} }
const handleAdd = async () => {
  try { await axios.post(API, form.value); showAdd.value = false; form.value = { ip_address: '', description: '', scope: 'all' }; await fetch(); ElMessage.success('添加成功') } catch {}
}
const handleDelete = async (ip: any) => {
  try {
    await ElMessageBox.confirm(`确定删除 ${ip.ip_address}？`, '警告', { type: 'error' })
    await axios.delete(`${API}/${ip.id}`)
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
.filter-bar { margin-bottom: 16px; }
</style>
