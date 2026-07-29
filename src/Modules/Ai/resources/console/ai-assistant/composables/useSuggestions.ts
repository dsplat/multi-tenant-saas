/**
 * useSuggestions — 新会话开场引导
 *
 * 调 GET /ai/assistant/suggestions 拉取四块引导数据：
 *  - page_suggestions    页面感知建议（后端按路由前缀匹配，传原始 route.path）
 *  - history_suggestions 最近会话（继续聊入口）
 *  - task_chains         预设任务链（引擎就位前为空数组，见 docs/task-chain.md）
 *  - setup_checklist     租户设置完善度（仅 tenant_admin 返回）
 *
 * 铁律：异步加载不阻塞面板渲染；失败静默降级为内置兜底建议。
 */
import axios from 'axios'
import { ref } from 'vue'
import { useRoute } from 'vue-router'
import type { SuggestionData } from '../types'

/** suggestions 端点。从 VITE_AI_ASSISTANT_ENDPOINT 推导，与 history 端点同规则 */
function resolveSuggestionsEndpoint(): string {
  const env = (import.meta as any).env || {}
  if (env.VITE_AI_SUGGESTIONS_ENDPOINT) return env.VITE_AI_SUGGESTIONS_ENDPOINT
  const base: string = env.VITE_AI_ASSISTANT_ENDPOINT || '/api/v1/ai/assistant'
  return base.endsWith('/stream')
    ? base.slice(0, -'/stream'.length) + '/suggestions'
    : base + '/suggestions'
}

const SUGGESTIONS_ENDPOINT = resolveSuggestionsEndpoint()

export function useSuggestions() {
  const route = useRoute()

  const loading = ref(false)
  const loaded = ref(false)
  const data = ref<SuggestionData | null>(null)

  /** 拉取开场引导（幂等：已加载则跳过，force 可强刷） */
  async function fetchSuggestions(force = false): Promise<void> {
    if (loading.value || (loaded.value && !force)) return
    loading.value = true
    try {
      const resp = await axios.get(SUGGESTIONS_ENDPOINT, {
        // 后端按路径前缀匹配（如 /customers），传原始 path 而非点分 route
        params: { route: route.path },
        timeout: 8000,
      })
      const payload = resp.data?.data
      if (payload && Array.isArray(payload.page_suggestions)) {
        data.value = {
          page_suggestions: payload.page_suggestions,
          history_suggestions: Array.isArray(payload.history_suggestions) ? payload.history_suggestions : [],
          task_chains: Array.isArray(payload.task_chains) ? payload.task_chains : [],
          setup_checklist: payload.setup_checklist ?? null,
        }
      }
    } catch {
      // 静默降级：面板回退到内置兜底建议
      data.value = null
    } finally {
      loading.value = false
      loaded.value = true
    }
  }

  return { loading, loaded, data, fetchSuggestions }
}
