<template>
  <div class="page">
    <div class="page-header">
      <h2>工单管理</h2>
      <el-button type="primary" @click="showCreate = true">+ 创建工单</el-button>
    </div>

    <el-card shadow="never">
      <div class="filter-bar">
        <el-select v-model="statusFilter" placeholder="全部状态" clearable style="width: 160px" @change="fetchTickets()">
          <el-option label="待处理" value="open" />
          <el-option label="处理中" value="in_progress" />
          <el-option label="已解决" value="resolved" />
          <el-option label="已关闭" value="closed" />
        </el-select>
      </div>

      <el-table :data="tickets" stripe style="width: 100%" empty-text="暂无工单">
        <el-table-column label="ID" width="80">
          <template #default="{ row }">{{ row.ticket_id }}</template>
        </el-table-column>
        <el-table-column label="标题" show-overflow-tooltip>
          <template #default="{ row }">{{ row.subject }}</template>
        </el-table-column>
        <el-table-column label="优先级" width="90">
          <template #default="{ row }">
            <el-tag :type="priorityType(row.priority)" size="small">{{ row.priority }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="创建时间" width="160">
          <template #default="{ row }">{{ formatDate(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="180">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="viewTicket(row)">查看</el-button>
            <el-button v-if="row.status !== 'resolved' && row.status !== 'closed'" link type="success" size="small" @click="resolveTicket(row)">解决</el-button>
            <el-button link type="danger" size="small" @click="deleteTicket(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination
        v-if="total > perPage"
        v-model:current-page="currentPage"
        :total="total"
        :page-size="perPage"
        layout="prev, pager, next"
        style="margin-top: 16px; justify-content: center"
        @current-change="fetchTickets()"
      />
    </el-card>

    <!-- 创建工单 -->
    <el-dialog v-model="showCreate" title="创建工单" width="480px">
      <el-form :model="form" label-width="80px">
        <el-form-item label="标题"><el-input v-model="form.subject" /></el-form-item>
        <el-form-item label="描述"><el-input v-model="form.description" type="textarea" :rows="4" /></el-form-item>
        <el-form-item label="优先级">
          <el-select v-model="form.priority" style="width: 100%">
            <el-option label="低" value="low" />
            <el-option label="中" value="medium" />
            <el-option label="高" value="high" />
            <el-option label="紧急" value="urgent" />
          </el-select>
        </el-form-item>
        <el-form-item label="分类"><el-input v-model="form.category" placeholder="bug/feature/..." /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="showCreate = false">取消</el-button>
        <el-button type="primary" @click="handleCreate">创建</el-button>
      </template>
    </el-dialog>

    <!-- 工单详情 + 评论 -->
    <el-dialog v-model="detailVisible" :title="detailTicket?.subject || '工单详情'" width="640px">
      <template v-if="detailTicket">
        <div class="detail-meta">
          <el-tag :type="statusType(detailTicket.status)" size="small">{{ statusLabel(detailTicket.status) }}</el-tag>
          <el-tag :type="priorityType(detailTicket.priority)" size="small">{{ detailTicket.priority }}</el-tag>
          <span class="meta-text">{{ formatDate(detailTicket.created_at) }}</span>
        </div>
        <p v-if="detailTicket.description" class="detail-desc">{{ detailTicket.description }}</p>

        <h4 style="margin: 16px 0 8px; font-size: 14px; color: var(--el-text-color-secondary)">评论</h4>
        <div v-for="c in comments" :key="c.comment_id" class="comment-item">
          <div class="comment-meta">{{ c.user_id }} · {{ formatDate(c.created_at) }}</div>
          <div class="comment-content">{{ c.content }}</div>
        </div>
        <el-empty v-if="comments.length === 0" description="暂无评论" :image-size="60" />

        <div class="comment-form">
          <el-input v-model="newComment" type="textarea" :rows="2" placeholder="添加评论..." />
          <el-button type="primary" :disabled="!newComment.trim()" style="margin-top: 8px" @click="handleAddComment">发送</el-button>
        </div>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'

const API = '/api/v1/tickets'
const tickets = ref<any[]>([])
const statusFilter = ref('')
const currentPage = ref(1)
const total = ref(0)
const perPage = 15
const showCreate = ref(false)
const detailVisible = ref(false)
const detailTicket = ref<any>(null)
const comments = ref<any[]>([])
const newComment = ref('')
const form = ref({ subject: '', description: '', priority: 'medium', category: '' })

const formatDate = (d: string) => d ? d.substring(0, 16) : '-'
const statusType = (s: string) => ({ open: 'info', in_progress: 'warning', resolved: 'success', closed: 'danger' }[s] || 'info') as any
const statusLabel = (s: string) => ({ open: '待处理', in_progress: '处理中', resolved: '已解决', closed: '已关闭' }[s] || s)
const priorityType = (p: string) => ({ low: 'info', medium: 'warning', high: 'danger', urgent: 'danger' }[p] || 'info') as any

const fetchTickets = async () => {
  try {
    const params: any = { page: currentPage.value, per_page: perPage }
    if (statusFilter.value) params.status = statusFilter.value
    const r = await axios.get(API, { params })
    tickets.value = r.data.data || []
    total.value = r.data.meta?.total ?? r.data.data?.length ?? 0
  } catch { tickets.value = [] }
}

const handleCreate = async () => {
  try {
    await axios.post(API, form.value)
    showCreate.value = false
    form.value = { subject: '', description: '', priority: 'medium', category: '' }
    await fetchTickets()
    ElMessage.success('创建成功')
  } catch (e: any) { ElMessage.error(e.response?.data?.message || '创建失败') }
}

const viewTicket = async (t: any) => {
  detailTicket.value = t
  detailVisible.value = true
  try {
    const r = await axios.get(`${API}/${t.ticket_id}/comments`)
    comments.value = r.data.data || []
  } catch { comments.value = [] }
}

const resolveTicket = async (t: any) => {
  try {
    await axios.post(`${API}/${t.ticket_id}/resolve`)
    ElMessage.success('工单已标记为已解决')
    await fetchTickets()
  } catch (e: any) { ElMessage.error(e.response?.data?.message || '操作失败') }
}

const deleteTicket = async (t: any) => {
  try {
    await ElMessageBox.confirm(`确定删除工单 "${t.subject}"？`, '警告', { type: 'warning' })
    await axios.delete(`${API}/${t.ticket_id}`)
    await fetchTickets()
    ElMessage.success('已删除')
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '删除失败')
  }
}

const handleAddComment = async () => {
  if (!detailTicket.value || !newComment.value.trim()) return
  try {
    await axios.post(`${API}/${detailTicket.value.ticket_id}/comments`, { content: newComment.value })
    newComment.value = ''
    const r = await axios.get(`${API}/${detailTicket.value.ticket_id}/comments`)
    comments.value = r.data.data || []
    ElMessage.success('评论已发送')
  } catch (e: any) { ElMessage.error(e.response?.data?.message || '发送失败') }
}

onMounted(() => fetchTickets())
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.filter-bar { margin-bottom: 16px; }
.detail-meta { display: flex; gap: 8px; align-items: center; margin-bottom: 12px; }
.meta-text { font-size: 13px; color: var(--el-text-color-secondary); }
.detail-desc { color: var(--el-text-color-secondary); font-size: 14px; line-height: 1.6; margin-bottom: 16px; }
.comment-item { padding: 8px 0; border-bottom: 1px solid var(--el-border-color-lighter); }
.comment-meta { font-size: 12px; color: var(--el-text-color-secondary); margin-bottom: 4px; }
.comment-content { font-size: 14px; }
.comment-form { margin-top: 16px; display: flex; flex-direction: column; align-items: flex-end; }
</style>
