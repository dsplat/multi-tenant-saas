<script setup lang="ts">
/**
 * AssistantPanel — AI 助手侧滑面板
 *
 * 可控制铁律：写操作先草稿后人确认；随时可中断流式输出。
 * 可预期铁律：顶部展示当前 agent 角色 + 能力说明；快捷指令明示可做什么。
 */
import { ref, nextTick, watch, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'
import { useAssistantStore } from '../../stores/assistant'
import { usePageContext } from '../composables/usePageContext'
import { useAssistantStream, EXTRACT_FILE_ENDPOINT } from '../composables/useAssistantStream'
import { useAssistantHistory } from '../composables/useAssistantHistory'
import { useSuggestions } from '../composables/useSuggestions'
import type { AttachmentDraft } from '../types'
import ChatMessage from './ChatMessage.vue'
import HistoryList from './HistoryList.vue'

const store = useAssistantStore()
const router = useRouter()
const { pageContext } = usePageContext()
const { send, abort, streaming } = useAssistantStream()
const { restore } = useAssistantHistory()
const { data: suggestions, fetchSuggestions } = useSuggestions()

const input = ref('')
const chatScroll = ref<HTMLElement | null>(null)
/** 历史会话视图开关（面板内覆盖视图，避免抽屉套抽屉） */
const showHistory = ref(false)

/* ============ 附件上传（文件不落库，后端提取文本随消息发送） ============ */

const attachments = ref<AttachmentDraft[]>([])
const fileInput = ref<HTMLInputElement | null>(null)
const textareaEl = ref<HTMLTextAreaElement | null>(null)
/** 输入框高度：默认 1 行，动态扩到最多 5 行后滚动（行高 19.5px + 上下 padding 18px） */
const INPUT_MIN_HEIGHT = 38
const INPUT_MAX_HEIGHT = INPUT_MIN_HEIGHT * 5 + 18

/** 接受的附件类型：md/文本、pdf、docx（旧版 doc 后端拒收并提示转存）、xlsx、图片 */
const ACCEPT_TYPES = '.md,.markdown,.txt,.csv,.json,.pdf,.doc,.docx,.xls,.xlsx,.ods,image/*'

function pickFiles() {
  fileInput.value?.click()
}

function onFilePicked(e: Event) {
  const el = e.target as HTMLInputElement
  if (el.files) addFiles(Array.from(el.files))
  el.value = ''
}

/** 粘贴上传：剪贴板带文件时拦截默认粘贴行为 */
function onPaste(e: ClipboardEvent) {
  const files = Array.from(e.clipboardData?.files ?? [])
  if (files.length > 0) {
    e.preventDefault()
    addFiles(files)
  }
}

/** 上传并提取文件内容（失败不阻断对话，chip 上标错可移除） */
async function addFiles(files: File[]) {
  for (const file of files) {
    attachments.value.push({
      id: `${Date.now()}-${Math.random().toString(36).slice(2, 8)}`,
      filename: file.name,
      status: 'uploading',
    })
    // 取数组内的响应式 proxy 引用：直接改 push 前的原始对象不会触发重渲染
    const draft = attachments.value[attachments.value.length - 1]

    try {
      const form = new FormData()
      form.append('file', file)
      // 复用 axios 拦截器的认证头（Bearer + X-Tenant-ID）
      const res = await axios.post(EXTRACT_FILE_ENDPOINT, form)
      const data = res.data?.data ?? {}
      draft.status = 'ready'
      draft.content = data.content ?? ''
      draft.format = data.format
      draft.truncated = !!data.truncated
    } catch (err: any) {
      draft.status = 'error'
      draft.error = err?.response?.data?.message || '文件内容提取失败'
    }
  }
}

function removeAttachment(id: string) {
  attachments.value = attachments.value.filter(a => a.id !== id)
}

const hasUploading = computed(() => attachments.value.some(a => a.status === 'uploading'))

/** 输入框自适应：先归零再按 scrollHeight 撑高，超过 5 行上限后由 CSS 出滚动条 */
function adjustHeight() {
  const el = textareaEl.value
  if (!el) return
  el.style.height = 'auto'
  el.style.height = `${Math.max(INPUT_MIN_HEIGHT, Math.min(el.scrollHeight, INPUT_MAX_HEIGHT))}px`
}
watch(input, () => nextTick(adjustHeight))

/** 内置快捷指令（suggestions 接口不可用时的兜底；对话中快捷栏仍用） */
const quickCommands = [
  { label: '分析', icon: '📊', intent: '分析当前页面的数据，给出洞察和改进建议' },
  { label: '填表', icon: '✍️', intent: '根据我的描述智能填写当前表单（请告诉我具体需求）' },
  { label: '帮助', icon: '💡', intent: '告诉我当前页面可以做什么，给出操作指引' },
  { label: '创建', icon: '✨', intent: '帮我创建一个新内容（请告诉我具体需求）' },
]

/**
 * 斜杠命令注册表：输入框键入 "/" 唤起列表，继续输入可过滤，
 * 上下键选择、Enter/Tab 确认。选中后回填 intent 模板供用户补齐参数再发送。
 */
const slashCommands = [
  { command: '分析', icon: '📊', intent: '分析当前页面的数据，给出洞察和改进建议', hint: '分析页面数据给建议' },
  { command: '填表', icon: '✍️', intent: '根据我的描述智能填写当前表单：', hint: '描述需求后智能填表' },
  { command: '帮助', icon: '💡', intent: '告诉我当前页面可以做什么，给出操作指引', hint: '当前页面怎么用' },
  { command: '创建', icon: '✨', intent: '帮我创建一个新内容：', hint: '创建新内容' },
  { command: '初始化站点', icon: '🌐', intent: '帮我从网站初始化团队品牌信息（名称、Logo、Favicon、主题色），我的网站是：', hint: '从网站提取团队信息' },
]

/** "/" 之后的过滤关键词（输入不以 / 开头时为 null，菜单不展示） */
const slashQuery = computed(() => {
  if (!input.value.startsWith('/')) return null
  return input.value.slice(1)
})

const filteredSlashCommands = computed(() => {
  const q = slashQuery.value
  if (q === null) return []
  return slashCommands.filter(c => c.command.includes(q) || c.hint.includes(q))
})

const slashVisible = computed(() => slashQuery.value !== null && filteredSlashCommands.value.length > 0)
const slashActiveIndex = ref(0)
watch(slashQuery, () => { slashActiveIndex.value = 0 })

/** 选中斜杠命令：回填 intent 模板（不直接发送，留用户补齐参数如网址） */
function selectSlashCommand(cmd: typeof slashCommands[number]) {
  input.value = cmd.intent
  nextTick(() => {
    textareaEl.value?.focus()
    adjustHeight()
    // 光标移到末尾便于续写参数
    textareaEl.value?.setSelectionRange(cmd.intent.length, cmd.intent.length)
  })
}

/** 输入框统一键处理：斜杠菜单打开时拦截导航/确认键，否则 Enter 发送（Shift+Enter 换行） */
function onInputKeydown(e: KeyboardEvent) {
  if (slashVisible.value) {
    const list = filteredSlashCommands.value
    if (e.key === 'ArrowDown') {
      e.preventDefault()
      slashActiveIndex.value = (slashActiveIndex.value + 1) % list.length
      return
    }
    if (e.key === 'ArrowUp') {
      e.preventDefault()
      slashActiveIndex.value = (slashActiveIndex.value - 1 + list.length) % list.length
      return
    }
    if (e.key === 'Tab' || (e.key === 'Enter' && !e.shiftKey)) {
      e.preventDefault()
      selectSlashCommand(list[slashActiveIndex.value])
      return
    }
    if (e.key === 'Escape') {
      input.value = ''
      return
    }
  }
  if (e.key === 'Enter' && !e.shiftKey) {
    e.preventDefault()
    handleSend()
  }
}

/** 空状态建议 chips：优先页面感知建议，接口未就绪时回退内置快捷指令 */
const emptyHints = computed(() => {
  const pageSuggestions = suggestions.value?.page_suggestions ?? []
  if (pageSuggestions.length > 0) {
    return pageSuggestions.map(s => ({ label: s, intent: s }))
  }
  return quickCommands.map(c => ({ label: `${c.icon} ${c.label}`, intent: c.intent }))
})

/** 继续聊入口（排除当前会话） */
const historySuggestions = computed(() =>
  (suggestions.value?.history_suggestions ?? []).filter(h => h.conversation_id !== store.conversationId))

/** 设置完善度（仅 tenant_admin 返回；全部完成时不展示引导条） */
const setupChecklist = computed(() => {
  const checklist = suggestions.value?.setup_checklist
  if (!checklist || checklist.completed >= checklist.total) return null
  return checklist
})
const undoneSetupItems = computed(() => setupChecklist.value?.items.filter(i => !i.done) ?? [])

const agentLabel = computed(() => {
  // 统一命名，不跟页面变
  return 'AI小助手'
})

/** 滚动到底部 */
async function scrollToBottom() {
  await nextTick()
  if (chatScroll.value) {
    chatScroll.value.scrollTop = chatScroll.value.scrollHeight
  }
}

// 有新消息时自动滚动
watch(() => store.messages.length, scrollToBottom)
watch(() => store.messages[store.messages.length - 1]?.content, scrollToBottom)

/** 发送消息（text 显式传入时为快捷指令/转派交接，不携带输入区附件） */
async function handleSend(text?: string) {
  const intent = (text ?? input.value).trim()
  const ready = text === undefined
    ? attachments.value.filter(a => a.status === 'ready' && a.content)
    : []
  if ((!intent && ready.length === 0) || streaming.value || hasUploading.value) return

  input.value = ''
  // 仅输入框自发发送时消费附件；快捷指令/转派保留待用附件
  if (text === undefined) attachments.value = []

  // 用户消息回显带上附件名（内容已注入上行 payload，不在列表重复展示）
  const display = ready.length > 0
    ? `${intent}${intent ? '\n' : ''}${ready.map(a => `📎 ${a.filename}`).join('  ')}`
    : intent

  // 多轮记忆：Node 引擎无状态，取此前轮次纯文本历史随请求上行（截最近 20 条控 payload）
  const history = store.messages
    .filter(m => !m.isError && m.content)
    .slice(-20)
    .map(m => ({ role: m.role, content: m.content }))

  store.pushUserMessage(display)

  const assistantMsg = store.startAssistantMessage()
  store.setStreaming(true)
  await scrollToBottom()

  // 转派后定向目标员工；未指定时由服务端兑底系统小助手
  const payload = {
    ...pageContext.value,
    agent_id: store.targetAgentId ?? undefined,
    // 续接已有会话（新会话由服务端创建并经 meta 帧下发）
    conversation_id: store.conversationId ?? undefined,
  }

  await send(payload, intent, {
    onMeta: (meta) => {
      if (meta.conversation_id && meta.conversation_id !== store.conversationId) {
        store.setConversationId(meta.conversation_id)
      }
    },
    onText: (t) => store.appendText(assistantMsg.id, t),
    onToolCall: (calls) => store.appendToolCalls(assistantMsg.id, calls),
    onToolResult: (toolCallId, result) => store.completeToolCall(assistantMsg.id, toolCallId, !!result?.error, result),
    onFormFill: (suggestion) => store.setFormFill(assistantMsg.id, suggestion),
    onWorkflow: (wf) => store.setWorkflow(assistantMsg.id, wf),
    onPendingConfirmation: (confirm) => store.setActionConfirm(assistantMsg.id, confirm),
    onUserChoice: (choice) => store.setUserChoice(assistantMsg.id, choice),
    onDone: () => store.finishMessage(assistantMsg.id),
    onError: (msg, action) => {
      store.finishMessage(assistantMsg.id)
      store.pushError(msg, action)
    },
  }, history, ready)

  store.setStreaming(false)
  await scrollToBottom()
}

/** 快捷指令 */
function handleQuick(intent: string) {
  handleSend(intent)
}

/** 中断输出（可控制） */
function handleAbort() {
  abort()
}

/** 秘书转派：切换目标员工并把交接消息作为开场发送 */
function handleDelegate(payload: { agentId: string; agentName: string; handoffMessage: string }) {
  store.setTargetAgent(payload.agentId, payload.agentName || null)
  const opening = payload.handoffMessage || '你好，请接手处理。'
  handleSend(opening)
}

/** 选项卡片点选：选择结果作为用户消息发送，驱动 AI 续答 */
function handleChoice(text: string) {
  handleSend(text)
}

/** 清空对话 */
function handleClear() {
  store.clearMessages()
}

/** 新建会话：清空当前对话并刷新开场引导（历史建议需含刚结束的会话） */
function handleNewConversation() {
  showHistory.value = false
  store.startNewConversation()
  fetchSuggestions(true)
}

/** 切换到历史会话：重置状态后由历史恢复流程拉取消息 */
async function handleSelectConversation(conversationId: number) {
  showHistory.value = false
  store.switchConversation(conversationId)
  await restore()
  await scrollToBottom()
}

/** 设置引导项跳转（关闭面板避免遮挡目标页） */
function goToSetupItem(route: string | null) {
  if (!route) return
  store.closePanel()
  router.push(route)
}

/** 跳转到数字员工页面 */
function goToAgents() {
  store.closePanel()
  router.push('/agents')
}

/** 外部引导唤醒：消费待预填提示词，填入输入框并聚焦（不自动发送，留用户确认） */
watch(() => store.pendingPrompt, async (prompt) => {
  if (!prompt) return
  const consumed = store.consumePendingPrompt()
  if (!consumed) return
  input.value = consumed
  await nextTick()
  textareaEl.value?.focus()
  adjustHeight()
  // 光标移到末尾便于续写参数
  textareaEl.value?.setSelectionRange(consumed.length, consumed.length)
  await scrollToBottom()
})

/** 外部运营引导唤醒：消费待自动发送提示词，填入输入框并直接发送 */
watch(() => store.autoSendPrompt, async (prompt) => {
  if (!prompt) return
  const consumed = store.consumeAutoSendPrompt()
  if (!consumed) return
  input.value = consumed
  await nextTick()
  adjustHeight()
  await handleSend(consumed)
})

onMounted(() => {
  // 空会话时异步拉开场引导（不阻塞面板渲染，失败回退内置建议）
  if (store.messages.length === 0) fetchSuggestions()
})

</script>

<template>
  <div class="assistant-panel" :class="{ pinned: store.panelMode === 'pinned' }">
    <!-- 头部 -->
    <div class="panel-header">
      <div class="header-info">
        <span class="header-avatar">AI</span>
        <div class="header-text">
          <div class="header-title">{{ agentLabel }}</div>
          <div class="header-sub">可分析数据 · 辅助创建 · 答疑解惑</div>
        </div>
      </div>
      <div class="header-actions">
        <button class="icon-btn" title="新建会话" @click="handleNewConversation">＋</button>
        <button
          class="icon-btn"
          :class="{ active: showHistory }"
          title="历史会话"
          @click="showHistory = !showHistory"
        >🕘</button>
        <button class="icon-btn" title="清空对话" @click="handleClear">🗑</button>
        <button
          class="icon-btn"
          :class="{ active: store.panelMode === 'pinned' }"
          :title="store.panelMode === 'pinned' ? '已常驻：刷新后自动展开（点击取消）' : '常驻：钉住后刷新页面自动展开'"
          @click="store.togglePin()"
        >📌</button>
        <button class="icon-btn" title="关闭" @click="store.closePanel()">✕</button>
      </div>
    </div>

    <!-- 未启用状态：引导用户去数字员工页面开启 -->
    <div v-if="!store.available" class="unavailable-state">
      <div class="unavailable-icon">🔒</div>
      <div class="unavailable-title">当前模块的 AI 助手尚未启用</div>
      <div class="unavailable-desc">
        前往「数字员工」页面开启对应模块的 AI 能力，即可在此获得智能辅助。
      </div>
      <button class="unavailable-link" @click="goToAgents">
        <span class="link-arrow">→</span>
        前往数字员工
      </button>
    </div>

    <!-- 已启用：正常对话区 -->
    <template v-else>

    <!-- 历史会话视图（面板内覆盖） -->
    <HistoryList
      v-if="showHistory"
      @select="handleSelectConversation"
      @close="showHistory = false"
    />

    <template v-else>

    <!-- 对话区 -->
    <div ref="chatScroll" class="chat-scroll">
      <!-- 空状态引导 -->
      <div v-if="store.messages.length === 0" class="empty-state">
        <div class="empty-icon">🤖</div>
        <div class="empty-title">你好，我是AI小助手</div>
        <div class="empty-desc">
          我可以帮你分析当前页面数据、辅助填写表单、解答操作疑问。<br />
          输入 <b>/</b> 可唤起快捷指令；所有 AI 建议仅供参考，关键操作需你确认后执行。
        </div>
        <div class="empty-hints">
          <button
            v-for="hint in emptyHints"
            :key="hint.intent"
            class="hint-chip"
            @click="handleQuick(hint.intent)"
          >
            {{ hint.label }}
          </button>
        </div>

        <!-- 继续上次的对话 -->
        <div v-if="historySuggestions.length > 0" class="suggest-block">
          <div class="suggest-label">继续上次的对话</div>
          <button
            v-for="h in historySuggestions"
            :key="h.conversation_id"
            class="continue-item"
            @click="handleSelectConversation(h.conversation_id)"
          >
            <span class="continue-subject">{{ h.subject || '未命名会话' }}</span>
            <span class="continue-arrow">→</span>
          </button>
        </div>

        <!-- 租户设置完善度引导（仅管理员可见，后端已按角色过滤） -->
        <div v-if="setupChecklist" class="setup-block">
          <div class="suggest-label">
            完善团队设置（{{ setupChecklist.completed }}/{{ setupChecklist.total }}）
          </div>
          <button
            v-for="item in undoneSetupItems"
            :key="item.key"
            class="setup-item"
            :title="item.description"
            @click="goToSetupItem(item.route)"
          >
            <span class="setup-dot">○</span>
            <span class="setup-label">{{ item.label }}</span>
            <span class="continue-arrow">→</span>
          </button>
        </div>
      </div>

      <!-- 消息列表 -->
      <ChatMessage v-for="msg in store.messages" :key="msg.id" :message="msg" @delegate="handleDelegate" @choice="handleChoice" />
    </div>

    <!-- 快捷指令栏 -->
    <div v-if="store.messages.length > 0" class="quick-bar">
      <button
        v-for="cmd in quickCommands"
        :key="cmd.label"
        class="quick-chip"
        :disabled="streaming"
        @click="handleQuick(cmd.intent)"
      >
        {{ cmd.icon }} {{ cmd.label }}
      </button>
    </div>

    <!-- 附件预览条（上传/提取中的状态反馈） -->
    <div v-if="attachments.length > 0" class="attach-bar">
      <span
        v-for="a in attachments"
        :key="a.id"
        class="attach-chip"
        :class="{ uploading: a.status === 'uploading', error: a.status === 'error' }"
        :title="a.status === 'error' ? a.error : (a.truncated ? '内容过长已截断' : a.filename)"
      >
        <span class="attach-name">{{ a.status === 'uploading' ? '⏳' : (a.status === 'error' ? '⚠️' : '📎') }} {{ a.filename }}</span>
        <button class="attach-remove" title="移除" @click="removeAttachment(a.id)">✕</button>
      </span>
    </div>

    <!-- 输入区（斜杠命令菜单悬浮在其上方） -->
    <div class="input-wrap">
      <!-- 斜杠命令菜单 -->
      <div v-if="slashVisible" class="slash-menu">
        <button
          v-for="(cmd, i) in filteredSlashCommands"
          :key="cmd.command"
          class="slash-item"
          :class="{ active: i === slashActiveIndex }"
          @mouseenter="slashActiveIndex = i"
          @click="selectSlashCommand(cmd)"
        >
          <span class="slash-cmd">{{ cmd.icon }} /{{ cmd.command }}</span>
          <span class="slash-hint">{{ cmd.hint }}</span>
        </button>
      </div>

      <div class="input-area">
      <button
        class="attach-btn"
        title="上传附件（md/pdf/docx/xlsx/图片）"
        :disabled="streaming"
        @click="pickFiles"
      >📎</button>
      <input
        ref="fileInput"
        type="file"
        multiple
        class="file-input-hidden"
        :accept="ACCEPT_TYPES"
        @change="onFilePicked"
      />
      <textarea
        ref="textareaEl"
        v-model="input"
        class="chat-input"
        rows="1"
        placeholder="输入你的需求，Enter 发送…（输 / 唤起快捷指令）"
        :disabled="streaming"
        @keydown="onInputKeydown"
        @paste="onPaste"
      />
      <button v-if="streaming" class="send-btn abort" title="中断输出" @click="handleAbort">■</button>
      <button
        v-else
        class="send-btn"
        :disabled="(!input.trim() && attachments.filter(a => a.status === 'ready').length === 0) || hasUploading"
        :title="hasUploading ? '附件提取中…' : '发送'"
        @click="handleSend()"
      >➤</button>
      </div>
    </div>

    <!-- 底部：AI 产出声明 -->
    <div v-if="store.available" class="panel-footer">
      <span class="ai-note">内容由 AI 生成，仅供参考</span>
    </div>
    </template>
    </template>
  </div>
</template>

<style scoped>
.assistant-panel {
  display: flex;
  flex-direction: column;
  height: 100%;
  background: var(--bg-color, #ffffff);
}

/* 头部 */
.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 16px;
  border-bottom: 1px solid var(--border-color, #e2e8f0);
  flex-shrink: 0;
}
.header-info { display: flex; align-items: center; gap: 10px; }
.header-avatar {
  width: 34px; height: 34px; border-radius: 10px;
  display: flex; align-items: center; justify-content: center;
  font-size: 13px; font-weight: 700; color: #fff;
  background: linear-gradient(135deg, var(--ac, #10b981), color-mix(in srgb, var(--ac, #10b981) 60%, #0ea5e9));
}
.header-title { font-size: 14px; font-weight: 600; color: var(--text-color-primary, #0f172a); }
.header-sub { font-size: 11px; color: var(--text-color-secondary, #64748b); margin-top: 1px; }
.header-actions { display: flex; gap: 4px; }
.icon-btn {
  width: 28px; height: 28px; border: none; border-radius: 6px;
  background: transparent; cursor: pointer; font-size: 13px;
  display: flex; align-items: center; justify-content: center;
  transition: background 0.15s;
}
.icon-btn:hover { background: var(--fill-color, #f1f5f9); }
.icon-btn.active {
  background: color-mix(in srgb, var(--ac, #10b981) 15%, transparent);
  box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--ac, #10b981) 40%, transparent);
}

/* 对话区 */
.chat-scroll {
  flex: 1;
  overflow-y: auto;
  padding: 16px;
}

/* 空状态 */
.empty-state { text-align: center; padding: 32px 12px; }
.empty-icon { font-size: 40px; margin-bottom: 12px; }
.empty-title { font-size: 15px; font-weight: 600; color: var(--text-color-primary, #0f172a); margin-bottom: 8px; }
.empty-desc { font-size: 12px; line-height: 1.7; color: var(--text-color-secondary, #64748b); margin-bottom: 18px; }
.empty-hints { display: flex; justify-content: center; gap: 8px; flex-wrap: wrap; }
.hint-chip {
  padding: 7px 14px; border-radius: 20px; font-size: 12px;
  border: 1px solid color-mix(in srgb, var(--ac, #10b981) 35%, transparent);
  background: color-mix(in srgb, var(--ac, #10b981) 8%, transparent);
  color: var(--text-color-primary, #0f172a);
  cursor: pointer; transition: all 0.15s;
}
.hint-chip:hover {
  background: color-mix(in srgb, var(--ac, #10b981) 18%, transparent);
  transform: translateY(-1px);
}

/* 开场引导块（继续聊 / 设置完善度） */
.suggest-block, .setup-block {
  margin-top: 20px;
  text-align: left;
}
.suggest-label {
  font-size: 11px; font-weight: 600;
  color: var(--text-color-secondary, #64748b);
  margin-bottom: 6px; padding-left: 2px;
}
.continue-item, .setup-item {
  display: flex; align-items: center; gap: 6px;
  width: 100%; padding: 8px 10px; margin-bottom: 4px;
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 10px; background: var(--fill-color, #f8fafc);
  font-size: 12px; color: var(--text-color-primary, #0f172a);
  cursor: pointer; transition: all 0.15s; text-align: left;
}
.continue-item:hover, .setup-item:hover {
  border-color: var(--ac, #10b981);
  background: color-mix(in srgb, var(--ac, #10b981) 6%, transparent);
}
.continue-subject, .setup-label {
  flex: 1; min-width: 0;
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.continue-arrow { font-size: 12px; color: var(--ac, #10b981); flex-shrink: 0; }
.setup-dot { color: var(--text-color-secondary, #64748b); flex-shrink: 0; }

/* 快捷指令 */
.quick-bar {
  display: flex; gap: 6px; padding: 8px 16px 0;
  flex-shrink: 0;
}
.quick-chip {
  padding: 4px 11px; border-radius: 14px; font-size: 11px;
  border: 1px solid var(--border-color, #e2e8f0);
  background: var(--fill-color, #f8fafc);
  color: var(--text-color-secondary, #64748b);
  cursor: pointer; transition: all 0.15s;
}
.quick-chip:hover:not(:disabled) { border-color: var(--ac, #10b981); color: var(--ac, #10b981); }
.quick-chip:disabled { opacity: 0.5; cursor: not-allowed; }

/* 附件预览条 */
.attach-bar {
  display: flex; flex-wrap: wrap; gap: 6px;
  padding: 8px 16px 0;
  flex-shrink: 0;
}
.attach-chip {
  display: inline-flex; align-items: center; gap: 4px;
  max-width: 220px; padding: 4px 8px; border-radius: 8px;
  font-size: 11px; border: 1px solid var(--border-color, #e2e8f0);
  background: var(--fill-color, #f8fafc);
  color: var(--text-color-primary, #0f172a);
}
.attach-chip.uploading { opacity: 0.7; }
.attach-chip.error {
  border-color: color-mix(in srgb, var(--badge-danger-fg, #f5222d) 50%, transparent);
  color: var(--badge-danger-fg, #f5222d);
}
.attach-name {
  min-width: 0; white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}
.attach-remove {
  border: none; background: transparent; cursor: pointer;
  font-size: 10px; color: var(--text-color-secondary, #64748b);
  padding: 0 2px; flex-shrink: 0;
}
.attach-remove:hover { color: var(--badge-danger-fg, #f5222d); }

/* 输入区（含斜杠命令菜单） */
.input-wrap {
  position: relative;
  border-top: 1px solid var(--border-color, #e2e8f0);
  flex-shrink: 0;
}

/* 斜杠命令菜单 */
.slash-menu {
  position: absolute;
  bottom: calc(100% + 6px);
  left: 16px; right: 16px;
  max-height: 240px;
  overflow-y: auto;
  background: var(--bg-color, #ffffff);
  border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 12px;
  box-shadow: 0 8px 24px rgba(15, 23, 42, 0.12);
  padding: 4px;
  z-index: 10;
}
.slash-item {
  display: flex; align-items: center; justify-content: space-between; gap: 8px;
  width: 100%; padding: 8px 10px;
  border: none; border-radius: 8px; background: transparent;
  font-size: 12px; cursor: pointer; text-align: left;
  color: var(--text-color-primary, #0f172a);
  transition: background 0.1s;
}
.slash-item.active,
.slash-item:hover {
  background: color-mix(in srgb, var(--ac, #10b981) 12%, transparent);
}
.slash-cmd { font-weight: 600; white-space: nowrap; }
.slash-hint {
  font-size: 11px;
  color: var(--text-color-secondary, #64748b);
  white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
}

.input-area {
  display: flex; align-items: flex-end; gap: 8px;
  padding: 12px 16px;
  flex-shrink: 0;
}
.attach-btn {
  width: 36px; height: 36px; border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 10px; background: var(--fill-color, #f8fafc);
  font-size: 14px; cursor: pointer; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  transition: border-color 0.15s, background 0.15s;
}
.attach-btn:hover:not(:disabled) {
  border-color: var(--ac, #10b981);
  background: color-mix(in srgb, var(--ac, #10b981) 8%, transparent);
}
.attach-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.file-input-hidden { display: none; }
.chat-input {
  flex: 1; resize: none; border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 10px; padding: 9px 12px; font-size: 13px;
  background: var(--fill-color, #f8fafc);
  color: var(--text-color-primary, #0f172a);
  outline: none; font-family: inherit; line-height: 1.5;
  min-height: 38px;
  max-height: 208px; /* 5 行（行高 19.5px）+ 上下 padding，超出出滚动条 */
  overflow-y: auto;
  transition: border-color 0.15s;
}
.chat-input:focus { border-color: var(--ac, #10b981); }
.chat-input:disabled { opacity: 0.6; }
.send-btn {
  width: 36px; height: 36px; border: none; border-radius: 10px;
  background: var(--ac, #10b981); color: #fff; font-size: 14px;
  cursor: pointer; flex-shrink: 0;
  display: flex; align-items: center; justify-content: center;
  transition: opacity 0.15s, transform 0.1s;
}
.send-btn:hover:not(:disabled) { transform: scale(1.05); }
.send-btn:disabled { opacity: 0.4; cursor: not-allowed; }
.send-btn.abort { background: var(--badge-danger-fg, #f5222d); }

/* 底部 */
.panel-footer {
  display: flex; align-items: center; justify-content: space-between;
  padding: 8px 16px 10px;
  flex-shrink: 0;
}
.ai-note { font-size: 10px; color: var(--text-color-secondary, #64748b); opacity: 0.7; }

/* 未启用状态 */
.unavailable-state {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 40px 24px;
  text-align: center;
}
.unavailable-icon {
  font-size: 36px;
  margin-bottom: 16px;
  opacity: 0.7;
}
.unavailable-title {
  font-size: 15px;
  font-weight: 600;
  color: var(--text-color-primary, #0f172a);
  margin-bottom: 10px;
}
.unavailable-desc {
  font-size: 13px;
  line-height: 1.7;
  color: var(--text-color-secondary, #64748b);
  margin-bottom: 24px;
  max-width: 280px;
}
.unavailable-link {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 10px 22px;
  border-radius: 8px;
  border: none;
  background: var(--ac, #10b981);
  color: #fff;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  transition: transform 0.15s, box-shadow 0.15s;
}
.unavailable-link:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 14px color-mix(in srgb, var(--ac, #10b981) 40%, transparent);
}
.link-arrow {
  font-size: 15px;
  transition: transform 0.15s;
}
.unavailable-link:hover .link-arrow {
  transform: translateX(3px);
}
</style>
