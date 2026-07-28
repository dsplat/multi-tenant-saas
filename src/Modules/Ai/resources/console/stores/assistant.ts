/**
 * AI 页面助手 — Pinia Store
 *
 * 管理助手全局状态：可用性、面板模式、对话历史、流式状态。
 * 遵循「AI 可选性」铁律：所有状态默认关闭/不可用，AI 故障不影响业务。
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type { ChatMessage, PanelMode, ToolCall, FormFillSuggestion, WorkflowSuggestion, HistoryMessage } from '../ai-assistant/types'

let msgSeq = 0
function nextId(): string {
  return `msg_${Date.now()}_${++msgSeq}`
}

/** 会话持久化 key（刷新不丢：conversation_id + 转派目标一起存） */
const CONVERSATION_STORAGE_KEY = 'ai_assistant_conversation'

interface PersistedConversation {
  id: number
  agentId: string | null
  agentName: string | null
}

function loadPersistedConversation(): PersistedConversation | null {
  try {
    const raw = localStorage.getItem(CONVERSATION_STORAGE_KEY)
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

  // ─── 面板状态 ─────────────────────────────────────────────
  const panelMode = ref<PanelMode>(loadPinnedPreference() ? 'pinned' : 'closed')
  /** 当前模块名（随路由变化） */
  const currentModule = ref('')

  // ─── 对话 ─────────────────────────────────────────────────
  const messages = ref<ChatMessage[]>([])
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

  function openPanel() {
    // 带图钉偏好打开时直接进入常驻模式
    panelMode.value = loadPinnedPreference() ? 'pinned' : 'panel'
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

  function pushUserMessage(content: string): ChatMessage {
    const msg: ChatMessage = {
      id: nextId(),
      role: 'user',
      content,
      timestamp: Date.now(),
    }
    messages.value.push(msg)
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

  /** 结束指定消息的流式状态 */
  function finishMessage(msgId: string) {
    const msg = messages.value.find(m => m.id === msgId)
    if (msg) msg.streaming = false
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
        localStorage.setItem(CONVERSATION_STORAGE_KEY, JSON.stringify({
          id: conversationId.value,
          agentId: targetAgentId.value,
          agentName: targetAgentName.value,
        }))
      } else {
        localStorage.removeItem(CONVERSATION_STORAGE_KEY)
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

  /** 用历史消息恢复面板（刷新后调用；已有对话时不覆盖） */
  function hydrateMessages(list: HistoryMessage[]) {
    hydrated.value = true
    if (messages.value.length > 0 || list.length === 0) return
    messages.value = list.map(m => ({
      id: `hist_${m.message_id}`,
      role: m.role,
      content: m.content,
      timestamp: m.created_at ? Date.parse(m.created_at) : Date.now(),
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
  }

  return {
    // state
    available, availabilityLoaded, userEnabled,
    panelMode, currentModule,
    messages, streaming, conversationId,
    targetAgentId, targetAgentName, hydrated,
    // computed
    visible, isOpen,
    // actions
    setAvailability, setUserEnabled, setModule,
    openPanel, closePanel, togglePin,
    pushUserMessage, startAssistantMessage, appendText, appendToolCalls, setFormFill, setWorkflow,
    finishMessage, pushError, setStreaming, setConversationId, setTargetAgent, clearMessages,
    hydrateMessages, markHydrated,
  }
})
