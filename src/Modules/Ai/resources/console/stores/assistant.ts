/**
 * AI 页面助手 — Pinia Store
 *
 * 管理助手全局状态：可用性、面板模式、对话历史、流式状态。
 * 遵循「AI 可选性」铁律：所有状态默认关闭/不可用，AI 故障不影响业务。
 */
import { defineStore } from 'pinia'
import { ref, computed } from 'vue'
import type { ChatMessage, PanelMode, ToolCall, FormFillSuggestion, WorkflowSuggestion } from '../ai-assistant/types'

let msgSeq = 0
function nextId(): string {
  return `msg_${Date.now()}_${++msgSeq}`
}

export const useAssistantStore = defineStore('aiAssistant', () => {
  // ─── 可用性 ───────────────────────────────────────────────
  /** 租户级 + 用户级开关后的最终可用性 */
  const available = ref(false)
  /** 可用性是否已探测完成 */
  const availabilityLoaded = ref(false)
  /** 用户级偏好（localStorage 持久化）：用户可手动关闭助手 */
  const userEnabled = ref(localStorage.getItem('ai_assistant_enabled') !== '0')

  // ─── 面板状态 ─────────────────────────────────────────────
  const panelMode = ref<PanelMode>('closed')
  /** 当前模块名（随路由变化） */
  const currentModule = ref('')

  // ─── 对话 ─────────────────────────────────────────────────
  const messages = ref<ChatMessage[]>([])
  /** 是否正在流式输出 */
  const streaming = ref(false)
  /** 当前会话 ID（后端返回，用于续接） */
  const conversationId = ref<number | null>(null)

  // ─── 计算属性 ─────────────────────────────────────────────
  /** 最终是否展示助手入口（后端可用 && 用户未关闭） */
  const visible = computed(() => available.value && userEnabled.value)
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
    panelMode.value = 'panel'
  }

  function closePanel() {
    panelMode.value = 'closed'
  }

  function togglePin() {
    panelMode.value = panelMode.value === 'pinned' ? 'panel' : 'pinned'
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
  function pushError(content: string) {
    messages.value.push({
      id: nextId(),
      role: 'assistant',
      content,
      isError: true,
      timestamp: Date.now(),
    })
  }

  function setStreaming(v: boolean) {
    streaming.value = v
  }

  function setConversationId(id: number | null) {
    conversationId.value = id
  }

  function clearMessages() {
    messages.value = []
    conversationId.value = null
  }

  return {
    // state
    available, availabilityLoaded, userEnabled,
    panelMode, currentModule,
    messages, streaming, conversationId,
    // computed
    visible, isOpen,
    // actions
    setAvailability, setUserEnabled, setModule,
    openPanel, closePanel, togglePin,
    pushUserMessage, startAssistantMessage, appendText, appendToolCalls, setFormFill, setWorkflow,
    finishMessage, pushError, setStreaming, setConversationId, clearMessages,
  }
})
