<script setup lang="ts">
/**
 * ChatMessage — 单条对话消息渲染
 *
 * 可看见铁律：工具调用以人性化文案展示（“搜索…”而非 slug），不黑箱也不暴露系统变量。
 * AI 产出标注：assistant 消息带「AI」徽标，与用户消息视觉区分。
 * assistant 正文按 Markdown 渲染（加粗/列表/链接），站内链接点击路由切换、面板不关闭。
 */
import { computed, onMounted, ref, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useAssistantStore } from '../../stores/assistant'
import type { ChatMessage } from '../types'
import { renderMarkdown } from '../utils/renderMarkdown'
import { toolLabel } from '../utils/toolLabels'
import FormFillCard from './FormFillCard.vue'
import ActionConfirmCard from './ActionConfirmCard.vue'
import ChoiceCard from './ChoiceCard.vue'
import WorkflowProgress from './WorkflowProgress.vue'

const props = defineProps<{ message: ChatMessage }>()
const emit = defineEmits<{
  (e: 'delegate', payload: { agentId: string; agentName: string; handoffMessage: string }): void
  /** 选项卡片点选结果：作为用户消息回传对话 */
  (e: 'choice', text: string): void
}>()
const router = useRouter()
const store = useAssistantStore()

const isUser = computed(() => props.message.role === 'user')

/** assistant 正文 Markdown 渲染（用户消息保持纯文本） */
const renderedContent = computed(() =>
  isUser.value || props.message.isError ? '' : renderMarkdown(props.message.content),
)

/** 正文内已含站内链接时，不再重复渲染独立 navigate 按钮 */
const hasInlineRoute = computed(() => renderedContent.value.includes('data-route'))

/** 站内跳转前校验路由存在：AI 给出的过期/错误路径不跳转，友好提示代替白屏 */
function safePush(path: string) {
  const resolved = router.resolve(path)
  if (resolved.matched.length === 0 || resolved.name === 'NotFound') {
    store.pushError(`抱歉，页面路径似乎已变更，未能找到对应页面。你可以告诉我想去的功能名称，我重新带路。`)
    return
  }
  router.push(path)
}

/** 正文内站内链接：事件委托 → 路由切换（面板不动，页面切换） */
function handleContentClick(e: MouseEvent) {
  const link = (e.target as HTMLElement).closest('a[data-route]') as HTMLElement | null
  if (link?.dataset.route) {
    e.preventDefault()
    safePush(link.dataset.route)
  }
}

/** 工具调用的内部名（仅用于分类，不直接展示） */
function toolName(call: any): string {
  return call?.slug || call?.name || call?.function?.name || ''
}

/** 解析工具参数为对象（兼容 JSON 字符串） */
function parseArgs(call: any): Record<string, any> {
  const args = call?.arguments || call?.function?.arguments
  if (!args) return {}
  if (typeof args === 'string') {
    try { return JSON.parse(args) } catch { return {} }
  }
  return args
}

/** 工具调用人性化展示：运营人员看得懂的动作描述，不暴露 slug/系统参数 */
function toolDisplay(call: any): { icon: string; text: string } {
  const args = parseArgs(call)
  switch (toolName(call)) {
    case 'system_kb_search':
      return { icon: '🔍', text: args.query ? `搜索「${args.query}」` : '搜索知识库' }
    case 'get_data_dictionary':
      return { icon: '📖', text: '查阅数据字典' }
    case 'navigate':
      return { icon: '🧭', text: args.label ? `带你前往「${args.label}」` : '为你导航页面' }
    case 'list_agents':
      return { icon: '👥', text: '查看可用的数字员工' }
    case 'delegate_to_agent':
      return { icon: '⇄', text: args.agent_name ? `转接给「${args.agent_name}」` : '转接专业员工' }
    case 'enable_agent':
      return { icon: '🔓', text: args.agent_name ? `启用「${args.agent_name}」` : '启用数字员工' }
    case 'ask_user_choice':
      return { icon: '🗳️', text: '给出选项供你选择' }
    default:
      // 其余工具走统一词条表（utils/toolLabels），未登记时兜底「执行操作」
      return { icon: '⚙️', text: toolLabel(toolName(call)) }
  }
}

