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
  /** 显式指定目标员工（秘书转派后续接） */
  agent_id?: number | string | null
}

/** SSE 消息类型 */
export type SseMessageType = 'meta' | 'text' | 'tool_call' | 'form_fill' | 'action_card' | 'workflow' | 'pending_confirmation' | 'done' | 'error'

/** SSE 单条消息 */
export interface SseMessage {
  type: SseMessageType
  content: any
  metadata?: Record<string, any> | null
}

/** 会话元信息（SSE 首帧下发，前端持久化用于刷新续接） */
export interface ConversationMeta {
  conversation_id: number
  agent_id?: number | null
}

/** 历史消息（GET /ai/assistant/history 返回） */
export interface HistoryMessage {
  message_id: number
  role: 'user' | 'assistant'
  content: string
  created_at?: string | null
}

/** 会话摘要（GET /ai/assistant/conversations 返回） */
export interface ConversationSummary {
  conversation_id: number
  agent_id: number
  subject: string | null
  status: string
  updated_at?: string | null
}

/** 历史会话建议（继续聊入口） */
export interface HistorySuggestion {
  conversation_id: number
  subject: string | null
  updated_at?: string | null
}

/** 租户设置完善度检查项（仅 tenant_admin 返回） */
export interface SetupChecklistItem {
  key: string
  label: string
  done: boolean
  route: string | null
  description: string
}

/** 设置完善度清单 */
export interface SetupChecklist {
  items: SetupChecklistItem[]
  completed: number
  total: number
}

/** 开场引导数据（GET /ai/assistant/suggestions 返回） */
export interface SuggestionData {
  page_suggestions: string[]
  history_suggestions: HistorySuggestion[]
  /** 预设任务链（引擎就位前固定空数组，见 docs/task-chain.md） */
  task_chains: unknown[]
  setup_checklist: SetupChecklist | null
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

/** L2 操作确认卡片数据（SSE pending_confirmation → 前端） */
export interface ActionConfirmData {
  /** 一次性确认令牌 */
  token: string
  /** 参数哈希（确认时回传校验） */
  args_hash: string
  /** 令牌有效期（秒） */
  expires_in: number
  /** 工具标识 */
  tool_slug: string
  /** 工具展示名 */
  tool_name: string
  /** 待执行参数（供用户核对，执行时以服务端存储为准） */
  arguments: Record<string, any>
  /** 归属会话 */
  conversation_id: number
}

/** 确认卡片交互状态 */
export type ActionConfirmStatus = 'pending' | 'confirming' | 'executed' | 'cancelled' | 'expired' | 'error'

/** 选项卡片数据（ask_user_choice 工具结果 → 前端） */
export interface UserChoiceData {
  /** 向用户提出的问题 */
  question: string
  /** 可点选的选项文案列表 */
  options: string[]
  /** 是否允许多选（false=单选，点击即提交） */
  multiple: boolean
}

/** 工具调用执行状态（Node 链路：9: 帧置 running，a: 帧置 done/error） */
export type ToolCallStatus = 'running' | 'done' | 'error'

/** 工具调用结构 */
export interface ToolCall {
  /** 调用 ID（Node 链路 toolCallId，用于结果帧回填状态） */
  id?: string
  slug?: string
  name?: string
  arguments?: Record<string, any>
  /** 执行状态（缺省视为已完成，兼容 PHP 链路历史消息） */
  status?: ToolCallStatus
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
  /** L2 待确认操作（type=pending_confirmation 时有值） */
  actionConfirm?: ActionConfirmData | null
  /** 确认卡片交互状态 */
  confirmStatus?: ActionConfirmStatus
  /** 确认/取消后的反馈文案 */
  confirmFeedback?: string | null
  /** 选项卡片数据（ask_user_choice 工具结果时有值） */
  userChoice?: UserChoiceData | null
  /** 已提交的选项（选择后锁定卡片，防重复点选） */
  userChoiceAnswer?: string[] | null
  /** 错误消息附带的操作按钮（如跳转数字员工） */
  action?: { label: string; route: string } | null
  /** 是否正在流式输出 */
  streaming?: boolean
  /** 是否为错误消息 */
  isError?: boolean
  /** 时间戳 */
  timestamp: number
}

/** 附件草稿（输入区上传/粘贴后本地暂存；文件不落库，后端提取文本随消息发送） */
export interface AttachmentDraft {
  /** 本地唯一 id */
  id: string
  /** 原始文件名 */
  filename: string
  /** 提取状态 */
  status: 'uploading' | 'ready' | 'error'
  /** 提取出的文本内容（status=ready 时有值） */
  content?: string
  /** 后端返回的格式标识（text/document/spreadsheet/pdf/image） */
  format?: string
  /** 是否被截断 */
  truncated?: boolean
  /** 失败原因（status=error 时有值） */
  error?: string
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
