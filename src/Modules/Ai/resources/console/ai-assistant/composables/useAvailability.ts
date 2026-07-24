/**
 * useAvailability — AI 助手可用性探测
 *
 * 调用后端 GET /api/v1/ai/assistant/availability 检测租户级 feature flag。
 * 遵循铁律：
 *  - 异步探测，不阻塞首屏渲染
 *  - 探测失败一律视为「不可用」（fail-open，隐藏入口，页面零影响）
 *  - 结果缓存，避免每次路由变化都请求
 */
import axios from 'axios'
import { useAssistantStore } from '../../stores/assistant'

/** 可用性探测端点（项目可通过 VITE_AI_AVAILABILITY_ENDPOINT 覆盖） */
const AVAILABILITY_ENDPOINT = (import.meta as any).env?.VITE_AI_AVAILABILITY_ENDPOINT || '/api/v1/ai/assistant/availability'

/** 探测结果缓存（按模块名） */
const cache = new Map<string, boolean>()

export function useAvailability() {
  const store = useAssistantStore()

  /**
   * 探测指定模块的助手可用性。
   * 静默失败：任何异常都置为不可用，绝不抛出。
   */
  async function check(module = ''): Promise<boolean> {
    // 用户已手动关闭 → 直接不可用，不发请求
    if (!store.userEnabled) {
      store.setAvailability(false)
      return false
    }

    const key = module || '__global__'
    if (cache.has(key)) {
      const ok = cache.get(key)!
      store.setAvailability(ok)
      return ok
    }

    try {
      const resp = await axios.get(AVAILABILITY_ENDPOINT, {
        params: module ? { module } : {},
        timeout: 5000,
      })
      const ok = Boolean(resp.data?.data?.available)
      cache.set(key, ok)
      store.setAvailability(ok)
      return ok
    } catch {
      // 探测失败 → 不可用（fail-open）
      cache.set(key, false)
      store.setAvailability(false)
      return false
    }
  }

  /** 清除缓存（如切换租户后） */
  function invalidate() {
    cache.clear()
  }

  return { check, invalidate }
}