/** 工具卡片状态：running 执行中 / error 失败 / 其余（含无状态的历史消息）视为完成 */
function toolStatus(call: any): 'running' | 'done' | 'error' {
  if (call?.status === 'running' || call?.status === 'error') return call.status
  return 'done'
}

/** 秘书 navigate 带路：从 toolCalls 提取跳转指令 */
const navigateActions = computed(() =>
  (props.message.toolCalls || [])
    .filter(c => toolName(c) === 'navigate')
    .map(c => parseArgs(c))
    .filter(a => typeof a.route_path === 'string' && a.route_path.startsWith('/')),
)

/** 秘书 delegate 转派：以工具结果为准（后端校验过的真实 agent_id/名称），
 * 调用参数只作兜底——模型可能传 role 标识而非长数字 id */
const delegateActions = computed(() =>
  (props.message.toolCalls || [])
    .filter(c => toolName(c) === 'delegate_to_agent')
    .map((c) => {
      const args = parseArgs(c)
      const res = (c as any).result ?? {}
      return {
        agentId: String(res.agent_id ?? args.agent_id ?? ''),
        agentName: String(res.agent_name ?? args.agent_name ?? ''),
        handoffMessage: String(args.handoff_message ?? ''),
        verified: res.action === 'delegate',
      }
    })
    .filter(d => d.agentId && d.verified),
)

function handleNavigate(routePath: string) {
  // 面板不动，页面切换（非阻塞铁律）；同样走路由存在性校验
  safePush(routePath)
}

/** 自动跳转：最新消息含 navigate 时自动执行一次路由跳转，无需用户手动点击 */
const autoNavigated = ref(false)
/** 自动转接：最新消息含 delegate 时自动执行转派，无需用户手动点击按钮 */
const autoDelegated = ref(false)

/** 是否是当前最新 assistant 消息（避免历史消息触发自动动作） */
function isLatestAssistantMessage(): boolean {
  const msgs = store.messages
  const lastAssistant = [...msgs].reverse().find(m => m.role === 'assistant')
  return lastAssistant?.id === props.message.id
}

/** 尝试自动转接（onMounted + watch 共用）：转派是确定性路由，全程无需用户点击 */
function tryAutoDelegate() {
  if (autoDelegated.value || isUser.value) return
  if (store.isDelegated(props.message.id)) {
    autoDelegated.value = true
    return
  }
  if (!isLatestAssistantMessage()) return
  const delegates = delegateActions.value
  if (delegates.length === 0) return
  // 秘书本轮尚未结束（工具返回后模型还要继续播报）：此时发交接消息会被
  // handleSend 的 streaming 守卫拦下，不置位门控，等流结束 watch 重试
  if (store.streaming) return
  autoDelegated.value = true
  store.markDelegated(props.message.id)
  const d = delegates[0]
  emit('delegate', {
    agentId: d.agentId,
    agentName: d.agentName,
    handoffMessage: d.handoffMessage,
  })
}

/** 尝试自动跳转（onMounted + watch 共用） */
function tryAutoNavigate() {
  if (autoNavigated.value || isUser.value) return
  if (!isLatestAssistantMessage()) return
  const navs = navigateActions.value
  if (navs.length === 0) return
  autoNavigated.value = true
  safePush(navs[0].route_path)
}

onMounted(() => {
  if (isUser.value) return
  if (!isLatestAssistantMessage()) return

  // 自动跳转（历史消息恢复时 toolCalls 已存在）
  tryAutoNavigate()

  // 自动转接（历史消息恢复时 toolCalls 已存在）
  tryAutoDelegate()
})

/**
 * 流式场景：组件已挂载但 toolCalls 还没到达，需 watch 变化后立即自动执行
 * 仅触发一次（autoNavigated / autoDelegated 门控）
 */
