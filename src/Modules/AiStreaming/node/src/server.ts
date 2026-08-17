/**
 * AiStreaming Node SSE 引擎
 *
 * 纯无状态 SSE 管道：鉴权、配额、工具执行、用量结算全部回调 PHP 契约 API。
 * 本进程不落库、不管理会话，崩溃可任意重启。
 *
 * 端点：
 *   GET  /health  健康探针（ai-streaming:status 使用）
 *   POST /chat    SSE 流式对话（浏览器经 nginx {public_path}/chat 进入）
 *
 * 环境变量：
 *   AI_STREAMING_NODE_HOST      监听地址（默认 127.0.0.1）
 *   AI_STREAMING_NODE_PORT      监听端口（默认 9200）
 *   AI_STREAMING_PHP_API_BASE   PHP 契约 API 基址（默认 http://127.0.0.1/api/v1）
 *   AI_STREAMING_API_KEY        key_delivery=none 时的本地兜底 key（可选）
 */

import { createServer, type IncomingMessage, type ServerResponse } from 'node:http'
import { AsyncLocalStorage } from 'node:async_hooks'
import { getRequestListener } from '@hono/node-server'
import { createOpenAI } from '@ai-sdk/openai'
import { jsonSchema, streamText, tool, StreamData, type Tool } from 'ai'
import { Hono } from 'hono'

const VERSION = '1.0.0'

const HOST = process.env.AI_STREAMING_NODE_HOST ?? '127.0.0.1'
const PORT = Number(process.env.AI_STREAMING_NODE_PORT ?? 9200)
const PHP_API_BASE = (process.env.AI_STREAMING_PHP_API_BASE ?? 'http://127.0.0.1/api/v1').replace(/\/+$/, '')
const FALLBACK_API_KEY = process.env.AI_STREAMING_API_KEY ?? ''

/**
 * LLM 首字节超时（毫秒）：兼容网关偶发挂起（连接建立但 0 字节返回），
 * Node fetch 无默认超时会一直等到 nginx 504。首字节到达后解除超时，
 * 流式传输期间由 nginx proxy_read_timeout 与前端空闲超时兜底。
 */
const LLM_FIRST_BYTE_TIMEOUT_MS = Number(process.env.AI_STREAMING_LLM_TIMEOUT_MS ?? 90_000)

/**
 * 心跳帧间隔（毫秒）：LLM 长思考/工具执行间隙流上无任何字节，
 * 前置代理层（SLB/WAF/CDN）的空闲超时会掐断长静默连接，用户侧表现为
 * 504/「响应超时」。周期性下发 2: ping 数据帧保活（前端解析器对非 meta
 * 类型静默忽略，ping 字节到达同时重置前端空闲计时器）。
 * 默认 5s：相对实测前置代理空闲超时 ~60s 留 12 倍余量，单帧十几字节开销可忽略。
 */
const KEEPALIVE_INTERVAL_MS = Number(process.env.AI_STREAMING_KEEPALIVE_MS ?? 5_000)

/**
 * AI 长任务流内轮询：工具快速提交任务（await_task）后按此间隔短连接
 * 轮询 tasks/status 直至终态；总等待上限防任务失控挂死工具循环。
 */
const TASK_POLL_INTERVAL_MS = Number(process.env.AI_TASK_POLL_MS ?? 3_000)
const TASK_MAX_WAIT_MS = Number(process.env.AI_TASK_MAX_WAIT_MS ?? 600_000)
/** PHP 契约 API 单次调用上限：fetch 无默认超时，挂死会阻塞轮询 deadline 判定 */
const PHP_POST_TIMEOUT_MS = Number(process.env.AI_STREAMING_PHP_TIMEOUT_MS ?? 120_000)

/** 用户可见的礼貌错误（经 AI SDK 错误帧 3: 透传到前端） */
const LLM_TIMEOUT_MESSAGE = 'AI 服务响应超时，请稍后重试。'
const LLM_GENERIC_ERROR_MESSAGE = 'AI 助手遇到错误，请稍后重试。'

