/**
 * useAssistantHistory — 会话历史恢复（刷新不丢）
 *
 * 页面刷新后凭 localStorage 中持久化的 conversation_id
 * 调 GET /ai/assistant/history 拉取历史消息恢复面板。
 * 遵循铁律：
 *  - 异步恢复，不阻塞首屏渲染
 *  - 任何异常静默降级（面板从空对话开始，不影响业务）
 *  - 会话已失效（404）时清除本地持久化，避免死续接
 */
import axios from 'axios'
import { useAssistantStore } from '../../stores/assistant'

/**
 * 历史端点。项目可通过 VITE_AI_HISTORY_ENDPOINT 覆盖；
 * 缺省从 VITE_AI_ASSISTANT_ENDPOINT 推导（…/stream → …/history，否则追加 /history）。
 */
function resolveHistoryEndpoint(): string {
  const env = (import.meta as any).env || {}
  if (env.VITE_AI_HISTORY_ENDPOINT) return env.VITE_AI_HISTORY_ENDPOINT
  const base: string = env.VITE_AI_ASSISTANT_ENDPOINT || '/api/v1/ai/assistant'
  return base.endsWith('/stream')
    ? base.slice(0, -'/stream'.length) + '/history'
    : base + '/history'
}

const HISTORY_ENDPOINT = resolveHistoryEndpoint()

export function useAssistantHistory() {
  const store = useAssistantStore()

  /**
   * 尝试恢复历史会话。幂等：已尝试过 / 已有消息 / 无持久化会话时直接返回。
   */
  async function restore(): Promise<void> {
    if (store.hydrated || store.messages.length > 0) return
    const cid = store.conversationId
    if (!cid) {
      store.markHydrated()
      return
    }

    try {
      const resp = await axios.get(HISTORY_ENDPOINT, {
        params: { conversation_id: cid, limit: 50 },
        timeout: 8000,
      })
      const data = resp.data?.data
      if (Array.isArray(data?.messages)) {
        store.hydrateMessages(data.messages)
      } else {
        store.markHydrated()
      }
    } catch (e: any) {
      if (e?.response?.status === 404) {
        // 会话已失效 → 清除本地持久化，从新会话开始
        store.clearMessages()
      }
      store.markHydrated()
    }
  }

  return { restore }
}