watch(navigateActions, (newVal) => {
  if (newVal.length > 0) tryAutoNavigate()
})

watch(delegateActions, (newVal) => {
  if (newVal.length > 0) tryAutoDelegate()
})

/** 流结束重试：转派结果先于秘书结束语到达时，等 streaming 置 false 后再自动转接 */
watch(() => store.streaming, (v) => {
  if (!v) tryAutoDelegate()
})

function handleDelegate(a: Record<string, any>) {
  autoDelegated.value = true
  store.markDelegated(props.message.id)
  emit('delegate', {
    agentId: String(a.agentId ?? a.agent_id),
    agentName: String(a.agentName ?? a.agent_name ?? ''),
    handoffMessage: String(a.handoffMessage ?? a.handoff_message ?? ''),
  })
}

/** L2 确认卡片回执：更新该消息确认态；服务端续答非空则追加为新的 assistant 消息 */
function handleConfirmResolved(payload: { status: any; feedback: string | null; assistantMessage: string }) {
  store.updateActionConfirmStatus(props.message.id, payload.status, payload.feedback)
  if (payload.assistantMessage) {
    store.pushAssistantMessage(payload.assistantMessage)
  }
}

/** 选项卡片点选：锁定卡片（防重复点选）后，选择结果作为用户消息发送 */
function handleChoice(answers: string[]) {
  store.setUserChoiceAnswered(props.message.id, answers)
  emit('choice', answers.join('、'))
}
</script>

<template>
  <div class="chat-msg" :class="{ 'is-user': isUser, 'is-error': message.isError }">
    <!-- 头像 -->
    <div class="msg-avatar">
      <template v-if="isUser">我</template>
      <template v-else>AI</template>
    </div>

    <div class="msg-body">
      <!-- 工具调用卡片（可看见，人性化文案 + 执行状态） -->
      <div v-if="message.toolCalls?.length" class="tool-calls">
        <div v-for="(call, i) in message.toolCalls" :key="i" class="tool-card" :class="`status-${toolStatus(call)}`">
          <span class="tool-icon">{{ toolDisplay(call).icon }}</span>
          <span class="tool-name">{{ toolDisplay(call).text }}</span>
          <span v-if="toolStatus(call) === 'running'" class="tool-status running">执行中…</span>
          <span v-else-if="toolStatus(call) === 'error'" class="tool-status error">⚠ 失败</span>
          <span v-else class="tool-status done">✓</span>
        </div>
      </div>

      <!-- 等待首字节：友好思考提示（有内容/工具调用后自动消失） -->
      <div v-if="message.streaming && !message.content && !message.toolCalls?.length" class="thinking-indicator">
        <span class="thinking-dots"><i></i><i></i><i></i></span>
        <span class="thinking-text">正在思考…</span>
      </div>

      <!-- 文本内容：assistant 按 Markdown 渲染（受控标签，XSS 安全），用户/错误消息纯文本 -->
      <div
        v-if="message.content && renderedContent"
        class="msg-text md-body"
        @click="handleContentClick"
        v-html="renderedContent"
      />
      <div v-else-if="message.content" class="msg-text" :class="{ 'error-text': message.isError }">
        {{ message.content }}
      </div>

      <!-- 错误消息附带的操作按钮（如跳转数字员工） -->
      <button
        v-if="message.action"
        class="msg-action-btn"
        @click="router.push(message.action.route)"
      >
        <span class="action-arrow">→</span>
        {{ message.action.label }}
      </button>

      <!-- 秘书带路：navigate 跳转按钮（正文已含内联链接时不重复展示） -->
      <button
        v-for="(nav, i) in hasInlineRoute ? [] : navigateActions"
        :key="'nav' + i"
        class="msg-action-btn"
        @click="handleNavigate(nav.route_path)"
      >
        <span class="action-arrow">→</span>
        {{ nav.label || nav.route_path }}
      </button>

      <!-- 秘书转派：自动执行后展示已转接状态；未自动执行时才保留兜底按钮 -->
      <template v-for="(d, i) in delegateActions" :key="'dlg' + i">
        <span v-if="autoDelegated" class="delegate-done">⇄ 已转接给「{{ d.agentName || '数字员工' }}」</span>
        <button v-else class="msg-action-btn" @click="handleDelegate(d)">
          <span class="action-arrow">⇄</span>
          转接给 {{ d.agentName || '数字员工' }}
        </button>
      </template>

      <!-- 表单填充建议卡片 -->
      <FormFillCard v-if="message.formFill" :suggestion="message.formFill" />

      <!-- L2 低风险写操作确认卡片 -->
      <ActionConfirmCard
        v-if="message.actionConfirm"
        :data="message.actionConfirm"
        :status="message.confirmStatus"
        :feedback="message.confirmFeedback"
        @resolved="handleConfirmResolved"
      />

      <!-- 选项卡片（ask_user_choice：是/否、单选/多选可点选按钮） -->
      <ChoiceCard
        v-if="message.userChoice"
        :data="message.userChoice"
        :answered="message.userChoiceAnswer"
        @choice="handleChoice"
      />

      <!-- 工作流编排进度 -->
      <WorkflowProgress v-if="message.workflow" :workflow="message.workflow" />

      <!-- 流式输出中的光标 -->
      <span v-if="message.streaming" class="typing-cursor" />
    </div>
  </div>
