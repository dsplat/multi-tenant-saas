/**
 * AI 页面助手 — 类型定义
 *
 * 与后端 SSE 协议（AssistantController）对齐：
 *  - {"type":"text","content":"..."}        增量文本
 *  - {"type":"tool_call","content":[...]}   工具调用决策
 *  - {"type":"done","metadata":{...}}       流结束
 */

/** 前端页面上下文（对应框架 PageContext DTO） */
export interface PageContext {
  /** 前端路由（如 marketing.campaign.create） */
  route: string
  /** 模块名（如 Marketing） */
  module: string
  /** 实体类型（如 campaign） */
  entity_type?: string | null
  /** 实体 ID（编辑时有值） */
  entity_id?: number | null
  /** 当前表单状态 */
  form_state?: Record<string, any>
  /** 页面可见数据摘要 */
  visible_data_summary?: string
  /** 用户自然语言意图 */
  user_intent?: string | null
  /** 续接已有会话 */
  conversation_id?: number | null
}

/** SSE 消息类型 */
export type SseMessageType = 'text' | 'tool_call' | 'form_fill' | 'action_card' | 'workflow' | 'done' | 'error'

/** SSE 单条消息 */
export interface SseMessage {
  type: SseMessageType
  content: any
  metadata?: Record<string, any> | null
}

/** 表单智能填充建议（AI → 前端） */
export interface FormFillSuggestion {
  /** 字段名 → 建议值 */
  fields: Record<string, any>
  /** 整体说明（为什么这样填） */
  explanation?: string
  /** 各字段的填写理由 */
  field_notes?: Record<string, string>
  /** 置信度 0-1（低于 0.6 标黄提示） */
  confidence?: number
}

/** 工具调用结构 */
export interface ToolCall {
  slug?: string
  name?: string
  arguments?: Record<string, any>
  [key: string]: any
}

/** 对话消息（前端渲染用） */
export interface ChatMessage {
  id: string
  role: 'user' | 'assistant'
  /** 文本内容（流式累积） */
  content: string
  /** 工具调用列表 */
  toolCalls?: ToolCall[]
  /** 表单填充建议（type=form_fill 时有值） */
  formFill?: FormFillSuggestion | null
  /** 工作流编排（type=workflow 时有值） */
  workflow?: WorkflowSuggestion | null
  /** 错误消息附带的操作按钮（如跳转数字员工） */
  action?: { label: string; route: string } | null
  /** 是否正在流式输出 */
  streaming?: boolean
  /** 是否为错误消息 */
  isError?: boolean
  /** 时间戳 */
  timestamp: number
}

/** 助手可用性状态 */
export interface AvailabilityState {
  /** 是否已加载 */
  loaded: boolean
  /** 当前模块是否可用 */
  available: boolean
  /** 模块名 */
  module: string
}

/** 面板显示模式 */
export type PanelMode = 'closed' | 'panel' | 'pinned'

/** 工作流步骤状态 */
export type WorkflowStepStatus = 'pending' | 'current' | 'done' | 'warning' | 'error'

/** 工作流步骤 */
export interface WorkflowStep {
  /** 步骤标识 */
  key: string
  /** 步骤名称 */
  label: string
  /** 状态 */
  status: WorkflowStepStatus
  /** AI 生成的草稿数据（可展开查看/编辑） */
  draft?: Record<string, any>
  /** 警告/提示信息 */
  message?: string
}

/** 工作流编排数据（AI → 前端） */
export interface WorkflowSuggestion {
  /** 流程名称（如"创建营销活动"） */
  name: string
  /** 步骤列表 */
  steps: WorkflowStep[]
  /** 最终提交调用的业务 API（写操作必经既定 Service 铁律） */
  submit_endpoint?: string
  submit_payload?: Record<string, any>
  /** 整体说明 */
  explanation?: string
}
