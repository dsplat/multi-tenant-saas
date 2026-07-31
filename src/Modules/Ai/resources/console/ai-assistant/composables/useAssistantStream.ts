/**
 * useAssistantStream — 小助手流式连接（Node SSE 引擎链路）
 *
 * 传输层走 ai-streaming Node 引擎（data stream 行协议），
 * 编排语义（工具执行/权限/配额）仍由引擎回调 PHP 契约 API 完成。
 * 引擎是哑管道：form_fill 等结构化建议经工具结果帧（a: 行）透传，
 * 由本解析器按 result.action 标记识别，引擎不感知 UI 语义。
 *
 * 遵循铁律：
 *  - 空闲 120s（无任何字节到达）自动断开；Node 链路无心跳帧，需容忍模型思考间隙
 *  - 任何错误都不抛出，转为降级提示（fail-open）
 *  - 支持随时中断（AbortController）
 */
import { ref } from 'vue'
import axios from 'axios'
import type { PageContext, ToolCall, FormFillSuggestion, WorkflowSuggestion, ConversationMeta, ActionConfirmData } from '../types'

/** 流空闲超时（毫秒）：Node 链路无心跳帧，放宽到 120s 容忍长思考 */
const STREAM_IDLE_TIMEOUT_MS = 120_000

/**
 * Node SSE 引擎端点（nginx 反代 {public_path}/chat）。
 * 项目可通过 VITE_AI_STREAM_ENDPOINT 环境变量覆盖。
 */
const STREAM_ENDPOINT = (import.meta as any).env?.VITE_AI_STREAM_ENDPOINT || '/ai-stream/chat'

/**
 * 旧 PHP 助手端点根（仅剩 confirm-action 复用；流式已切 Node 引擎）。
 * 项目覆盖端点可能带 /stream 后缀（如 scrm 的 …/assistant/stream），需剥离后再拼接。
 */
const ASSISTANT_ENDPOINT = (import.meta as any).env?.VITE_AI_ASSISTANT_ENDPOINT || '/api/v1/ai/assistant'
const ASSISTANT_BASE = ASSISTANT_ENDPOINT.endsWith('/stream')
  ? ASSISTANT_ENDPOINT.slice(0, -'/stream'.length)
  : ASSISTANT_ENDPOINT
export const CONFIRM_ACTION_ENDPOINT = `${ASSISTANT_BASE}/confirm-action`

/** 上行历史消息（由面板从 store 提取，纯文本轮次） */
export interface HistoryTurn {
  role: 'user' | 'assistant'
  content: string
}

export interface StreamCallbacks {
  /** 会话元信息（Node 链路经 2: data 帧下发 conversation_id，前端持久化用于续接） */
  onMeta?: (meta: ConversationMeta) => void
  onText: (text: string) => void
  onToolCall: (calls: ToolCall[]) => void
  /** 工具结果帧（a: 行）：回填工具卡片执行状态 */
  onToolResult?: (toolCallId: string, result: any) => void
  onFormFill: (suggestion: FormFillSuggestion) => void
  onWorkflow: (workflow: WorkflowSuggestion) => void
  onPendingConfirmation?: (data: ActionConfirmData) => void
  onDone: (metadata?: Record<string, any> | null) => void
  onError: (message: string, action?: { label: string; route: string } | null) => void
}

/**
 * 复刻 PHP PageContext::toPromptContext + buildMessage 的消息包装格式，
 * 页面上下文注入由前端完成（引擎哑管道，不感知上下文语义）。
 */