</template>

<style scoped>
.chat-msg {
  display: flex;
  gap: 10px;
  margin-bottom: 16px;
}
.chat-msg.is-user {
  flex-direction: row-reverse;
}

.msg-avatar {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  flex-shrink: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 12px;
  font-weight: 600;
  color: #fff;
  background: linear-gradient(135deg, var(--ac, #10b981), color-mix(in srgb, var(--ac, #10b981) 60%, #0ea5e9));
}
.chat-msg.is-user .msg-avatar {
  background: var(--tx2, #64748b);
}

.msg-body {
  max-width: 78%;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.chat-msg.is-user .msg-body {
  align-items: flex-end;
}

.msg-text {
  padding: 10px 13px;
  border-radius: 12px;
  font-size: 13px;
  line-height: 1.6;
  white-space: pre-wrap;
  word-break: break-word;
  background: var(--fill-color, #f8fafc);
  color: var(--text-color-primary, #0f172a);
  border: 1px solid var(--border-color, #e2e8f0);
}
.chat-msg.is-user .msg-text {
  background: var(--ac, #10b981);
  color: #fff;
  border-color: transparent;
}
.msg-text.error-text {
  background: var(--badge-danger-bg, #fff1f0);
  color: var(--badge-danger-fg, #f5222d);
  border-color: color-mix(in srgb, var(--badge-danger-fg, #f5222d) 30%, transparent);
}

/* Markdown 渲染（v-html 内容需 :deep） */
.md-body { white-space: normal; }
.md-body :deep(.md-p) { margin: 0; }
.md-body :deep(.md-p + .md-p) { margin-top: 4px; }
.md-body :deep(.md-gap) { height: 8px; }
.md-body :deep(.md-heading) {
  font-weight: 700;
  margin: 8px 0 4px;
}
.md-body :deep(.md-heading:first-child) { margin-top: 0; }
.md-body :deep(.md-list) {
  margin: 4px 0;
  padding-left: 20px;
}
.md-body :deep(.md-list li) { margin: 3px 0; }
.md-body :deep(.md-code) {
  padding: 1px 5px;
  border-radius: 4px;
  font-size: 12px;
  font-family: ui-monospace, monospace;
  background: color-mix(in srgb, var(--text-color-secondary, #64748b) 12%, transparent);
}
.md-body :deep(.md-pre) {
  margin: 6px 0;
  padding: 8px 10px;
  border-radius: 8px;
  font-size: 12px;
  font-family: ui-monospace, monospace;
  background: color-mix(in srgb, var(--text-color-secondary, #64748b) 10%, transparent);
  overflow-x: auto;
  white-space: pre;
}
.md-body :deep(.md-link) {
  color: var(--ac, #10b981);
  font-weight: 600;
  text-decoration: underline;
  text-underline-offset: 2px;
  cursor: pointer;
}
.md-body :deep(.md-link:hover) {
  opacity: 0.8;
}

/* 错误消息操作按钮 */
.msg-action-btn {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 8px 16px;
  border-radius: 8px;
  border: none;
  background: var(--ac, #10b981);
  color: #fff;
  font-size: 12px;
  font-weight: 500;
  cursor: pointer;
  transition: transform 0.15s, box-shadow 0.15s;
  align-self: flex-start;
}

/* 已自动转接状态（非交互）：转派是确定性路由，自动执行后不再需要用户点击 */
.delegate-done {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 6px 12px;
  border-radius: 8px;
  background: rgba(16, 185, 129, 0.1);
  color: #0a9e6c;
  font-size: 12px;
  align-self: flex-start;
}
.msg-action-btn:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px color-mix(in srgb, var(--ac, #10b981) 40%, transparent);
}
.action-arrow {
  font-size: 14px;
  transition: transform 0.15s;
}
.msg-action-btn:hover .action-arrow {
  transform: translateX(3px);
}

/* 工具调用卡片 */
.tool-calls {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.tool-card {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 10px;
  border-radius: 8px;
  font-size: 11px;
  background: color-mix(in srgb, var(--ac, #10b981) 8%, transparent);
  border: 1px solid color-mix(in srgb, var(--ac, #10b981) 25%, transparent);
  color: var(--text-color-secondary, #64748b);
  max-width: 100%;
}
.tool-icon { font-size: 12px; }
.tool-name { font-weight: 600; color: var(--text-color-primary, #0f172a); }
.tool-status {
  margin-left: 2px;
  font-size: 10px;
  flex-shrink: 0;
}
.tool-status.done { color: var(--ac, #10b981); font-weight: 700; }
.tool-status.error { color: var(--badge-danger-fg, #f5222d); font-weight: 600; }
.tool-status.running {
  color: var(--text-color-secondary, #64748b);
  animation: status-pulse 1.2s ease-in-out infinite;
}
@keyframes status-pulse {
  0%, 100% { opacity: 0.4; }
  50% { opacity: 1; }
}
.tool-card.status-error {
  background: color-mix(in srgb, var(--badge-danger-fg, #f5222d) 6%, transparent);
  border-color: color-mix(in srgb, var(--badge-danger-fg, #f5222d) 25%, transparent);
}

/* 思考等待提示 */
.thinking-indicator {
  display: inline-flex;
  align-items: center;
  gap: 8px;
  padding: 10px 14px;
  border-radius: 12px;
  background: var(--fill-color, #f8fafc);
  border: 1px solid var(--border-color, #e2e8f0);
}
.thinking-dots {
  display: inline-flex;
  align-items: center;
  gap: 3px;
}
.thinking-dots i {
  width: 5px;
  height: 5px;
  border-radius: 50%;
  background: var(--ac, #10b981);
  animation: dot-bounce 1.2s ease-in-out infinite;
}
.thinking-dots i:nth-child(2) { animation-delay: 0.2s; }
.thinking-dots i:nth-child(3) { animation-delay: 0.4s; }
@keyframes dot-bounce {
  0%, 60%, 100% { opacity: 0.3; transform: translateY(0); }
  30% { opacity: 1; transform: translateY(-3px); }
}
.thinking-text {
  font-size: 12px;
  color: var(--text-color-secondary, #64748b);
}

/* 打字光标 */
.typing-cursor {
  display: inline-block;
  width: 2px;
  height: 14px;
  background: var(--ac, #10b981);
  animation: blink 0.8s infinite;
  vertical-align: middle;
}
@keyframes blink {
  0%, 50% { opacity: 1; }
  51%, 100% { opacity: 0; }
}
</style>
