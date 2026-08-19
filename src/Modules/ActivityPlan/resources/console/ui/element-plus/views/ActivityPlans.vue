<template>
  <div class="page">
    <div class="page-header">
      <h2>活动计划</h2>
      <div class="toolbar">
        <el-button @click="goCalendar">返回日历</el-button>
        <el-button type="primary" @click="dialogVisible = true">＋ 新建活动</el-button>
      </div>
    </div>

    <el-card shadow="never" v-loading="loading">
      <el-table :data="plans" size="default">
        <el-table-column label="活动名称" min-width="200">
          <template #default="{ row }">
            <el-link type="primary" @click="openCalendar(row)">{{ planTitle(row) }}</el-link>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="row.status === 'scheduled' ? 'success' : 'info'" size="small">{{ statusText(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="tasks_count" label="事项数" width="90" />
        <el-table-column label="创建时间" width="170">
          <template #default="{ row }">{{ fmt(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="150">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openCalendar(row)">日历</el-button>
            <el-button link type="danger" size="small" @click="remove(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialogVisible" title="新建活动" width="400px">
      <el-form label-width="80px">
        <el-form-item label="活动名称" required>
          <el-input v-model="name" placeholder="如：8 月线下课运营" maxlength="120" @keyup.enter="submit" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submit">创建</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'

const router = useRouter()
const API = '/api/v1/tenant/activity-plan'

const loading = ref(false)
const saving = ref(false)
const plans = ref<any[]>([])
const dialogVisible = ref(false)
const name = ref('')

const planTitle = (p: any) => p.plan_doc?.title || p.plan_doc?.name || `活动 ${p.plan_id}`
const statusText = (s: string) =>
  ({ planning: '规划中', scheduled: '进行中', running: '执行中', closed: '已结束', cancelled: '已取消' }[s] || s)
const fmt = (s: string) => (s ? s.replace('T', ' ').slice(0, 16) : '—')

const load = async () => {
  loading.value = true
  try {
    const res = await axios.get(`${API}/plans`)
    plans.value = (res.data.data || []).filter((p: any) => p.plan_doc?.manual)
  } catch {
    ElMessage.error('加载活动列表失败')
  } finally {
    loading.value = false
  }
}

const submit = async () => {
  if (!name.value.trim()) return ElMessage.warning('请填写活动名称')
  saving.value = true
  try {
    await axios.post(`${API}/manual-plans`, { name: name.value.trim() })
    ElMessage.success('创建成功')
    dialogVisible.value = false
    name.value = ''
    await load()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '创建失败')
  } finally {
    saving.value = false
  }
}

const remove = async (row: any) => {
  try {
    await ElMessageBox.confirm(`删除活动「${planTitle(row)}」及其所有事项？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await axios.delete(`${API}/plans/${row.plan_id}`)
    ElMessage.success('已删除')
    await load()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '删除失败')
  }
}

const openCalendar = (row: any) => router.push(`/activity/calendar?plan_id=${row.plan_id}`)
const goCalendar = () => router.push('/activity/calendar')

onMounted(load)
</script>

<style scoped>
.page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px; }
.page-header h2 { margin: 0; }
.toolbar { display: flex; gap: 8px; }
</style>