/** 已知可展示的错误文案白名单；其余错误一律屏蔽内部细节，统一降级提示 */
const FRIENDLY_ERROR_MESSAGES = new Set([LLM_TIMEOUT_MESSAGE])

/** AI SDK 默认 getErrorMessage 返回固定的 "An error occurred."，据此映射为用户可读文案 */
function toFriendlyErrorMessage(error: unknown): string {
  const message = error instanceof Error ? error.message : String(error)
  return FRIENDLY_ERROR_MESSAGES.has(message) ? message : LLM_GENERIC_ERROR_MESSAGE
}

/**
 * 带首字节超时的 fetch：仅约束「响应头到达前」的等待时长，
 * 响应头到达后立即解除定时器，不误杀正常但较长的流式回复。
 * 与 AI SDK 自身的 abort signal 合并（外部中断优先透传）。
 */
function createFirstByteTimeoutFetch(): typeof fetch {
  return async (input: Parameters<typeof fetch>[0], init?: Parameters<typeof fetch>[1]) => {
    const controller = new AbortController()
    const timer = setTimeout(() => controller.abort(), LLM_FIRST_BYTE_TIMEOUT_MS)
    const external = init?.signal
    if (external) {
      if (external.aborted) controller.abort()
      else external.addEventListener('abort', () => controller.abort(), { once: true })
    }
    try {
      const response = await fetch(input, { ...init, signal: controller.signal })
      clearTimeout(timer)
      return response
    } catch (error) {
      clearTimeout(timer)
      // 外部主动中断（用户取消）原样抛出；超时则转为用户可读错误
      if (!external?.aborted && controller.signal.aborted) {
        throw new Error(LLM_TIMEOUT_MESSAGE)
      }
      throw error
    }
  }
}

// ---------- PHP 契约 API 客户端（透传浏览器鉴权：Bearer 或 Cookie 会话） ----------

interface ResolvePayload {
  tenant_id: number
  agent_id: number
  conversation_id?: number
  provider: string
  model: string
  base_url: string
  api_key?: string
  system_prompt: string
  temperature: number
  max_tokens: number
  max_tool_calls: number
  tools: Array<{ type: 'function'; function: { name: string; description?: string; parameters?: Record<string, unknown> } }>
  /** 服务端历史（事实源 = DB，PHP StreamHistoryBuilder 产物）：存在时作唯一上下文，前端上行历史忽略 */
  history?: Array<Record<string, unknown>>
}

/** 流内工具执行结果上报项（onFinish 随 messages/report 落库 role=tool） */
interface ToolResultReport {
  tool_call_id: string
  tool_name: string
  content: string
}

interface AuthContext {
  authorization?: string
  /** Cookie 会话模式：透传浏览器 Cookie，PHP 侧 stateful 中间件据此解析会话 */
  cookie?: string
  /** Cookie 会话模式必需：PHP 依 Origin/Referer 判定 stateful（命中 sanctum.stateful 才启动会话） */
  origin?: string
  /** 从浏览器 Cookie 中提取的 XSRF-TOKEN（URL 编码原值），回调 PHP 时 URL 解码后作 X-XSRF-TOKEN 头过 CSRF */
  xsrfToken?: string
  tenantId?: string
}

/** 从 Cookie 头提取指定 cookie 的原始值（不做 URL 解码，保持与 Laravel 下发时一致） */
function extractCookieValue(cookieHeader: string, name: string): string | undefined {
  const match = cookieHeader.match(new RegExp(`(?:^|;\\s*)${name}=([^;]*)`))
  return match?.[1] || undefined
}

class PhpApiError extends Error {
  constructor(public status: number, message: string) {
    super(message)
  }
}

