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

import { serve } from '@hono/node-server'
import { createOpenAI } from '@ai-sdk/openai'
import { jsonSchema, streamText, tool, StreamData, type Tool } from 'ai'
import { Hono } from 'hono'

const VERSION = '1.0.0'

const HOST = process.env.AI_STREAMING_NODE_HOST ?? '127.0.0.1'
const PORT = Number(process.env.AI_STREAMING_NODE_PORT ?? 9200)
const PHP_API_BASE = (process.env.AI_STREAMING_PHP_API_BASE ?? 'http://127.0.0.1/api/v1').replace(/\/+$/, '')
const FALLBACK_API_KEY = process.env.AI_STREAMING_API_KEY ?? ''

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
  })

  const json = (await response.json().catch(() => ({}))) as { success?: boolean; message?: string; data?: T }

  if (!response.ok || json.success === false) {
    throw new PhpApiError(response.status, json.message ?? `PHP API ${path} 调用失败 (HTTP ${response.status})`)
  }

  return json.data as T
}

// ---------- 工具桥接：LLM tool_call → PHP tools/execute ----------

function buildTools(resolved: ResolvePayload, auth: AuthContext) {
  const tools: Record<string, Tool> = {}

  for (const def of resolved.tools ?? []) {
    const fn = def.function
    if (!fn?.name) continue

    tools[fn.name] = tool({
      description: fn.description ?? '',
      parameters: jsonSchema(fn.parameters ?? { type: 'object', properties: {} }),
      execute: async (args: unknown, options?: { toolCallId?: string }) => {
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
          return data.result
        } catch (error) {
          // 工具失败不打断流：把错误作为观察结果交还给 LLM
          return { error: true, message: error instanceof Error ? error.message : String(error) }
        }
      },
    })
  }

  return tools
}

// ---------- HTTP 服务 ----------

const app = new Hono()

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

  const body = await c.req.json<{ agent_id?: number; conversation_id?: number; messages?: Array<{ role: 'user' | 'assistant' | 'system'; content: string }> }>().catch(() => null)
  if (!Array.isArray(body?.messages) || body.messages.length === 0) {
    return c.json({ success: false, message: '参数错误：messages 必填' }, 422)
  }
  const inputMessages = body.messages

  // 1. 回调 PHP：鉴权 + 配额检查 + Agent 配置解析（失败即拒绝，零 LLM 开销）
  // agent_id 省略时由 PHP 兑底到租户的系统小助手（console 小助手入口）
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
  const provider = createOpenAI({ baseURL: resolved.base_url, apiKey, compatibility: 'compatible' })

  // 会话元信息经 2: data 帧下发前端（前端持久化 conversation_id 用于续接/历史恢复）
  const streamData = new StreamData()
  if (resolved.conversation_id) {
    streamData.append({ type: 'meta', conversation_id: resolved.conversation_id, agent_id: resolved.agent_id })
  }

  const result = streamText({
    model: provider.chat(resolved.model),
    system: resolved.system_prompt || undefined,
    messages: inputMessages,
    temperature: resolved.temperature,
    maxTokens: resolved.max_tokens,
    tools: buildTools(resolved, auth),
    maxSteps: Math.max(1, resolved.max_tool_calls) + 1,
    onFinish: async ({ text, usage, finishReason, steps }) => {
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
          const lastUser = [...inputMessages].reverse().find((m) => m.role === 'user')
          // 保留 LLM 原生 tool_call id：PHP 续答时需按 OpenAI 协议与 tool 消息成对回放
          const toolCalls = (steps ?? []).flatMap((step) =>
            (step.toolCalls ?? []).map((call) => ({ id: call.toolCallId, name: call.toolName, arguments: call.args ?? {} })),
          )
          await phpPost('/ai-streaming/messages/report', auth, {
            conversation_id: resolved.conversation_id,
            agent_id: resolved.agent_id,
            user_message: lastUser?.content ?? null,
            assistant_message: text ?? '',
            tool_calls: toolCalls,
          })
        } catch (error) {
          console.error('[ai-streaming] message report failed:', error)
        }
      }
    },
  })

  return result.toDataStreamResponse({ data: streamData })
})

serve({ fetch: app.fetch, hostname: HOST, port: PORT }, (info) => {
  console.log(`[ai-streaming] engine v${VERSION} listening on http://${info.address}:${info.port} → PHP ${PHP_API_BASE}`)
})
