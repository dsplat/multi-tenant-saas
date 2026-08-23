/**
 * AI 页面助手 — Pinia Store
 *
 * 管理助手全局状态：可用性、面板模式、对话历史、流式状态。
 * 遵循「AI 可选性」铁律：所有状态默认关闭/不可用，AI 故障不影响业务。
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type { ChatMessage, PanelMode, ToolCall, FormFillSuggestion, WorkflowSuggestion, HistoryMessage, ActionConfirmData, ActionConfirmStatus, UserChoiceData } from '../ai-assistant/types'

let msgSeq = 0
function nextId(): string {
  return `msg_${Date.now()}_${++msgSeq}`
}

/** 当前登录租户 ID（scrm 登录态约定 key；无则回退无租户 key） */
function readTenantId(): string {
  try { return localStorage.getItem('auth_tenant_id') || '' } catch { return '' }
}

/** 租户维度持久化 key：同一浏览器切换租户不串数据（跨租户历史泄漏修复） */
function tenantScopedKey(base: string): string {
  const tid = readTenantId()
  return tid ? `${base}_${tid}` : base
}

/** 动态获取会话/消息持久化 key（随登录租户变化，勿缓存为常量） */
function storageKeys() {
  return {
    conversation: tenantScopedKey('ai_assistant_conversation'),
    messages: tenantScopedKey('ai_assistant_messages'),
  }
}

/** 本地持久化消息上限（控 localStorage 体积） */
const MESSAGES_PERSIST_LIMIT = 50

function loadPersistedMessages(): ChatMessage[] {
  try {
    const raw = localStorage.getItem(storageKeys().messages)
    if (!raw) return []
    const parsed = JSON.parse(raw)
    if (!Array.isArray(parsed)) return []
    // 只恢复文本内容（卡片/确认态不跨刷新恢复）
    return parsed
      .filter((m: any) => m && typeof m.content === 'string' && (m.role === 'user' || m.role === 'assistant'))
      .map((m: any) => ({ id: String(m.id ?? nextId()), role: m.role, content: m.content, timestamp: Number(m.timestamp) || Date.now() }))
  } catch {
    return []
  }
}

interface PersistedConversation {
  id: number
  agentId: string | null
  agentName: string | null
}

function loadPersistedConversation(): PersistedConversation | null {
  try {
    const raw = localStorage.getItem(storageKeys().conversation)
    if (!raw) return null
    const parsed = JSON.parse(raw)
    if (typeof parsed?.id !== 'number') return null
    return { id: parsed.id, agentId: parsed.agentId ?? null, agentName: parsed.agentName ?? null }
  } catch {
    return null
  }
}

/** 图钉“常驻”偏好：钉住后刷新页面自动展开面板 */
function loadPinnedPreference(): boolean {
  try { return localStorage.getItem('ai_assistant_pinned') === '1' } catch { return false }
}
function savePinnedPreference(on: boolean) {
  try { localStorage.setItem('ai_assistant_pinned', on ? '1' : '0') } catch { /* 静默降级 */ }
}

