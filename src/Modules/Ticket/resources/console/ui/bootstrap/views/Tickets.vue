<template>
  <div class="page">
    <div class="page-header">
      <h2>工单管理</h2>
      <button class="primary-btn" @click="showCreate = true">+ 创建工单</button>
    </div>

    <div class="panel">
      <div class="filter-bar">
        <select v-model="statusFilter" @change="fetchTickets">
          <option value="">全部状态</option>
          <option value="open">待处理</option>
          <option value="in_progress">处理中</option>
          <option value="resolved">已解决</option>
          <option value="closed">已关闭</option>
        </select>
      </div>

      <table class="data-table">
        <thead>
          <tr><th>ID</th><th>标题</th><th>优先级</th><th>状态</th><th>创建时间</th><th>操作</th></tr>
        </thead>
        <tbody>
          <tr v-for="t in tickets" :key="t.ticket_id">
            <td>{{ t.ticket_id }}</td>
            <td>{{ t.subject }}</td>
            <td><span :class="['badge', priorityClass(t.priority)]">{{ t.priority }}</span></td>
            <td><span :class="['badge', statusClass(t.status)]">{{ statusLabel(t.status) }}</span></td>
            <td>{{ formatDate(t.created_at) }}</td>
            <td>
              <button class="link-btn" @click="viewTicket(t)">查看</button>
              <button v-if="t.status !== 'resolved' && t.status !== 'closed'" class="link-btn" @click="resolveTicket(t)">解决</button>
              <button class="link-btn danger" @click="deleteTicket(t)">删除</button>
            </td>
          </tr>
          <tr v-if="tickets.length === 0"><td colspan="6" class="empty-row">暂无工单</td></tr>
        </tbody>
      </table>

      <div v-if="totalPages > 1" class="pagination">
        <button :disabled="currentPage <= 1" @click="goPage(currentPage - 1)">上一页</button>
        <span>{{ currentPage }} / {{ totalPages }}</span>
        <button :disabled="currentPage >= totalPages" @click="goPage(currentPage + 1)">下一页</button>
      </div>
    </div>

    <!-- 创建工单 -->
    <div class="modal-backdrop" v-if="showCreate" @click="showCreate = false">
      <div class="modal-content" @click.stop>
        <h3>创建工单</h3>
        <form @submit.prevent="handleCreate">
          <div class="form-group"><label>标题</label><input v-model="form.subject" required /></div>
          <div class="form-group"><label>描述</label><textarea v-model="form.description" rows="4"></textarea></div>
          <div class="form-group"><label>优先级</label>
            <select v-model="form.priority">
              <option value="low">低</option><option value="medium">中</option>
              <option value="high">高</option><option value="urgent">紧急</option>
            </select>
          </div>
          <div class="form-group"><label>分类</label><input v-model="form.category" placeholder="bug/feature/..." /></div>
          <div class="form-actions"><button type="button" @click="showCreate = false">取消</button><button type="submit" class="primary-btn">创建</button></div>
        </form>
      </div>
    </div>

    <!-- 工单详情 + 评论 -->
    <div class="modal-backdrop" v-if="detailTicket" @click="detailTicket = null">
      <div class="modal-content modal-lg" @click.stop>
        <h3>{{ detailTicket.subject }}</h3>
        <div class="detail-meta">
          <span :class="['badge', statusClass(detailTicket.status)]">{{ statusLabel(detailTicket.status) }}</span>
          <span :class="['badge', priorityClass(detailTicket.priority)]">{{ detailTicket.priority }}</span>
          <span class="meta-text">{{ formatDate(detailTicket.created_at) }}</span>
        </div>
        <p v-if="detailTicket.description" class="detail-desc">{{ detailTicket.description }}</p>

        <h4>评论</h4>
        <div v-for="c in comments" :key="c.comment_id" class="comment-item">
          <div class="comment-meta">{{ c.user_id }} · {{ formatDate(c.created_at) }}</div>
          <div class="comment-content">{{ c.content }}</div>
        </div>
        <div v-if="comments.length === 0" class="empty-row">暂无评论</div>

        <form @submit.prevent="handleAddComment" class="comment-form">
          <textarea v-model="newComment" rows="2" placeholder="添加评论..."></textarea>
          <button type="submit" class="primary-btn" :disabled="!newComment.trim()">发送</button>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const API = '/api/v1/tickets'
const tickets = ref<any[]>([])
const statusFilter = ref('')
const currentPage = ref(1)
const totalPages = ref(1)
const showCreate = ref(false)
const detailTicket = ref<any>(null)
const comments = ref<any[]>([])
const newComment = ref('')
const form = ref({ subject: '', description: '', priority: 'medium', category: '' })

const formatDate = (d: string) => d ? d.substring(0, 16) : '-'
const statusClass = (s: string) => ({ open: 'badge-info', in_progress: 'badge-warning', resolved: 'badge-success', closed: 'badge-danger' }[s] || 'badge-info')
const statusLabel = (s: string) => ({ open: '待处理', in_progress: '处理中', resolved: '已解决', closed: '已关闭' }[s] || s)
const priorityClass = (p: string) => ({ low: 'badge-info', medium: 'badge-warning', high: 'badge-danger', urgent: 'badge-danger' }[p] || 'badge-info')