function buildContextMessage(ctx: PageContext, userIntent: string): string {
  const parts = [
    `当前页面: ${ctx.route || ''}`,
    `模块: ${ctx.module || ''}`,
  ]

  if (ctx.entity_type) {
    parts.push(`实体: ${ctx.entity_id ? `${ctx.entity_type}#${ctx.entity_id}` : ctx.entity_type}`)
  }
  if (ctx.visible_data_summary) {
    parts.push(`页面数据: ${ctx.visible_data_summary}`)
  }
  if (ctx.form_state && Object.keys(ctx.form_state).length > 0) {
    parts.push(`表单状态: ${JSON.stringify(ctx.form_state)}`)
  }

  return `[页面上下文]\n${parts.join('\n')}\n\n[用户请求]\n${userIntent}`
}

export function useAssistantStream() {
  const streaming = ref(false)
  let abortController: AbortController | null = null
  let timeoutTimer: ReturnType<typeof setTimeout> | null = null

  /** 重置空闲计时器：每次收到字节调用，超过空闲阈值才断开 */
  function resetIdleTimer() {
    if (timeoutTimer) clearTimeout(timeoutTimer)
    timeoutTimer = setTimeout(() => {
      abortController?.abort()
    }, STREAM_IDLE_TIMEOUT_MS)
  }

  /**
   * 发起一次流式对话。
   * 返回的 Promise 永远 resolve（不 reject），错误通过 onError 回调降级。
   *
   * @param pageContext 页面上下文（含转派 agent_id 时定向目标员工）
   * @param userIntent  用户本轮输入原话
   * @param history     此前轮次的纯文本历史（引擎无状态，多轮记忆由前端携带）
   */
  async function send(
    pageContext: PageContext & { agent_id?: number | string | null },
    userIntent: string,
    callbacks: StreamCallbacks,
    history: HistoryTurn[] = [],
  ): Promise<void> {
    if (streaming.value) return

    streaming.value = true
    abortController = new AbortController()
    resetIdleTimer()

    try {
      // 历史轮次为纯文本；仅当前轮包装页面上下文（与旧 PHP 链路行为一致）
      const messages: Array<{ role: string; content: string }> = [
        ...history.filter(h => h.content).map(h => ({ role: h.role, content: h.content })),
        { role: 'user', content: buildContextMessage(pageContext, userIntent) },
      ]

      const body: Record<string, any> = { messages }
      // 转派后定向目标员工；缺省由 PHP resolve 兑底系统小助手
      const agentId = Number(pageContext.agent_id)
      if (Number.isFinite(agentId) && agentId > 0) body.agent_id = agentId
      // 续接已有会话（缺省由 PHP resolve 创建新会话并经 2: 帧下发）
      const conversationId = Number(pageContext.conversation_id)
      if (Number.isFinite(conversationId) && conversationId > 0) body.conversation_id = conversationId

      // 复用 axios 的认证头（Bearer + X-Tenant-ID）
      const headers: Record<string, string> = { 'Content-Type': 'application/json' }
      const auth = axios.defaults.headers.common['Authorization']
      if (auth) headers['Authorization'] = String(auth)
      const tenant = axios.defaults.headers.common['X-Tenant-ID']
      if (tenant) headers['X-Tenant-ID'] = String(tenant)

      const response = await fetch(STREAM_ENDPOINT, {
        method: 'POST',
        headers,
        body: JSON.stringify(body),
        signal: abortController.signal,
      })

      if (!response.ok) {
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
   * 消费 data stream 行协议（Vercel AI SDK：每行「类型:JSON」）。
   *  0: 文本增量   2: 自定义数据（会话元信息）   9: 工具调用   a: 工具结果   3: 错误   d: 流结束
   */
  async function consumeStream(body: ReadableStream<Uint8Array>, callbacks: StreamCallbacks): Promise<void> {
    const reader = body.getReader()
    const decoder = new TextDecoder('utf-8')
    let buffer = ''
    let finished = false

    try {
      while (true) {
        const { done, value } = await reader.read()
        if (done) break

        resetIdleTimer()
        buffer += decoder.decode(value, { stream: true })

        let idx: number
        while ((idx = buffer.indexOf('\n')) !== -1) {
          const line = buffer.slice(0, idx).trim()
          buffer = buffer.slice(idx + 1)
          if (line && handleStreamLine(line, callbacks)) {
            finished = true
            return
          }
        }
      }
      if (buffer.trim()) {
        finished = handleStreamLine(buffer.trim(), callbacks)
      }
    } finally {
      reader.releaseLock()
      // 服务端提前关流（无 d: 帧）也要收尾，避免面板卡在流式态
      if (!finished) callbacks.onDone(null)
    }
  }

  /**
   * 解析单行数据帧。返回 true 表示流已结束（d: 帧）。
   */
  function handleStreamLine(line: string, callbacks: StreamCallbacks): boolean {
    const sep = line.indexOf(':')
    if (sep === -1) return false

    const type = line.slice(0, sep)
    const payload = line.slice(sep + 1)

    try {
      switch (type) {
        case '0': { // 文本增量
          const text = JSON.parse(payload)
          if (typeof text === 'string') callbacks.onText(text)
          break
        }
        case '2': { // 自定义数据帧（数组）：Node 下发的会话元信息
          const items = JSON.parse(payload)
          if (Array.isArray(items)) {
            for (const item of items) {
              if (item?.type === 'meta' && item.conversation_id) {
                callbacks.onMeta?.({ conversation_id: Number(item.conversation_id), agent_id: item.agent_id ?? null })
              }
            }
          }
          break
        }
        case '9': { // 工具调用（执行中，结果帧到达后回填状态）
          const call = JSON.parse(payload)
          callbacks.onToolCall([{ id: call.toolCallId, name: call.toolName, arguments: call.args ?? {}, status: 'running' }])
          break
        }
        case 'a': { // 工具结果：回填卡片状态 + 识别结构化建议标记（引擎哑管道，语义在前端）
          const parsed = JSON.parse(payload)
          const result = parsed?.result
          if (parsed?.toolCallId) {
            callbacks.onToolResult?.(String(parsed.toolCallId), result)
          }
          if (result?.action === 'form_fill' && result.fields) {
            callbacks.onFormFill({
              fields: result.fields,
              explanation: result.explanation ?? null,
              field_notes: result.field_notes ?? null,
              confidence: result.confidence ?? 0.8,
            } as FormFillSuggestion)
          } else if (result?.action === 'workflow' && result.steps) {
            callbacks.onWorkflow(result as WorkflowSuggestion)
          } else if (result?.action === 'pending_confirmation' && result.token) {
            // L2 确认门：PHP 签发的确认载荷经工具结果帧透传，渲染确认卡片
            callbacks.onPendingConfirmation?.({
              token: result.token,
              args_hash: result.args_hash,
              expires_in: result.expires_in ?? 300,
              tool_slug: result.tool_slug,
              tool_name: result.tool_name ?? result.tool_slug,
              arguments: result.arguments ?? {},
              conversation_id: result.conversation_id,
            } as ActionConfirmData)
          }
          break
        }
        case '3': { // 错误帧
          const msg = JSON.parse(payload)
          callbacks.onError(typeof msg === 'string' && msg ? msg : 'AI 助手遇到错误。')
          break
        }
        case 'd': // 流结束
          callbacks.onDone(null)
          return true
      }
    } catch {
      // 非法 JSON 行，静默跳过（不中断流）
    }

    return false
  }

  /** 中断当前流 */
  function abort() {
    abortController?.abort()
  }

  return { streaming, send, abort }
}