async function phpPost<T>(path: string, auth: AuthContext, body: unknown): Promise<T> {
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  }
  // 双模鉴权：优先 Bearer（纯 API 客户端）；否则透传 Cookie + Origin 走会话
  if (auth.authorization) {
    headers['Authorization'] = auth.authorization
  }
  if (auth.cookie) {
    headers['Cookie'] = auth.cookie
  }
  if (auth.origin) {
    headers['Origin'] = auth.origin
  }
  // stateful 写请求需过 CSRF：X-XSRF-TOKEN 需 Cookie 中 XSRF-TOKEN 的 URL 解码值
  // （浏览器 document.cookie 自动解码后回传；Cookie 请求头中仍是 URL 编码原值）
  if (auth.xsrfToken) {
    try {
      headers['X-XSRF-TOKEN'] = decodeURIComponent(auth.xsrfToken)
    } catch {
      headers['X-XSRF-TOKEN'] = auth.xsrfToken
    }
  }
  // 透传浏览器的 X-Tenant-ID：多租户 Operator 切换团队后回调 PHP 才能命中正确租户
  if (auth.tenantId) {
    headers['X-Tenant-ID'] = auth.tenantId
  }

  const response = await fetch(`${PHP_API_BASE}${path}`, {
    method: 'POST',
    headers,
    body: JSON.stringify(body),
    signal: AbortSignal.timeout(PHP_POST_TIMEOUT_MS),
  })

  const json = (await response.json().catch(() => ({}))) as { success?: boolean; message?: string; data?: T }

  if (!response.ok || json.success === false) {
    throw new PhpApiError(response.status, json.message ?? `PHP API ${path} 调用失败 (HTTP ${response.status})`)
  }

  return json.data as T
}

// ---------- 工具桥接：LLM tool_call → PHP tools/execute ----------

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms))
}

/**
 * await_task 流内轮询：工具提交长任务（重模型生成，如活动策划）后，
 * 在工具 execute 内部按间隔短连接轮询 tasks/status 直至终态——
 * 对 LLM 与前端完全无感（流级心跳保活期间持续生效）。
 * 客户端断连时上报 abandoned（任务不杀，完成后 PHP 兜底落库会话）并短路返回。
 */
async function awaitTaskResult(taskId: number, auth: AuthContext, abortSignal?: AbortSignal): Promise<unknown> {
  const deadline = Date.now() + TASK_MAX_WAIT_MS
  for (;;) {
    if (abortSignal?.aborted) {
      // 任务生命周期独立于连接：通知 PHP 标记放弃，完成后结果落库原会话
      phpPost('/ai-streaming/tasks/status', auth, { task_id: taskId, abandoned: true }).catch(() => {})
      return { error: true, message: 'client disconnected' }
    }
    try {
      const data = await phpPost<{ status: string; result?: unknown; error?: string }>('/ai-streaming/tasks/status', auth, { task_id: taskId })
      if (data.status === 'completed') return data.result ?? { status: 'completed' }
      if (data.status === 'failed') return { error: true, message: data.error ?? '后台任务执行失败' }
    } catch (error) {
      // 404（任务不存在/跨租户）快速失败；其余瞬时错误不断轮询
      if (error instanceof PhpApiError && error.status === 404) {
        return { error: true, message: error.message }
      }
      console.warn('[ai-streaming] task status poll failed:', error instanceof Error ? error.message : String(error))
    }
    if (Date.now() > deadline) {
      return {
        error: true,
        message: `后台任务等待超时（超过 ${Math.round(TASK_MAX_WAIT_MS / 60_000)} 分钟），任务可能仍在后台完成，请告知用户稍后重新询问查看结果`,
      }
    }
    await sleep(TASK_POLL_INTERVAL_MS)
  }
}

