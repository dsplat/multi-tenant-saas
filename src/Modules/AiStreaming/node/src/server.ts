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
import { jsonSchema, streamText, tool, type Tool } from 'ai'
import { Hono } from 'hono'

const VERSION = '1.0.0'

const HOST = process.env.AI_STREAMING_NODE_HOST ?? '127.0.0.1'
const PORT = Number(process.env.AI_STREAMING_NODE_PORT ?? 9200)
const PHP_API_BASE = (process.env.AI_STREAMING_PHP_API_BASE ?? 'http://127.0.0.1/api/v1').replace(/\/+$/, '')
const FALLBACK_API_KEY = process.env.AI_STREAMING_API_KEY ?? ''

// ---------- PHP 契约 API 客户端（透传浏览器 Authorization） ----------

interface ResolvePayload {
  tenant_id: number
  agent_id: number
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

class PhpApiError extends Error {
  constructor(public status: number, message: string) {
    super(message)
  }
}

async function phpPost<T>(path: string, authorization: string, body: unknown): Promise<T> {
  const response = await fetch(`${PHP_API_BASE}${path}`, {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
      'Authorization': authorization,
    },
    body: JSON.stringify(body),
  })

  const json = (await response.json().catch(() => ({}))) as { success?: boolean; message?: string; data?: T }

  if (!response.ok || json.success === false) {
    throw new PhpApiError(response.status, json.message ?? `PHP API ${path} 调用失败 (HTTP ${response.status})`)
  }

  return json.data as T
}

// ---------- 工具桥接：LLM tool_call → PHP tools/execute ----------

function buildTools(resolved: ResolvePayload, authorization: string) {
  const tools: Record<string, Tool> = {}

  for (const def of resolved.tools ?? []) {
    const fn = def.function
    if (!fn?.name) continue

    tools[fn.name] = tool({
      description: fn.description ?? '',
      parameters: jsonSchema(fn.parameters ?? { type: 'object', properties: {} }),
      execute: async (args: unknown) => {
        try {
          const data = await phpPost<{ result: unknown }>('/ai-streaming/tools/execute', authorization, {
            agent_id: resolved.agent_id,
            tool: fn.name,
            arguments: args ?? {},
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
  if (!authorization) {
    return c.json({ success: false, message: '缺少 Authorization 头' }, 401)
  }

  const body = await c.req.json<{ agent_id?: number; messages?: Array<{ role: 'user' | 'assistant' | 'system'; content: string }> }>().catch(() => null)
  if (!body?.agent_id || !Array.isArray(body.messages) || body.messages.length === 0) {
    return c.json({ success: false, message: '参数错误：agent_id 与 messages 必填' }, 422)
  }

  // 1. 回调 PHP：鉴权 + 配额检查 + Agent 配置解析（失败即拒绝，零 LLM 开销）
  let resolved: ResolvePayload
  try {
    resolved = await phpPost<ResolvePayload>('/ai-streaming/resolve', authorization, { agent_id: body.agent_id })
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

  const result = streamText({
    model: provider.chat(resolved.model),
    system: resolved.system_prompt || undefined,
    messages: body.messages,
    temperature: resolved.temperature,
    maxTokens: resolved.max_tokens,
    tools: buildTools(resolved, authorization),
    maxSteps: Math.max(1, resolved.max_tool_calls) + 1,
    onFinish: async ({ usage, finishReason, steps }) => {
      // 3. 流结束后回调 PHP 结算 token（失败仅告警，不影响已完成的响应）
      try {
        await phpPost('/ai-streaming/usage/report', authorization, {
          agent_id: resolved.agent_id,
          model: resolved.model,
          input_tokens: usage.promptTokens ?? 0,
          output_tokens: usage.completionTokens ?? 0,
          metadata: { finish_reason: finishReason, steps: steps?.length ?? 1 },
        })
      } catch (error) {
        console.error('[ai-streaming] usage report failed:', error)
      }
    },
  })

  return result.toDataStreamResponse()
})

serve({ fetch: app.fetch, hostname: HOST, port: PORT }, (info) => {
  console.log(`[ai-streaming] engine v${VERSION} listening on http://${info.address}:${info.port} → PHP ${PHP_API_BASE}`)
})
