<template>
  <div class="queue-failed">
    <el-card shadow="never">
      <template #header>
        <div class="card-header">
          <span>失败队列任务</span>
          <div class="header-actions">
            <el-button type="warning" plain :disabled="!jobs.length" @click="retryAll" :loading="retryAllLoading">
              重试全部
            </el-button>
            <el-button type="danger" plain :disabled="!jobs.length" @click="flushAll" :loading="flushLoading">
              清空全部
            </el-button>
            <el-button plain @click="fetchList" :loading="loading">刷新</el-button>
          </div>
        </div>
      </template>

      <el-table
        :data="jobs"
        v-loading="loading"
        stripe
        style="width: 100%"
        empty-text="暂无失败任务"
      >
        <el-table-column label="ID" prop="id" width="80" />
        <el-table-column label="任务名" prop="name" min-width="200" show-overflow-tooltip />
        <el-table-column label="队列" prop="queue" width="120" />
        <el-table-column label="连接" prop="connection" width="100" />
        <el-table-column label="尝试次数" prop="attempts" width="90" align="center" />
        <el-table-column label="失败时间" width="170">
          <template #default="{ row }">{{ formatTime(row.failed_at) }}</template>
        </el-table-column>
        <el-table-column label="异常信息" min-width="300">
          <template #default="{ row }">
            <el-tooltip
              :content="row.exception"
              placement="top-start"
              :show-after="200"
              effect="dark"
            >
              <div class="exception-cell">{{ truncate(row.exception, 80) }}</div>
            </el-tooltip>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="160" fixed="right">
          <template #default="{ row }">
            <el-button size="small" type="primary" plain @click="retry(row.id)" :loading="retryLoading[row.id]">
              重试
            </el-button>
            <el-popconfirm
              title="确定删除此失败任务？"
              @confirm="remove(row.id)"
            >
              <template #reference>
                <el-button size="small" type="danger" plain>删除</el-button>
              </template>
            </el-popconfirm>
          </template>
        </el-table-column>
      </el-table>

      <div class="pagination-wrapper">
        <el-pagination
          v-model:current-page="page"
          v-model:page-size="perPage"
          :total="total"
          layout="total, sizes, prev, pager, next"
          :page-sizes="[20, 50, 100]"
          @size-change="fetchList"
          @current-change="fetchList"
        />
      </div>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import axios from 'axios'

const jobs = ref<any[]>([])
const loading = ref(false)
const total = ref(0)
const page = ref(1)
const perPage = ref(20)
const retryLoading = reactive<Record<string, boolean>>({})
const retryAllLoading = ref(false)
const flushLoading = ref(false)

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
    ElMessage.error('获取失败任务列表失败：' + (e.response?.data?.message || e.message))
  } finally {
    loading.value = false
  }
}

const retry = async (id: string) => {
  retryLoading[id] = true
  try {
    await axios.post(`/api/v1/admin/queue/failed/${id}/retry`)
    ElMessage.success('任务已重新加入队列')
    await fetchList()
  } catch (e: any) {
    ElMessage.error('重试失败：' + (e.response?.data?.message || e.message))
  } finally {
    retryLoading[id] = false
  }
}

const retryAll = async () => {
  retryAllLoading.value = true
  try {
    const r = await axios.post('/api/v1/admin/queue/failed/retry-all')
    ElMessage.success(`已重试 ${r.data.data?.retried ?? '所有'} 个任务`)
    await fetchList()
  } catch (e: any) {
    ElMessage.error('批量重试失败：' + (e.response?.data?.message || e.message))
  } finally {
    retryAllLoading.value = false
  }
}

const remove = async (id: string) => {
  try {
    await axios.delete(`/api/v1/admin/queue/failed/${id}`)
    ElMessage.success('已删除失败任务')
    await fetchList()
  } catch (e: any) {
    ElMessage.error('删除失败：' + (e.response?.data?.message || e.message))
  }
}

const flushAll = async () => {
  flushLoading.value = true
  try {
    await axios.delete('/api/v1/admin/queue/failed')
    ElMessage.success('已清空所有失败任务')
    await fetchList()
  } catch (e: any) {
    ElMessage.error('清空失败：' + (e.response?.data?.message || e.message))
  } finally {
    flushLoading.value = false
  }
}

const formatTime = (t: string) => t ? t.replace('T', ' ').substring(0, 19) : '-'
const truncate = (s: string, len: number) => s && s.length > len ? s.substring(0, len) + '…' : s

onMounted(fetchList)
</script>

<style scoped>
.queue-failed { padding: 0; }
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.header-actions { display: flex; gap: 8px; }
.exception-cell {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  cursor: help;
}
.pagination-wrapper {
  display: flex;
  justify-content: flex-end;
  margin-top: 16px;
}
</style>
