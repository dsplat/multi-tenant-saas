<template>
  <div class="queue-failed">
    <div class="page-header">
      <h2>失败队列任务</h2>
      <div class="header-actions">
        <button class="btn btn-warning btn-sm" :disabled="!jobs.length || retryAllLoading" @click="retryAll">
          <span v-if="retryAllLoading" class="spinner-border spinner-border-sm"></span>
          重试全部
        </button>
        <button class="btn btn-danger btn-sm" :disabled="!jobs.length || flushLoading" @click="confirmFlush">
          <span v-if="flushLoading" class="spinner-border spinner-border-sm"></span>
          清空全部
        </button>
        <button class="btn btn-secondary btn-sm" @click="fetchList" :disabled="loading">
          <span v-if="loading" class="spinner-border spinner-border-sm"></span>
          刷新
        </button>
      </div>
    </div>

    <div class="panel">
      <div v-if="loading && !jobs.length" class="text-center p-4">
        <div class="spinner-border text-primary"></div>
      </div>

      <table v-else class="data-table">
        <thead>
          <tr>
            <th style="width: 60px;">ID</th>
            <th style="min-width: 160px;">任务名</th>
            <th style="width: 100px;">队列</th>
            <th style="width: 90px;">连接</th>
            <th style="width: 80px;">尝试</th>
            <th style="width: 150px;">失败时间</th>
            <th style="min-width: 240px;">异常信息</th>
            <th style="width: 140px;">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="job in jobs" :key="job.id">
            <td>{{ job.id }}</td>
            <td class="ellipsis" :title="job.name">{{ job.name }}</td>
            <td>{{ job.queue }}</td>
            <td>{{ job.connection }}</td>
            <td class="text-center">{{ job.attempts }}</td>
            <td>{{ formatTime(job.failed_at) }}</td>
            <td class="ellipsis" :title="job.exception">{{ truncate(job.exception, 80) }}</td>
            <td>
              <button class="btn btn-primary btn-xs" @click="retry(job.id)" :disabled="retryLoading[job.id]">
                <span v-if="retryLoading[job.id]" class="spinner-border spinner-border-sm"></span>
                重试
              </button>
              <button class="btn btn-danger btn-xs" @click="confirmRemove(job.id)">删除</button>
            </td>
          </tr>
          <tr v-if="!jobs.length">
            <td colspan="8" class="empty-row text-center text-muted">暂无失败任务</td>
          </tr>
        </tbody>
      </table>

      <div v-if="total > perPage" class="pagination-wrapper">
        <div class="page-info">共 {{ total }} 条</div>
        <div class="btn-group" role="group">
          <button class="btn btn-sm" :disabled="page <= 1" @click="goPage(page - 1)">上一页</button>
          <button class="btn btn-sm">{{ page }}</button>
          <button class="btn btn-sm" :disabled="page * perPage >= total" @click="goPage(page + 1)">下一页</button>
        </div>
      </div>
    </div>

    <div v-if="toast.show" :class="['alert', `alert-${toast.type}`, 'toast']">
      {{ toast.message }}
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'

const jobs = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(20)
const retryLoading = reactive<Record<string, boolean>>({})
const retryAllLoading = ref(false)
const flushLoading = ref(false)
const toast = reactive({ show: false, type: 'info', message: '' })

const showToast = (type: 'success' | 'danger' | 'info', message: string) => {
  toast.type = type
  toast.message = message
  toast.show = true
  setTimeout(() => (toast.show = false), 3000)
}

const fetchList = async () => {
  loading.value = true
  try {
    const r = await axios.get('/api/v1/admin/queue/failed', {
      params: { page: page.value, per_page: perPage.value },
    })
    if (r.data.success) {
      jobs.value = r.data.data.items || []
      total.value = r.data.data.total || 0
    }
  } catch (e: any) {
    showToast('danger', '获取失败任务列表失败：' + (e.response?.data?.message || e.message))
  } finally {
    loading.value = false
  }
}

const retry = async (id: string) => {
  retryLoading[id] = true
  try {
    await axios.post(`/api/v1/admin/queue/failed/${id}/retry`)
    showToast('success', '任务已重新加入队列')
    await fetchList()
  } catch (e: any) {
    showToast('danger', '重试失败：' + (e.response?.data?.message || e.message))
  } finally {
    retryLoading[id] = false
  }
}

const retryAll = async () => {
  if (!confirm('确定要重试所有失败任务？')) return
  retryAllLoading.value = true
  try {
    const r = await axios.post('/api/v1/admin/queue/failed/retry-all')
    showToast('success', `已重试 ${r.data.data?.retried ?? '所有'} 个任务`)
    await fetchList()
  } catch (e: any) {
    showToast('danger', '批量重试失败：' + (e.response?.data?.message || e.message))
  } finally {
    retryAllLoading.value = false
  }
}

const confirmRemove = (id: string) => {
  if (!confirm('确定删除此失败任务？')) return
  remove(id)
}

const remove = async (id: string) => {
  try {
    await axios.delete(`/api/v1/admin/queue/failed/${id}`)
    showToast('success', '已删除失败任务')
    await fetchList()
  } catch (e: any) {
    showToast('danger', '删除失败：' + (e.response?.data?.message || e.message))
  }
}

const confirmFlush = async () => {
  if (!confirm('确定清空所有失败任务？此操作不可恢复！')) return
  flushLoading.value = true
  try {
    await axios.delete('/api/v1/admin/queue/failed')
    showToast('success', '已清空所有失败任务')
    await fetchList()
  } catch (e: any) {
    showToast('danger', '清空失败：' + (e.response?.data?.message || e.message))
  } finally {
    flushLoading.value = false
  }
}

const goPage = (p: number) => {
  if (p < 1 || (p - 1) * perPage.value >= total.value) return
  page.value = p
  fetchList()
}

const formatTime = (t: string) => (t ? t.replace('T', ' ').substring(0, 19) : '-')
const truncate = (s: string, len: number) => (s && s.length > len ? s.substring(0, len) + '…' : s)

onMounted(fetchList)
</script>

<style scoped>
.queue-failed { padding: 0; }
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
}
.header-actions { display: flex; gap: 8px; }
.panel {
  background: #fff;
  border-radius: 4px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.08);
  overflow: hidden;
}
.data-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 13px;
}
.data-table th,
.data-table td {
  padding: 10px 12px;
  text-align: left;
  border-bottom: 1px solid #eee;
}
.data-table th {
  background: #f8f9fa;
  font-weight: 600;
  color: #495057;
}
.ellipsis {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 240px;
}
.empty-row {
  padding: 40px;
  font-size: 14px;
}
.pagination-wrapper {
  display: flex;
  justify-content: flex-end;
  align-items: center;
  gap: 16px;
  padding: 12px;
}
.toast {
  position: fixed;
  top: 80px;
  right: 24px;
  z-index: 9999;
  min-width: 300px;
}
</style>