function buildTools(resolved: ResolvePayload, auth: AuthContext, toolResults: ToolResultReport[], abortSignal?: AbortSignal) {
  const tools: Record<string, Tool> = {}

  for (const def of resolved.tools ?? []) {
    const fn = def.function
    if (!fn?.name) continue

    tools[fn.name] = tool({
      description: fn.description ?? '',
      parameters: jsonSchema(fn.parameters ?? { type: 'object', properties: {} }),
      execute: async (args: unknown, options?: { toolCallId?: string }) => {
        // 客户端已断连：短路拒绝，不再回调 PHP 触发工具副作用
        // （ai@4.3 的 abortSignal 不中断多步工具循环，必须在执行层自行拦截）
        if (abortSignal?.aborted) {
          return { error: true, message: 'client disconnected' }
        }
        try {
          const data = await phpPost<{ result: unknown }>('/ai-streaming/tools/execute', auth, {
            agent_id: resolved.agent_id,
            tool: fn.name,
            arguments: args ?? {},
            // L2 确认门：确认令牌绑定会话，PHP 遇 L2 工具时据此签发 pending_confirmation
            conversation_id: resolved.conversation_id ?? null,
            // LLM 原生 tool_call id：随令牌存储，确认后续答时与落库的 assistant.tool_calls 配对
            tool_call_id: options?.toolCallId ?? null,
          })
          // 任务化长工具：PHP 毫秒级提交后返回 await_task，
          // 此处转入流内轮询直至终态（LLM/前端无感，心跳保活不断连）
          const execResult = data.result as { action?: string; task_id?: number } | null
          const toolResult =
            execResult && execResult.action === 'await_task' && execResult.task_id
              ? await awaitTaskResult(execResult.task_id, auth, abortSignal)
              : data.result
          collectToolResult(toolResults, options?.toolCallId, fn.name, toolResult)
          return toolResult
        } catch (error) {
          // 工具失败不打断流：把错误作为观察结果交还给 LLM
          const errorResult = { error: true, message: error instanceof Error ? error.message : String(error) }
          collectToolResult(toolResults, options?.toolCallId, fn.name, errorResult)
          return errorResult
        }
      },
    })
  }

  return tools
}

/**
 * 收集流内工具执行结果（与 LLM 实际看到的值同构）：
 * onFinish 随 messages/report 上报 PHP 落库 role=tool，下一轮经服务端历史
 * 重建回到模型上下文——plan_id/agent_id 等关键数值跨轮传递不靠猜。
 * 缺 tool_call_id 的不收集（PHP 侧无法与 tool_calls 配对，落库即拒收）。
 */
function collectToolResult(collector: ToolResultReport[], toolCallId: string | undefined, toolName: string, result: unknown): void {
  if (!toolCallId) return
  collector.push({
    tool_call_id: toolCallId,
    tool_name: toolName,
    content: typeof result === 'string' ? result : JSON.stringify(result) ?? '',
  })
}

// ---------- 内容安全守护（第一道闸：本地轻量扫描，安全先于鉴权/配额/LLM） ----------

/** 拒绝文案（与 PHP ContentGuardService 口径一致） */
const GUARD_MESSAGE =
  '抱歉，这类请求超出了我的能力范围。我是系统的 AI 小助手，只能帮你完成系统内的业务操作（营销策划、客户管理、消息触达等）。有其他业务需要随时告诉我。'

/** 归一化：全角转半角 + 去空白 + 转小写（防 rm -r -f、大小写混写等变体绕过） */
function normalizeGuardText(text: string): string {
  let out = ''
  for (const ch of text) {
    const code = ch.charCodeAt(0)
    if (code === 0x3000) continue // 全角空格
    out += code >= 0xff01 && code <= 0xff5e ? String.fromCharCode(code - 0xfee0) : ch
  }
  return out.replace(/\s+/g, '').toLowerCase()
}

