<template>
  <div class="page">
    <div class="page-header">
      <h1 class="page-title">通知中心</h1>
      <div class="header-actions">
        <button class="btn-sm" @click="markAllRead" :disabled="unreadCount === 0">全部已读</button>
        <router-link to="/notifications/preferences" class="btn-sm btn-outline">偏好设置</router-link>
      </div>
    </div>

    <!-- 筛选 -->
    <div class="filters">
      <button :class="['filter-btn', !filterType && !unreadOnly && 'active']" @click="setFilter()">全部</button>
      <button :class="['filter-btn', unreadOnly && 'active']" @click="setFilter('', true)">未读 ({{ unreadCount }})</button>
    </div>

    <!-- 列表 -->
    <div class="notification-list">
      <div v-if="loading" class="empty">加载中...</div>
      <div v-else-if="items.length === 0" class="empty">暂无通知</div>
      <div
        v-for="item in items"
        :key="item.id"
        :class="['notification-item', !item.read_at && 'unread']"
        @click="markRead(item)"
      >
        <div class="notification-content">
          <span class="notification-title">{{ item.title }}</span>
          <span v-if="item.content" class="notification-body">{{ item.content }}</span>
        </div>
        <div class="notification-meta">
          <span class="notification-time">{{ formatTime(item.created_at) }}</span>
          <button class="btn-icon" @click.stop="remove(item.id)" title="删除">×</button>
        </div>
      </div>
    </div>

    <!-- 分页 -->
    <div v-if="lastPage > 1" class="pagination">
      <button :disabled="currentPage <= 1" @click="goPage(currentPage - 1)">上一页</button>
      <span>{{ currentPage }} / {{ lastPage }}</span>
      <button :disabled="currentPage >= lastPage" @click="goPage(currentPage + 1)">下一页</button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'

const loading = ref(true)
const items = ref<any[]>([])
const currentPage = ref(1)
const lastPage = ref(1)
const unreadCount = ref(0)
const filterType = ref('')
const unreadOnly = ref(false)

onMounted(() => fetchList())

async function fetchList() {
  loading.value = true
  const token = localStorage.getItem('user_token')
  const params = new URLSearchParams({ per_page: '20', page: String(currentPage.value) })
  if (filterType.value) params.set('type', filterType.value)
  if (unreadOnly.value) params.set('unread_only', '1')

  try {
    const res = await fetch(`/api/v1/in-app-notifications?${params}`, {
      headers: { Authorization: `Bearer ${token}` },
    })
    const data = await res.json()
    items.value = data.data || []
    currentPage.value = data.meta?.current_page || 1
    lastPage.value = data.meta?.last_page || 1
    unreadCount.value = data.meta?.unread_count || 0
  } catch {}
  loading.value = false
}

function setFilter(type = '', unread = false) {
  filterType.value = type
  unreadOnly.value = unread
  currentPage.value = 1
  fetchList()
}

function goPage(page: number) {
  currentPage.value = page
  fetchList()
}

async function markRead(item: any) {
  if (item.read_at) return
  const token = localStorage.getItem('user_token')
  try {
    await fetch(`/api/v1/in-app-notifications/${item.id}/read`, {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}` },
    })
    item.read_at = new Date().toISOString()
    unreadCount.value = Math.max(0, unreadCount.value - 1)
  } catch {}
}

async function markAllRead() {
  const token = localStorage.getItem('user_token')
  try {
    await fetch('/api/v1/in-app-notifications/read-all', {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}` },
    })
    items.value.forEach(i => { i.read_at = i.read_at || new Date().toISOString() })
    unreadCount.value = 0
  } catch {}
}

async function remove(id: number) {
  const token = localStorage.getItem('user_token')
  try {
    await fetch(`/api/v1/in-app-notifications/${id}`, {
      method: 'DELETE',
      headers: { Authorization: `Bearer ${token}` },
    })
    items.value = items.value.filter(i => i.id !== id)
  } catch {}
}

function formatTime(dateStr: string): string {
  if (!dateStr) return ''
  const d = new Date(dateStr)
  const now = new Date()
  const diff = now.getTime() - d.getTime()
  if (diff < 60000) return '刚刚'
  if (diff < 3600000) return `${Math.floor(diff / 60000)} 分钟前`
  if (diff < 86400000) return `${Math.floor(diff / 3600000)} 小时前`
  return d.toLocaleDateString('zh-CN')
}
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.page-title { font-size: 24px; }
.header-actions { display: flex; gap: 8px; }
.btn-sm { padding: 6px 12px; font-size: 13px; border: none; border-radius: 4px; cursor: pointer; background: #1976d2; color: #fff; text-decoration: none; }
.btn-sm:disabled { opacity: 0.5; }
.btn-outline { background: #fff; border: 1px solid #ddd; color: #333; }
.filters { display: flex; gap: 8px; margin-bottom: 16px; }
.filter-btn { padding: 6px 14px; font-size: 13px; border: 1px solid #ddd; border-radius: 16px; background: #fff; cursor: pointer; }
.filter-btn.active { background: #e3f2fd; border-color: #1976d2; color: #1976d2; }
.notification-list { background: #fff; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
.notification-item { display: flex; justify-content: space-between; align-items: flex-start; padding: 16px 20px; border-bottom: 1px solid #f5f5f5; cursor: pointer; transition: background 0.15s; }
.notification-item:hover { background: #fafafa; }
.notification-item.unread { border-left: 3px solid #1976d2; }
.notification-content { flex: 1; display: flex; flex-direction: column; gap: 4px; }
.notification-title { font-size: 14px; font-weight: 500; }
.notification-body { font-size: 13px; color: #666; }
.notification-meta { display: flex; align-items: center; gap: 8px; }
.notification-time { font-size: 12px; color: #999; white-space: nowrap; }
.btn-icon { background: none; border: none; font-size: 18px; color: #999; cursor: pointer; padding: 0 4px; }
.btn-icon:hover { color: #e53935; }
.empty { padding: 40px; text-align: center; color: #999; }
.pagination { display: flex; justify-content: center; align-items: center; gap: 16px; margin-top: 20px; }
.pagination button { padding: 6px 14px; border: 1px solid #ddd; border-radius: 4px; background: #fff; cursor: pointer; }
.pagination button:disabled { opacity: 0.4; cursor: not-allowed; }
</style>
