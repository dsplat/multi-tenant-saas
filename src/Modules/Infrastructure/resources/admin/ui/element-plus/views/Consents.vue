<template>
  <div class="page">
    <div class="page-header"><h2>合规同意</h2></div>

    <el-card shadow="never">
      <div class="filter-bar">
        <el-input v-model="filters.user_id" placeholder="用户ID" style="width: 200px" @keyup.enter="fetch" />
        <el-select v-model="filters.type" placeholder="全部类型" clearable style="width: 160px" @change="fetch">
          <el-option label="Cookie" value="cookie" />
          <el-option label="数据处理" value="data_processing" />
          <el-option label="营销" value="marketing" />
          <el-option label="条款" value="terms" />
        </el-select>
      </div>

      <el-table :data="consents" stripe style="width: 100%" empty-text="暂无记录">
        <el-table-column label="ID" width="80">
          <template #default="{ row }">{{ row.id ?? row.consent_id }}</template>
        </el-table-column>
        <el-table-column prop="user_id" label="用户ID" />
        <el-table-column label="类型" width="120">
          <template #default="{ row }"><el-tag size="small">{{ row.type }}</el-tag></template>
        </el-table-column>
        <el-table-column label="版本" width="80">
          <template #default="{ row }">{{ row.version || '-' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.revoked_at ? 'danger' : 'success'" size="small">{{ row.revoked_at ? '已撤回' : '有效' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="时间" width="120" />
        <el-table-column label="操作" width="80">
          <template #default="{ row }">
            <el-button v-if="!row.revoked_at" link type="danger" size="small" @click="handleRevoke(row)">撤回</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'

const API = '/api/v1/admin/consents'
const consents = ref<any[]>([])
const filters = ref({ user_id: '', type: '' })

const fetch = async () => {
  try { const r = await axios.get(API, { params: { user_id: filters.value.user_id || undefined, type: filters.value.type || undefined } }); consents.value = r.data.data || [] } catch {}
}

const handleRevoke = async (c: any) => {
  try {
    await ElMessageBox.confirm('确定撤回此同意？', '警告', { type: 'warning' })
    await axios.post(`${API}/${c.id ?? c.consent_id}/revoke`)
    await fetch()
    ElMessage.success('已撤回')
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '操作失败')
  }
}

onMounted(fetch)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.filter-bar { display: flex; gap: 12px; margin-bottom: 16px; }
</style>
