<template>
  <div class="page">
    <div class="page-header">
      <h2>运营人员</h2>
      <el-button type="primary" :icon="Plus" @click="showInvite = true">邀请运营人员</el-button>
    </div>

    <el-card shadow="never">
      <div class="filter-bar">
        <el-select v-model="filterScope" placeholder="全部范围" clearable style="width: 160px" @change="fetchOperators">
          <el-option label="全部范围" value="" />
          <el-option label="平台级" value="platform" />
          <el-option label="租户级" value="tenant" />
        </el-select>
      </div>

      <el-table :data="operators" stripe style="width: 100%" empty-text="暂无运营人员">
        <el-table-column prop="operator_id" label="ID" width="80" />
        <el-table-column prop="name" label="姓名" />
        <el-table-column prop="email" label="邮箱" />
        <el-table-column label="范围" width="100">
          <template #default="{ row }">
            <el-tag :type="row.scope === 'platform' ? 'warning' : 'info'" size="small">{{ row.scope }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.is_active ? 'success' : 'danger'" size="small">
              {{ row.is_active ? '活跃' : '禁用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="创建时间" width="120" />
        <el-table-column label="操作" width="140">
          <template #default="{ row }">
            <el-button link :type="row.is_active ? 'warning' : 'success'" size="small" @click="toggleStatus(row)">
              {{ row.is_active ? '禁用' : '启用' }}
            </el-button>
            <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="showInvite" title="邀请运营人员" width="440px">
      <el-form :model="inviteForm" label-width="80px">
        <el-form-item label="邮箱"><el-input v-model="inviteForm.email" type="email" /></el-form-item>
        <el-form-item label="姓名"><el-input v-model="inviteForm.name" /></el-form-item>
        <el-form-item label="范围">
          <el-select v-model="inviteForm.scope" style="width: 100%">
            <el-option label="平台级" value="platform" />
            <el-option label="租户级" value="tenant" />
          </el-select>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="showInvite = false">取消</el-button>
        <el-button type="primary" @click="handleInvite">邀请</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { Plus } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from 'element-plus'

const operators = ref<any[]>([])
const filterScope = ref('')
const showInvite = ref(false)
const inviteForm = ref({ email: '', name: '', scope: 'platform' })

const fetchOperators = async () => {
  try {
    const res = await axios.get('/api/v1/operators', { params: { scope: filterScope.value || undefined } })
    operators.value = res.data.data || []
  } catch {}
}

const handleInvite = async () => {
  try {
    await axios.post('/api/v1/operators/invite', inviteForm.value)
    showInvite.value = false
    inviteForm.value = { email: '', name: '', scope: 'platform' }
    await fetchOperators()
    ElMessage.success('邀请已发送')
  } catch {}
}

const toggleStatus = async (op: any) => {
  try {
    await axios.put(`/v1/admin/operators/${op.operator_id}`, { is_active: !op.is_active })
    await fetchOperators()
    ElMessage.success(op.is_active ? '已禁用' : '已启用')
  } catch {}
}

const handleDelete = async (op: any) => {
  try {
    await ElMessageBox.confirm(`确定删除运营人员 ${op.name}？`, '警告', { type: 'error' })
    await axios.delete(`/v1/admin/operators/${op.operator_id}`)
    await fetchOperators()
    ElMessage.success('删除成功')
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '删除失败')
  }
}

onMounted(fetchOperators)
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.filter-bar { margin-bottom: 16px; }
</style>