/** 内置拦截规则（与 PHP ContentGuardService::BUILTIN_PATTERNS 同口径；归一化后无空白，量词用 \s* 兼容） */
const GUARD_PATTERNS: RegExp[] = [
  // 系统命令/shell 执行诱导（能力归零铁律：即使只读命令也无此能力）
  /rm-?r-?f/, /rm-r-f/, /\/bin\/(ba|z|da)?sh\b/,
  /(curl|wget).{0,40}\|(ba|z)?sh/, /\/dev\/tcp\//, /\bnc\s*-[a-z]*e/,
  /reverseshell/, /\bmkfs\b/, /\bdd\s*if=.{0,30}of=\/dev\//,
  /\bshutdown\b/, /\breboot\b/, /\bkill\s*-9\s*1\b/,
  // SQL 破坏诱导（归一化后连写，只保留首部词边界）
  /\bdrop\s*(table|database)/, /\btruncate\s*table/, /\bdelete\s*from\s*\w+/,
  // 代码执行诱导
  /\beval\s*\(/, /\b(exec|system|passthru|shell_exec|popen|proc_open|pcntl_exec)\s*\(/,
  // 超范围破坏诉求（删除/清空 数据库/系统/所有数据，双向语序）
  /(删除|清空|抹掉|格式化|销毁).{0,8}(数据库|数据表|系统|服务器|所有数据|全部数据)/,
  /(数据库|数据表|系统|服务器|所有数据|全部数据).{0,8}(删了|删掉|删光|清空掉|抹掉|格式化|销毁|清除)/,
  // 违法违规基础词
  /(制作|购买|出售).{0,6}(枪支|军火|炸药|毒品)/,
]

/** 返回 true 表示命中拦截 */
function guardBlocked(text: string): boolean {
  if (!text.trim()) return false
  const normalized = normalizeGuardText(text)
  return GUARD_PATTERNS.some((p) => p.test(normalized))
}

// ---------- 入口净化（防历史伪造/注入与 token 炸弹） ----------

/** 历史轮次上限（防超长上下文刷爆平台账单，同时缓解历史污染自我模仿） */
const MAX_HISTORY_MESSAGES = 40
/** 单条消息长度上限 */
const MAX_CONTENT_LENGTH = 20000

/**
 * 净化前端传入的 messages：
 *  - 只保留 user/assistant 角色（system 提示词仅由 PHP resolve 下发，
 *    防篡改客户端注入 system 消息覆盖系统提示词）
 *  - 单条超长截断、总轮次取最近 N 条
 */
function sanitizeMessages(messages: Array<{ role: string; content: unknown }>): Array<{ role: 'user' | 'assistant'; content: string }> {
  return messages
    .filter((m) => (m.role === 'user' || m.role === 'assistant') && typeof m.content === 'string')
    .slice(-MAX_HISTORY_MESSAGES)
    .map((m) => ({
      role: m.role as 'user' | 'assistant',
      content: (m.content as string).length > MAX_CONTENT_LENGTH ? (m.content as string).slice(0, MAX_CONTENT_LENGTH) : (m.content as string),
    }))
}

/**
 * 服务端历史（OpenAI wire 格式，PHP StreamHistoryBuilder 产物）转 Vercel AI SDK
 * CoreMessage 格式：assistant.content 为 parts 数组（text/tool-call），tool 消息为
 * tool-result parts——wire 格式直接传 streamText 会触发 AI_InvalidPromptError。
 *
 * toolName 从前序 assistant.tool_calls 的 id→name 映射派生（PHP 侧
 * reconcileToolCallPairs 已保证 tool 消息与 tool_calls 严格配对）；
 * 映射不到的 tool 消息属协议异常，降级为 user 观察文本保留信息。
 */
function serverHistoryToCoreMessages(history: Array<Record<string, unknown>>): Array<Record<string, unknown>> {
  const core: Array<Record<string, unknown>> = []
  const callNames = new Map<string, string>()

  for (const msg of history) {
    const role = msg.role as string
    const content = typeof msg.content === 'string' ? msg.content : ''

    if (role === 'user') {
      core.push({ role: 'user', content })
      continue
    }

    if (role === 'assistant') {
      const rawCalls = Array.isArray(msg.tool_calls) ? (msg.tool_calls as Array<Record<string, unknown>>) : []
      const parts: Array<Record<string, unknown>> = []
      if (content !== '') {
        parts.push({ type: 'text', text: content })
      }
      for (const call of rawCalls) {
        const fn = (call?.function ?? {}) as Record<string, unknown>
        const id = String(call?.id ?? '')
        const name = String(fn.name ?? '')
        let args: unknown = {}
        try {
          args = typeof fn.arguments === 'string' && fn.arguments !== '' ? JSON.parse(fn.arguments) : {}
        } catch {
          args = {} // 非法 JSON 参数不给模型回放，工具执行记录在库中可查
        }
        if (id !== '' && name !== '') {
          callNames.set(id, name)
        }
        parts.push({ type: 'tool-call', toolCallId: id, toolName: name, args })
      }
      core.push({ role: 'assistant', content: parts })
      continue
    }

    if (role === 'tool') {
      const callId = String(msg.tool_call_id ?? '')
      const toolName = callNames.get(callId)
      if (callId === '' || toolName === undefined) {
        // 配对异常（理论上不会发生，reconcile 已保证）：降级保留信息
        core.push({ role: 'user', content: `[工具执行结果] ${content}` })
        continue
      }
      let result: unknown = content
      try {
        result = content !== '' ? JSON.parse(content) : ''
      } catch {
        /* 非 JSON 结果保持原文本 */
      }
      core.push({
        role: 'tool',
        content: [{ type: 'tool-result', toolCallId: callId, toolName, result }],
      })
    }
    // system 及其他角色忽略（system_prompt 经 resolve 单独下发）
  }

  return core
}

// ---------- HTTP 服务 ----------

const app = new Hono()

/**
 * 断连中止器经 AsyncLocalStorage 传入 hono handler（@hono/node-server 不在
 * 断连时 abort req.raw.signal，也不暴露 incoming，需自建 request 监听器接管）。
 */
const abortStore = new AsyncLocalStorage<AbortController>()
let httpServer: ReturnType<typeof createServer> | null = null
function requestAbortSignal(): AbortSignal | undefined {
  return abortStore.getStore()?.signal
}

app.get('/health', (c) => c.json({ ok: true, version: VERSION }))

app.post('/chat', async (c) => {
  const authorization = c.req.header('authorization') ?? ''
  const cookie = c.req.header('cookie') ?? ''
  // 双模认证：Bearer token 或 Cookie 会话（SPA stateful）二选一
  if (!authorization && !cookie) {
    return c.json({ success: false, message: '缺少认证信息（Authorization 头或会话 Cookie）' }, 401)
  }
  const auth: AuthContext = {
    authorization: authorization || undefined,
    cookie: cookie || undefined,
    origin: c.req.header('origin') || c.req.header('referer') || undefined,
    xsrfToken: extractCookieValue(cookie, 'XSRF-TOKEN'),
    tenantId: c.req.header('x-tenant-id'),
  }

  const body = await c.req.json<{ agent_id?: number; conversation_id?: number; messages?: Array<{ role: 'user' | 'assistant'; content: string }> }>().catch(() => null)
  if (!Array.isArray(body?.messages) || body.messages.length === 0) {
    return c.json({ success: false, message: '参数错误：messages 必填' }, 422)
  }
  // 入口净化：过滤 system/tool 角色、限长限轮次（防伪造历史注入与 token 炸弹）
  const inputMessages = sanitizeMessages(body.messages)
  if (inputMessages.length === 0) {
    return c.json({ success: false, message: '参数错误：无有效的 user/assistant 消息' }, 422)
  }

  // 内容安全守护（第一道闸，先于鉴权/配额/LLM，零网络开销）：
  // 命中破坏性指令/代码执行诱导等直接拒绝，与 PHP ContentGuardService 同口径
  const lastUser = [...inputMessages].reverse().find((m) => m.role === 'user')
  if (lastUser && guardBlocked(lastUser.content)) {
    return c.json({ success: false, message: GUARD_MESSAGE }, 422)
  }

  // 1. 回调 PHP：鉴权 + 配额检查 + Agent 配置解析（失败即拒绝，零 LLM 开销）
  // agent_id 省略时由 PHP 兜底到租户的系统小助手（console 小助手入口）
  // conversation_id 由 PHP 续接/创建（落库归属），Node 不管理会话
  let resolved: ResolvePayload
  try {
    resolved = await phpPost<ResolvePayload>('/ai-streaming/resolve', auth, {
      agent_id: body.agent_id ?? null,
      conversation_id: body.conversation_id ?? null,
    })
  } catch (error) {
    const status = error instanceof PhpApiError ? error.status : 502
    return c.json({ success: false, message: error instanceof Error ? error.message : String(error) }, status as 402)
  }

  const apiKey = resolved.api_key || FALLBACK_API_KEY
  if (!apiKey) {
    return c.json({ success: false, message: '未获得 API Key（key_delivery=none 时需配置 AI_STREAMING_API_KEY）' }, 500)
  }

  // 2. 直连 LLM（OpenAI 兼容端点），SSE 流式转发
  //    自定义 fetch 注入首字节超时：网关挂起时快速失败并经错误帧礼貌提示，
  //    避免 Node 无限等待导致 nginx 504（前端只能显示兜底错误）
  const provider = createOpenAI({
    baseURL: resolved.base_url,
    apiKey,
    compatibility: 'compatible',
    fetch: createFirstByteTimeoutFetch(),
  })

  // 会话元信息经 2: data 帧下发前端（前端持久化 conversation_id 用于续接/历史恢复）
  const streamData = new StreamData()
  if (resolved.conversation_id) {
    streamData.append({ type: 'meta', conversation_id: resolved.conversation_id, agent_id: resolved.agent_id })
  }

  // 心跳保活：模型思考间隙周期性下发 ping 帧，防前置代理空闲超时掐断长连接
  const heartbeat = setInterval(() => {
    try {
      streamData.append({ type: 'ping', at: Date.now() })
    } catch {
      /* 流已关闭时的竞态，忽略 */
    }
  }, KEEPALIVE_INTERVAL_MS)

  // 客户端断连感知：连接关闭时中止 LLM 循环——否则用户/代理断开后引擎
  // 继续跑完整工具链（孤儿执行），白白消耗 LLM token 并触发 PHP 工具副作用
  const streamAbort = new AbortController()
  const abortSignal = requestAbortSignal()
  const onUpstreamAbort = () => streamAbort.abort()
  abortSignal?.addEventListener('abort', onUpstreamAbort, { once: true })
  const cleanupStream = () => {
    clearInterval(heartbeat)
    abortSignal?.removeEventListener('abort', onUpstreamAbort)
  }

  // 流内工具结果收集器：onFinish 随 messages/report 上报落库（跨轮数值不靠猜）
  const toolResults: ToolResultReport[] = []

  // 上下文事实源切换：resolve 下发服务端历史（DB 重建，含工具结果）时以其为
  // 唯一上下文，前端 messages 仅取当前用户输入（已经 sanitizeMessages + ContentGuard）；
  // 未下发（开关关闭/旧 PHP）时保持旧行为透传前端历史（向后兼容）
  const lastUserInput = [...inputMessages].reverse().find((m) => m.role === 'user')
  const streamMessages: Array<Record<string, unknown>> = resolved.history
    ? [...serverHistoryToCoreMessages(resolved.history), ...(lastUserInput ? [{ role: 'user', content: lastUserInput.content }] : [])]
    : inputMessages

  const result = streamText({
    model: provider.chat(resolved.model),
    system: resolved.system_prompt || undefined,
    messages: streamMessages as never,
    temperature: resolved.temperature,
    maxTokens: resolved.max_tokens,
    tools: buildTools(resolved, auth, toolResults, streamAbort.signal),
    maxSteps: Math.max(1, resolved.max_tool_calls) + 1,
    abortSignal: streamAbort.signal,
    onError: ({ error }) => {
      cleanupStream()
      // 客户端断连导致的中止属预期路径：不写错误帧（连接已关）、不按异常告警
      if (streamAbort.signal.aborted) {
        console.warn('[ai-streaming] client disconnected, stream aborted')
        return
      }
      // 错误路径也要关闭 streamData，否则 2: 数据帧挂着导致前端流不收尾
      streamData.close().catch(() => {})
      console.error('[ai-streaming] stream error:', error instanceof Error ? error.message : String(error))
    },
    onFinish: async ({ text, usage, finishReason, steps }) => {
      cleanupStream()
      streamData.close().catch(() => {})

      // 3. 流结束后回调 PHP 结算 token（失败仅告警，不影响已完成的响应）
      try {
        await phpPost('/ai-streaming/usage/report', auth, {
          agent_id: resolved.agent_id,
          model: resolved.model,
          input_tokens: usage.promptTokens ?? 0,
          output_tokens: usage.completionTokens ?? 0,
          metadata: { finish_reason: finishReason, steps: steps?.length ?? 1 },
        })
      } catch (error) {
        console.error('[ai-streaming] usage report failed:', error)
      }

      // 4. 本轮消息落库（落库语义归 PHP，Node 仅搬运；失败仅告警）
      if (resolved.conversation_id) {
        try {
          // 拼接所有 steps 的文本：纯工具调用轮的中间步骤文本不丢，
          // 避免 assistant 轮落库空 content 后历史接口过滤导致刷新丢消息
          const fullText = (steps ?? [])
            .map((step) => step.text ?? '')
            .filter(Boolean)
            .join('\n\n') || (text ?? '')
          // 保留 LLM 原生 tool_call id：PHP 续答时需按 OpenAI 协议与 tool 消息成对回放
          const toolCalls = (steps ?? []).flatMap((step) =>
            (step.toolCalls ?? []).map((call) => ({ id: call.toolCallId, name: call.toolName, arguments: call.args ?? {} })),
          )
          await phpPost('/ai-streaming/messages/report', auth, {
            conversation_id: resolved.conversation_id,
            agent_id: resolved.agent_id,
            user_message: lastUserInput?.content ?? null,
            assistant_message: fullText,
            tool_calls: toolCalls,
            // 流内工具结果：PHP 在 assistant 消息之后逐条落 role=tool，
            // 下一轮经服务端历史重建回到模型上下文（老 PHP 不识别该字段时忽略，向后兼容）
            tool_results: toolResults,
          })
        } catch (error) {
          console.error('[ai-streaming] message report failed:', error)
        }
      }
    },
  })

  return result.toDataStreamResponse({ data: streamData, getErrorMessage: toFriendlyErrorMessage })
})

// 自建 HTTP 服务（代替 serve()）：@hono/node-server 不在客户端断连时 abort
// req.raw.signal，需在 hono 处理前为每个请求挂 AbortController。
// 注意：incoming（请求流）的 close 在 body 读尽时即触发（Node 18+），
// 早于流式响应开始，不可用；必须以 outgoing（响应端）close 为准——
// 响应未正常写完（writableFinished=false）即连接关闭 = 客户端/代理断连；
// 响应正常结束时 writableFinished=true，close 不产生误 abort。
httpServer = createServer((incoming: IncomingMessage, outgoing: ServerResponse) => {
  const controller = new AbortController()
  outgoing.on('close', () => {
    if (!outgoing.writableFinished) controller.abort()
  })
  abortStore.run(controller, () => {
    getRequestListener(app.fetch, { hostname: HOST })(incoming, outgoing)
  })
})

httpServer.listen(PORT, HOST, () => {
  console.log(`[ai-streaming] engine v${VERSION} listening on http://${HOST}:${PORT} → PHP ${PHP_API_BASE}`)
})
