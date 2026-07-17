<template>
  <div class="page">
    <div class="page-header"><h2>模块管理</h2></div>

    <el-card shadow="never">
      <el-table :data="modules" stripe style="width: 100%" empty-text="暂无模块">
        <el-table-column label="模块名" width="200">
          <template #default="{ row }"><strong>{{ row.name }}</strong></template>
        </el-table-column>
        <el-table-column label="版本" width="100">
          <template #default="{ row }">{{ row.version || '-' }}</template>
        </el-table-column>
        <el-table-column label="描述">
          <template #default="{ row }">{{ row.description || '-' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.enabled ? 'success' : 'danger'" size="small">{{ row.enabled ? '已启用' : '已禁用' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="80">
          <template #default="{ row }">
            <el-button link :type="row.enabled ? 'warning' : 'success'" size="small" @click="toggleModule(row)">
              {{ row.enabled ? '禁用' : '启用' }}
            </el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const API = '/api/v1/admin/modules'
const modules = ref<any[]>([])

const fetchModules = async () => { try { const r = await axios.get(API); modules.value = r.data.data || [] } catch {} }

const toggleModule = async (m: any) => {
  const action = m.enabled ? 'disable' : 'enable'
  try { await axios.post(`${API}/${m.name}/${action}`); await fetchModules() } catch {}
}

onMounted(fetchModules)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
</style>
