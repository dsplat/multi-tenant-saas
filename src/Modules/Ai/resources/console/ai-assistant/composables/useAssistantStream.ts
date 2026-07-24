/**
 * useAssistantStream — SSE 流式连接管理
 *
 * 用 fetch + ReadableStream 消费 POST SSE（EventSource 不支持 POST）。
 * 遵循铁律：
 *  - 超时 30s 自动断开（非阻塞）
 *  - 任何错误都不抛出，转为降级提示（fail-open）
 *  - 支持随时中断（AbortController）
 */
import { ref } from 'vue'
import axios from 'axios'
import type { PageContext, SseMessage, ToolCall, FormFillSuggestion, WorkflowSuggestion } from '../types'

/** SSE 流式超时（毫秒） */
const STREAM_TIMEOUT_MS = 30_000

/**
 * AI 助手 SSE 端点。
 * 项目可通过 VITE_AI_ASSISTANT_ENDPOINT 环境变量覆盖（如 scrm 项目指向 /api/v1/scrm/ai/assistant/stream）。
 * 默认使用框架级端点。
 */
const ASSISTANT_ENDPOINT = (import.meta as any).env?.VITE_AI_ASSISTANT_ENDPOINT || '/api/v1/ai/assistant'

export interface StreamCallbacks {
  onText: (text: string) => void
  onToolCall: (calls: ToolCall[]) => void
  onFormFill: (suggestion: FormFillSuggestion) => void
  onWorkflow: (workflow: WorkflowSuggestion) => void
  onDone: (metadata?: Record<string, any> | null) => void
  onError: (message: string) => void
}

export function useAssistantStream() {
  const streaming = ref(false)
  let abortController: AbortController | null = null
  let timeoutTimer: ReturnType<typeof setTimeout> | null = null

  /**
   * 发起一次流式对话。
   * 返回的 Promise 永远 resolve（不 reject），错误通过 onError 回调降级。
   */
  async function send(pageContext: PageContext, userIntent: string, callbacks: StreamCallbacks): Promise<void> {
    if (streaming.value) return

    streaming.value = true
    abortController = new AbortController()

    // 超时保护：30s 自动断开
    timeoutTimer = setTimeout(() => {
      abortController?.abort()
    }, STREAM_TIMEOUT_MS)

    try {
      const payload: PageContext = {
        ...pageContext,
        user_intent: userIntent,
      }

      // 复用 axios 的认证头（Bearer + X-Tenant-ID）
      const headers: Record<string, string> = {
        'Content-Type': 'application/json',
        Accept: 'text/event-stream',
      }
      const auth = axios.defaults.headers.common['Authorization']
      if (auth) headers['Authorization'] = String(auth)
      const tenant = axios.defaults.headers.common['X-Tenant-ID']
      if (tenant) headers['X-Tenant-ID'] = String(tenant)

      const response = await fetch(ASSISTANT_ENDPOINT, {
        method: 'POST',
        headers,
        body: JSON.stringify(payload),
        signal: abortController.signal,
      })

      if (!response.ok) {
        // 非 200：尝试读取 JSON 错误信息
        let msg = 'AI 助手暂时不可用，请使用页面原有功能操作。'
        try {
          const err = await response.json()
          if (err?.message) msg = err.message
        } catch { /* 忽略解析失败 */ }
        callbacks.onError(msg)
        return
      }

      if (!response.body) {
        callbacks.onError('AI 助手响应为空，请稍后重试。')
        return
      }

      await consumeStream(response.body, callbacks)
    } catch (e: any) {
      if (e?.name === 'AbortError') {
        callbacks.onError('AI 响应超时或已中断。页面功能不受影响。')
      } else {
        callbacks.onError('AI 助手连接失败，请使用页面原有功能操作。')
      }
    } finally {
      streaming.value = false
      abortController = null
      if (timeoutTimer) {
        clearTimeout(timeoutTimer)
        timeoutTimer = null
      }
    }
  }

  /**
   * 逐行解析 SSE 流（data: JSON\n\n 格式）。
   */
  async function consumeStream(body: ReadableStream<Uint8Array>, callbacks: StreamCallbacks): Promise<void> {
    const reader = body.getReader()
    const decoder = new TextDecoder('utf-8')
    let buffer = ''

    try {
      while (true) {
        const { done, value } = await reader.read()
        if (done) break

        buffer += decoder.decode(value, { stream: true })

        // 按 SSE 事件边界（空行）切分
        let idx: number
        while ((idx = buffer.indexOf('\n\n')) !== -1) {
          const rawEvent = buffer.slice(0, idx)
          buffer = buffer.slice(idx + 2)
          handleSseEvent(rawEvent, callbacks)
        }
      }
      // 处理残留 buffer
      if (buffer.trim()) {
        handleSseEvent(buffer, callbacks)
      }
    } finally {
      reader.releaseLock()
    }
  }

  /**
   * 解析单个 SSE 事件块。
   */
  function handleSseEvent(raw: string, callbacks: StreamCallbacks) {
    const lines = raw.split('\n')
    for (const line of lines) {
      if (!line.startsWith('data:')) continue
      const data = line.slice(5).trim()

      if (data === '[DONE]') {
        callbacks.onDone(null)
        return
      }

      try {
        const msg = JSON.parse(data) as SseMessage
        switch (msg.type) {
          case 'text':
            if (typeof msg.content === 'string') callbacks.onText(msg.content)
            break
          case 'tool_call':
            callbacks.onToolCall(Array.isArray(msg.content) ? msg.content : [msg.content])
            break
          case 'form_fill':
            callbacks.onFormFill(msg.content as FormFillSuggestion)
            break
          case 'workflow':
            callbacks.onWorkflow(msg.content as WorkflowSuggestion)
            break
          case 'done':
            callbacks.onDone(msg.metadata)
            break
          case 'error':
            callbacks.onError(String(msg.content || 'AI 助手遇到错误。'))
            break
        }
      } catch {
        // 非法 JSON 行，静默跳过（不中断流）
      }
    }
  }

  /** 中断当前流 */
  function abort() {
    abortController?.abort()
  }

  return { streaming, send, abort }
}
