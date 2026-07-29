/**
 * useAssistantConversations — 小助手历史会话列表（多会话管理）
 *
 * 封装 GET /ai/assistant/conversations（分页）与 DELETE /ai/assistant/conversations/{id}。
 * 遵循铁律：所有请求异步、失败静默降级（列表为空但不影响对话）。
 */
import axios from 'axios'
import { ref } from 'vue'
import type { ConversationSummary } from '../types'

/**
 * 会话列表端点。从 VITE_AI_ASSISTANT_ENDPOINT 推导
 * （…/stream → …/conversations，否则追加 /conversations），与 history 端点同规则。
 */
function resolveConversationsEndpoint(): string {
  const env = (import.meta as any).env || {}
  if (env.VITE_AI_CONVERSATIONS_ENDPOINT) return env.VITE_AI_CONVERSATIONS_ENDPOINT
  const base: string = env.VITE_AI_ASSISTANT_ENDPOINT || '/api/v1/ai/assistant'
  return base.endsWith('/stream')
    ? base.slice(0, -'/stream'.length) + '/conversations'
    : base + '/conversations'
}

const CONVERSATIONS_ENDPOINT = resolveConversationsEndpoint()
const PER_PAGE = 20

export function useAssistantConversations() {
  const conversations = ref<ConversationSummary[]>([])
  const loading = ref(false)
  const currentPage = ref(0)
  const lastPage = ref(1)
  const total = ref(0)

  /** 是否还有下一页 */
  function hasMore(): boolean {
    return currentPage.value < lastPage.value
  }

  /** 加载指定页（page=1 时重置列表，否则追加） */
  async function load(page = 1): Promise<void> {
    if (loading.value) return
    loading.value = true
    try {
      const resp = await axios.get(CONVERSATIONS_ENDPOINT, {
        params: { page, per_page: PER_PAGE },
        timeout: 8000,
      })
      const data = resp.data?.data
      const list: ConversationSummary[] = Array.isArray(data?.conversations) ? data.conversations : []
      conversations.value = page === 1 ? list : [...conversations.value, ...list]
      currentPage.value = data?.meta?.current_page ?? page
      lastPage.value = data?.meta?.last_page ?? page
      total.value = data?.meta?.total ?? list.length
    } catch {
      // 静默降级：列表加载失败不影响对话
      if (page === 1) conversations.value = []
    } finally {
      loading.value = false
    }
  }

  /** 加载下一页（追加） */
  async function loadMore(): Promise<void> {
    if (!hasMore()) return
    await load(currentPage.value + 1)
  }

  /** 删除会话，成功返回 true 并从本地列表移除 */
  async function remove(conversationId: number): Promise<boolean> {
    try {
      await axios.delete(`${CONVERSATIONS_ENDPOINT}/${conversationId}`, { timeout: 8000 })
      conversations.value = conversations.value.filter(c => c.conversation_id !== conversationId)
      total.value = Math.max(0, total.value - 1)
      return true
    } catch {
      return false
    }
  }

  return { conversations, loading, total, hasMore, load, loadMore, remove }
}