const fetchTickets = async (page = 1) => {
  try {
    const params: any = { page, per_page: 15 }
    if (statusFilter.value) params.status = statusFilter.value
    const r = await axios.get(API, { params })
    tickets.value = r.data.data || []
    totalPages.value = r.data.meta?.last_page ?? 1
    currentPage.value = page
  } catch { tickets.value = [] }
}

const goPage = (p: number) => fetchTickets(p)

const handleCreate = async () => {
  try {
    await axios.post(API, form.value)
    showCreate.value = false
    form.value = { subject: '', description: '', priority: 'medium', category: '' }
    await fetchTickets()
  } catch (e: any) { alert(e.response?.data?.message || '创建失败') }
}

const viewTicket = async (t: any) => {
  detailTicket.value = t
  try {
    const r = await axios.get(`${API}/${t.ticket_id}/comments`)
    comments.value = r.data.data || []
  } catch { comments.value = [] }
}

const resolveTicket = async (t: any) => {
  try { await axios.post(`${API}/${t.ticket_id}/resolve`); await fetchTickets(currentPage.value) } catch (e: any) { alert(e.response?.data?.message || '操作失败') }
}

const deleteTicket = async (t: any) => {
  if (!confirm(`确定删除工单 "${t.subject}"？`)) return
  try { await axios.delete(`${API}/${t.ticket_id}`); await fetchTickets(currentPage.value) } catch (e: any) { alert(e.response?.data?.message || '删除失败') }
}

const handleAddComment = async () => {
  if (!detailTicket.value || !newComment.value.trim()) return
  try {
    await axios.post(`${API}/${detailTicket.value.ticket_id}/comments`, { content: newComment.value })
    newComment.value = ''
    const r = await axios.get(`${API}/${detailTicket.value.ticket_id}/comments`)
    comments.value = r.data.data || []
  } catch (e: any) { alert(e.response?.data?.message || '发送失败') }
}

onMounted(() => fetchTickets())
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.primary-btn { padding: 8px 16px; background: var(--link-color, #6366f1); color: #fff; border: none; border-radius: 6px; cursor: pointer; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.filter-bar { display: flex; gap: 12px; margin-bottom: 16px; }
.filter-bar select { padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.data-table th { color: var(--text-color-secondary); font-weight: 500; }
.data-table td { color: var(--text-color-primary); }
.empty-row { text-align: center; color: var(--text-color-secondary); padding: 24px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-info { background: var(--badge-info-bg); color: var(--badge-info-fg); }
.badge-success { background: var(--badge-success-bg); color: var(--badge-success-fg); }
.badge-warning { background: var(--badge-warning-bg); color: var(--badge-warning-fg); }
.badge-danger { background: var(--badge-danger-bg); color: var(--badge-danger-fg); }
.link-btn { background: none; border: none; color: var(--link-color); cursor: pointer; font-size: 13px; padding: 0 4px; }
.link-btn.danger { color: var(--link-danger); }
.pagination { display: flex; align-items: center; justify-content: center; gap: 16px; margin-top: 16px; }
.pagination button { padding: 6px 14px; border: 1px solid var(--border-color); border-radius: 6px; background: var(--bg-color); cursor: pointer; font-size: 13px; color: var(--text-color-primary); }
.pagination button:disabled { opacity: 0.4; cursor: not-allowed; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-content { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; min-width: 460px; max-width: 600px; max-height: 80vh; overflow-y: auto; color: var(--text-color-primary); }
.modal-content h3 { margin: 0 0 20px; }
.modal-content h4 { margin: 16px 0 8px; font-size: 14px; color: var(--text-color-secondary); }
.modal-lg { min-width: 600px; max-width: 720px; }
.detail-meta { display: flex; gap: 8px; align-items: center; margin-bottom: 12px; }
.meta-text { font-size: 13px; color: var(--text-color-secondary); }
.detail-desc { color: var(--text-color-secondary); font-size: 14px; margin-bottom: 16px; line-height: 1.6; }
.comment-item { padding: 8px 0; border-bottom: 1px solid var(--border-color, #eee); }
.comment-meta { font-size: 12px; color: var(--text-color-secondary); margin-bottom: 4px; }
.comment-content { font-size: 14px; color: var(--text-color-primary); }
.comment-form { display: flex; gap: 8px; margin-top: 12px; }
.comment-form textarea { flex: 1; padding: 8px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; resize: vertical; background: var(--bg-color); color: var(--text-color-primary); }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 13px; color: var(--text-color-secondary); }
.form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; box-sizing: border-box; background: var(--bg-color); color: var(--text-color-primary); }
.form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
.form-actions button { padding: 8px 16px; border-radius: 6px; border: 1px solid var(--border-color, #ddd); background: var(--bg-color); cursor: pointer; color: var(--text-color-primary); }
</style>
