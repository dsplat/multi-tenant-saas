<script setup lang="ts">
/**
 * HistoryList — 历史会话列表（面板内视图）
 *
 * 多会话管理：分页加载、点击切换会话、两步确认删除。
 * 以面板内覆盖视图呈现（避免抽屉套抽屉），返回按钮回到对话区。
 */
import { ref, onMounted } from 'vue'
import { useAssistantStore } from '../../stores/assistant'
import { useAssistantConversations } from '../composables/useAssistantConversations'

const emit = defineEmits<{
  (e: 'select', conversationId: number): void
  (e: 'close'): void
}>()

const store = useAssistantStore()
const { conversations, loading, hasMore, load, loadMore, remove } = useAssistantConversations()

/** 待确认删除的会话 ID（两步确认：再点一次才真删） */
const pendingDeleteId = ref<number | null>(null)
/** 删除失败的会话 ID（短暂提示） */
const deleteFailedId = ref<number | null>(null)

onMounted(() => load(1))

function formatTime(iso?: string | null): string {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  const now = new Date()
  const sameDay = d.toDateString() === now.toDateString()
  const hm = `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
  return sameDay ? hm : `${d.getMonth() + 1}/${d.getDate()} ${hm}`
}

function handleSelect(id: number) {
  emit('select', id)
}

async function handleDelete(id: number) {
  // 第一次点击进入待确认态，再次点击执行删除
  if (pendingDeleteId.value !== id) {
    pendingDeleteId.value = id
    deleteFailedId.value = null
    return
  }
  pendingDeleteId.value = null
  const ok = await remove(id)
  if (!ok) {
    deleteFailedId.value = id
    return
  }
  // 删除的是当前会话 → 回到新会话状态
  if (store.conversationId === id) {
    store.startNewConversation()
  }
}
</script>

<template>
  <div class="history-list">
    <div class="history-header">
      <button class="back-btn" title="返回对话" @click="emit('close')">←</button>
      <span class="history-title">历史会话</span>
    </div>

    <div class="history-scroll">
      <!-- 空态 -->
      <div v-if="!loading && conversations.length === 0" class="history-empty">
        <div class="history-empty-icon">💬</div>
        <div class="history-empty-text">暂无历史会话</div>
      </div>

      <!-- 会话列表 -->
      <div
        v-for="conv in conversations"
        :key="conv.conversation_id"
        class="history-item"
        :class="{ active: conv.conversation_id === store.conversationId }"
        @click="handleSelect(conv.conversation_id)"
      >
        <div class="item-main">
          <div class="item-subject">{{ conv.subject || '未命名会话' }}</div>
          <div class="item-time">{{ formatTime(conv.updated_at) }}</div>
        </div>
        <button
          class="item-delete"
          :class="{ confirming: pendingDeleteId === conv.conversation_id }"
          :title="pendingDeleteId === conv.conversation_id ? '再次点击确认删除' : '删除会话'"
          @click.stop="handleDelete(conv.conversation_id)"
        >
          {{ pendingDeleteId === conv.conversation_id ? '确认删除？' : '🗑' }}
        </button>
        <span v-if="deleteFailedId === conv.conversation_id" class="item-error">删除失败</span>
      </div>

      <!-- 分页加载 -->
      <div v-if="loading" class="history-loading">加载中…</div>
      <button v-else-if="hasMore()" class="load-more" @click="loadMore()">加载更多</button>
    </div>
  </div>
</template>

<style scoped>
.history-list {
  display: flex;
  flex-direction: column;
  flex: 1;
  min-height: 0;
}
.history-header {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 16px;
  border-bottom: 1px solid var(--border-color, #e2e8f0);
  flex-shrink: 0;
}
.back-btn {
  width: 28px; height: 28px; border: none; border-radius: 6px;
  background: transparent; cursor: pointer; font-size: 15px;
  display: flex; align-items: center; justify-content: center;
  transition: background 0.15s;
}
.back-btn:hover { background: var(--fill-color, #f1f5f9); }
.history-title { font-size: 13px; font-weight: 600; color: var(--text-color-primary, #0f172a); }

.history-scroll { flex: 1; overflow-y: auto; padding: 8px; }

.history-empty { text-align: center; padding: 48px 12px; }
.history-empty-icon { font-size: 32px; margin-bottom: 8px; opacity: 0.6; }
.history-empty-text { font-size: 12px; color: var(--text-color-secondary, #64748b); }

.history-item {
  display: flex; align-items: center; gap: 8px;
  padding: 10px 12px; border-radius: 10px;
  cursor: pointer; transition: background 0.15s;
}
.history-item:hover { background: var(--fill-color, #f8fafc); }
.history-item.active {
  background: color-mix(in srgb, var(--ac, #10b981) 8%, transparent);
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--ac, #10b981) 30%, transparent);
}
.item-main { flex: 1; min-width: 0; }
.item-subject {
  font-size: 13px; color: var(--text-color-primary, #0f172a);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.item-time { font-size: 11px; color: var(--text-color-secondary, #64748b); margin-top: 2px; }
.item-delete {
  border: none; border-radius: 6px; background: transparent;
  cursor: pointer; font-size: 12px; padding: 4px 6px;
  color: var(--text-color-secondary, #64748b);
  opacity: 0; transition: opacity 0.15s, background 0.15s;
  flex-shrink: 0; white-space: nowrap;
}
.history-item:hover .item-delete { opacity: 1; }
.item-delete.confirming {
  opacity: 1;
  color: #fff;
  background: var(--badge-danger-fg, #f5222d);
}
.item-error { font-size: 11px; color: var(--badge-danger-fg, #f5222d); flex-shrink: 0; }

.history-loading {
  text-align: center; padding: 12px;
  font-size: 12px; color: var(--text-color-secondary, #64748b);
}
.load-more {
  display: block; width: 100%; padding: 8px;
  border: none; border-radius: 8px; background: transparent;
  font-size: 12px; color: var(--ac, #10b981); cursor: pointer;
  transition: background 0.15s;
}
.load-more:hover { background: var(--fill-color, #f8fafc); }
</style>