export const useAssistantStore = defineStore('aiAssistant', () => {
  // ─── 可用性 ───────────────────────────────────────────────
  /** 租户级 + 用户级开关后的最终可用性（默认开启，探测可覆盖） */
  const available = ref(true)
  /** 可用性是否已探测完成 */
  const availabilityLoaded = ref(false)
  /** 用户级偏好：始终启用（浮动按钮即开关，无需额外禁用入口） */
  const userEnabled = ref(true)
  /** 已绑定的租户上下文（setTenantContext 注入；切换时重置本地会话，防止跨租户泄漏） */
  const boundTenantId = ref<string>('')

  // ─── 面板状态 ─────────────────────────────────────────────
  const panelMode = ref<PanelMode>(loadPinnedPreference() ? 'pinned' : 'closed')
  /** 当前模块名（随路由变化） */
  const currentModule = ref('')
  /** 待预填提示词（外部引导卡片唤醒时写入，面板消费后清空） */
  const pendingPrompt = ref<string | null>(null)
  /** 待自动发送提示词（运营引导唤醒时写入，面板打开后直接发送） */
  const autoSendPrompt = ref<string | null>(null)

  // ─── 对话 ─────────────────────────────────────────────────
  const messages = ref<ChatMessage[]>(loadPersistedMessages())
  /** 是否正在流式输出 */
  const streaming = ref(false)
  const persisted = loadPersistedConversation()
  /** 当前会话 ID（后端返回，用于续接；刷新后从 localStorage 恢复） */
  const conversationId = ref<number | null>(persisted?.id ?? null)
  /** 转派目标员工（秘书 delegate 后后续消息定向该员工） */
  const targetAgentId = ref<string | null>(persisted?.agentId ?? null)
  const targetAgentName = ref<string | null>(persisted?.agentName ?? null)
  /** 历史恢复是否已尝试过（避免重复拉取） */
  const hydrated = ref(false)
  /** 已自动转派的消息 ID（会话级去重：组件重挂载/流结束重触发时不再重复转派） */
  const delegatedMsgIds = new Set<string>()

  // ─── 计算属性 ─────────────────────────────────────────────
  /** 最终是否展示助手入口（用户未关闭即显示浮动按钮，可用性仅影响面板内容） */
  const visible = computed(() => userEnabled.value)
  const isOpen = computed(() => panelMode.value !== 'closed')

  // ─── Actions ──────────────────────────────────────────────
  function setAvailability(ok: boolean) {
    available.value = ok
    availabilityLoaded.value = true
  }

  function setUserEnabled(on: boolean) {
    userEnabled.value = on
    localStorage.setItem('ai_assistant_enabled', on ? '1' : '0')
  }

  function setModule(mod: string) {
    currentModule.value = mod
  }

  /** 绑定租户上下文：租户变化时重置内存会话，并从新租户的本地持久化恢复（无则全新开始） */
  function setTenantContext(tenantId: string) {
    const tid = tenantId || readTenantId()
    if (boundTenantId.value === tid) return
    boundTenantId.value = tid
    messages.value = loadPersistedMessages()
    const persisted = loadPersistedConversation()
    conversationId.value = persisted?.id ?? null
    targetAgentId.value = persisted?.agentId ?? null
    targetAgentName.value = persisted?.agentName ?? null
    hydrated.value = false
    delegatedMsgIds.clear()
  }

  function openPanel() {
    // 带图钉偏好打开时直接进入常驻模式
    panelMode.value = loadPinnedPreference() ? 'pinned' : 'panel'
  }

  /** 唤醒面板并预填提示词（消费后自动清空；供 dashboard 引导卡片等外部入口使用） */
  function openWithPrompt(prompt: string) {
    pendingPrompt.value = prompt
    openPanel()
  }

  /** 唤醒面板并自动发送提示词（运营引导卡片：打开即带小秘书开始，无需用户再点发送） */
  function openWithPromptAndSend(prompt: string) {
    autoSendPrompt.value = prompt
    openPanel()
  }

  /** 消费并清空待预填提示词，返回其值（无待预填时返回 null） */
  function consumePendingPrompt(): string | null {
    const p = pendingPrompt.value
    pendingPrompt.value = null
    return p
  }

  /** 消费并清空待自动发送提示词，返回其值（无待发送时返回 null） */
  function consumeAutoSendPrompt(): string | null {
    const p = autoSendPrompt.value
    autoSendPrompt.value = null
    return p
  }

  function closePanel() {
    // 主动关闭即取消常驻（下次刷新不自动展开）
    panelMode.value = 'closed'
    savePinnedPreference(false)
  }

  function togglePin() {
    const next = panelMode.value === 'pinned' ? 'panel' : 'pinned'
    panelMode.value = next
    savePinnedPreference(next === 'pinned')
  }

  /** 消息本地持久化（仅存文本轮次，截最近 N 条） */
  function persistMessages() {
    try {
      const slim = messages.value
        .filter(m => m.content && !m.isError)
        .slice(-MESSAGES_PERSIST_LIMIT)
        .map(m => ({ id: m.id, role: m.role, content: m.content, timestamp: m.timestamp }))
      localStorage.setItem(storageKeys().messages, JSON.stringify(slim))
    } catch { /* 存储不可用时静默降级 */ }
  }

  function pushUserMessage(content: string): ChatMessage {
    const msg: ChatMessage = {
      id: nextId(),
      role: 'user',
      content,
      timestamp: Date.now(),
    }
    messages.value.push(msg)
    persistMessages()
    return msg
  }

  /** 创建一条空的 assistant 消息用于流式填充，返回该消息 */
  function startAssistantMessage(): ChatMessage {
    const msg: ChatMessage = {
      id: nextId(),
      role: 'assistant',
      content: '',
      streaming: true,
      timestamp: Date.now(),
    }
    messages.value.push(msg)
    return msg
  }

  /** 向指定 assistant 消息追加文本 */
  function appendText(msgId: string, text: string) {
    const msg = messages.value.find(m => m.id === msgId)
    if (msg) msg.content += text
  }

  /** 向指定 assistant 消息追加工具调用 */
  function appendToolCalls(msgId: string, calls: ToolCall[]) {
    const msg = messages.value.find(m => m.id === msgId)
    if (msg) msg.toolCalls = [...(msg.toolCalls || []), ...calls]
  }

  /** 工具结果到达：按 toolCallId 回填卡片执行状态，并留存结果（delegate 等结构化指令从中取后端校验过的真实字段） */
  function completeToolCall(msgId: string, toolCallId: string, isError = false, result: any = null) {
    const msg = messages.value.find((m: ChatMessage) => m.id === msgId)
    const call = msg?.toolCalls?.find((c: ToolCall) => c.id === toolCallId)
    if (call) {
      call.status = isError ? 'error' : 'done'
      if (result) call.result = result
    }
  }

  /** 向指定 assistant 消息设置表单填充建议 */
  function setFormFill(msgId: string, suggestion: FormFillSuggestion) {
    const msg = messages.value.find(m => m.id === msgId)
    if (msg) msg.formFill = suggestion
  }

  /** 向指定 assistant 消息设置工作流编排 */
  function setWorkflow(msgId: string, workflow: WorkflowSuggestion) {
    const msg = messages.value.find(m => m.id === msgId)
    if (msg) msg.workflow = workflow
  }

  /** 向指定 assistant 消息设置 L2 待确认操作（确认卡片） */
  function setActionConfirm(msgId: string, data: ActionConfirmData) {
    const msg = messages.value.find(m => m.id === msgId)
    if (msg) {
      msg.actionConfirm = data
      msg.confirmStatus = 'pending'
    }
  }

  /** 更新确认卡片交互状态（可附带反馈文案） */
  function updateActionConfirmStatus(msgId: string, status: ActionConfirmStatus, feedback: string | null = null) {
    const msg = messages.value.find(m => m.id === msgId)
    if (msg) {
      msg.confirmStatus = status
      msg.confirmFeedback = feedback
    }
  }

  /** 向指定 assistant 消息设置选项卡片（ask_user_choice） */
  function setUserChoice(msgId: string, data: UserChoiceData) {
    const msg = messages.value.find(m => m.id === msgId)
    if (msg) msg.userChoice = data
  }

  /** 记录选项卡片已提交的选项（锁定卡片，防重复点选） */
  function setUserChoiceAnswered(msgId: string, answers: string[]) {
    const msg = messages.value.find(m => m.id === msgId)
    if (msg) msg.userChoiceAnswer = answers
  }

  /** 直接追加一条完整的 assistant 消息（确认/取消后 LLM 续答用） */
  function pushAssistantMessage(content: string): ChatMessage {
    const msg: ChatMessage = {
      id: nextId(),
      role: 'assistant',
      content,
      timestamp: Date.now(),
    }
    messages.value.push(msg)
    return msg
  }

  /** 结束指定消息的流式状态 */
  function finishMessage(msgId: string) {
    const msg = messages.value.find(m => m.id === msgId)
    if (msg) {
      msg.streaming = false
      // 兑底：流已结束，未收到结果帧的工具调用视为已完成（避免卡在执行中）
      msg.toolCalls?.forEach((c: ToolCall) => {
        if (c.status === 'running') c.status = 'done'
      })
    }
    persistMessages()
  }

  /** 追加一条错误消息（降级提示，不阻断） */
  function pushError(content: string, action?: { label: string; route: string } | null) {
    messages.value.push({
      id: nextId(),
      role: 'assistant',
      content,
      action: action || null,
      isError: true,
      timestamp: Date.now(),
    })
  }

  function setStreaming(v: boolean) {
    streaming.value = v
  }

  /** 持久化当前会话（刷新不丢） */
  function persistConversation() {
    try {
      if (conversationId.value) {
        localStorage.setItem(storageKeys().conversation, JSON.stringify({
          id: conversationId.value,
          agentId: targetAgentId.value,
          agentName: targetAgentName.value,
        }))
      } else {
        localStorage.removeItem(storageKeys().conversation)
      }
    } catch { /* 存储不可用时静默降级（不影响对话） */ }
  }

  function setConversationId(id: number | null) {
    conversationId.value = id
    persistConversation()
  }

  /** 设置/清除转派目标员工 */
  function setTargetAgent(agentId: string | null, agentName: string | null = null) {
    targetAgentId.value = agentId
    targetAgentName.value = agentName
    // 切换员工后会话重新开始（后端按 agent 隔离会话）
    conversationId.value = null
    persistConversation()
  }

  /** 标记消息已自动转派（防重复） */
  function markDelegated(msgId: string) {
    delegatedMsgIds.add(msgId)
  }

  function isDelegated(msgId: string): boolean {
    return delegatedMsgIds.has(msgId)
  }

  /** 用历史消息恢复面板（刷新后调用；已有对话时不覆盖） */
  function hydrateMessages(list: HistoryMessage[]) {
    hydrated.value = true
    if (messages.value.length > 0 || list.length === 0) return
    messages.value = list.map(m => ({
      id: `hist_${m.message_id}`,
      role: m.role,
      content: m.content,
      // 后端日期序列化为 Y-m-d H:i:s；Safari 不认空格分隔，转 ISO 分隔符再解析
      timestamp: m.created_at ? Date.parse(m.created_at.replace(' ', 'T')) : Date.now(),
    }))
  }

  /** 标记历史恢复已尝试（无可恢复内容时） */
  function markHydrated() {
    hydrated.value = true
  }

  function clearMessages() {
    messages.value = []
    conversationId.value = null
    targetAgentId.value = null
    targetAgentName.value = null
    persistConversation()
    try { localStorage.removeItem(storageKeys().messages) } catch { /* 静默降级 */ }
  }

  /** 切换到历史会话（多会话管理）：清空当前消息并重置 hydrated，由历史恢复流程重新拉取 */
  function switchConversation(id: number) {
    if (conversationId.value === id && messages.value.length > 0) return
    messages.value = []
    targetAgentId.value = null
    targetAgentName.value = null
    conversationId.value = id
    hydrated.value = false
    persistConversation()
  }

  /** 新建会话：清空当前对话与持久化，不再触发历史恢复 */
  function startNewConversation() {
    clearMessages()
    hydrated.value = true
  }

  return {
    // state
    available, availabilityLoaded, userEnabled,
    panelMode, currentModule, pendingPrompt, autoSendPrompt,
    messages, streaming, conversationId,
    targetAgentId, targetAgentName, hydrated,
    // computed
    visible, isOpen,
    // actions
    setAvailability, setUserEnabled, setModule, setTenantContext,
    openPanel, openWithPrompt, openWithPromptAndSend, consumePendingPrompt, consumeAutoSendPrompt, closePanel, togglePin,
    pushUserMessage, startAssistantMessage, appendText, appendToolCalls, completeToolCall, setFormFill, setWorkflow,
    setActionConfirm, updateActionConfirmStatus, setUserChoice, setUserChoiceAnswered, pushAssistantMessage,
    finishMessage, pushError, setStreaming, setConversationId, setTargetAgent, markDelegated, isDelegated, clearMessages,
    switchConversation, startNewConversation,
    hydrateMessages, markHydrated,
  }
})
