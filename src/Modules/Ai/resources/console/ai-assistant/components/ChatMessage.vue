<script setup lang="ts">
/**
 * ChatMessage — 单条对话消息渲染
 *
 * 可看见铁律：工具调用以人性化文案展示（“搜索…”而非 slug），不黑箱也不暴露系统变量。
 * AI 产出标注：assistant 消息带「AI」徽标，与用户消息视觉区分。
 * assistant 正文按 Markdown 渲染（加粗/列表/链接），站内链接点击路由切换、面板不关闭。
 */
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAssistantStore } from '../../stores/assistant'
import type { ChatMessage } from '../types'
import { renderMarkdown } from '../utils/renderMarkdown'
import FormFillCard from './FormFillCard.vue'
import WorkflowProgress from './WorkflowProgress.vue'

const props = defineProps<{ message: ChatMessage }>()
const emit = defineEmits<{
  (e: 'delegate', payload: { agentId: string; agentName: string; handoffMessage: string }): void
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
    default:
      return { icon: '⚙️', text: '处理中…' }
  }
}

/** 秘书 navigate 带路：从 toolCalls 提取跳转指令 */
const navigateActions = computed(() =>
  (props.message.toolCalls || [])
    .filter(c => toolName(c) === 'navigate')
    .map(c => parseArgs(c))
    .filter(a => typeof a.route_path === 'string' && a.route_path.startsWith('/')),
)

/** 秘书 delegate 转派：从 toolCalls 提取转派指令 */
const delegateActions = computed(() =>
  (props.message.toolCalls || [])
    .filter(c => toolName(c) === 'delegate_to_agent')
    .map(c => parseArgs(c))
    .filter(a => a.agent_id),
)

function handleNavigate(routePath: string) {
  // 面板不动，页面切换（非阻塞铁律）；同样走路由存在性校验
  safePush(routePath)
}

function handleDelegate(a: Record<string, any>) {
  emit('delegate', {
    agentId: String(a.agent_id),
    agentName: String(a.agent_name || ''),
    handoffMessage: String(a.handoff_message || ''),
  })
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
      <!-- 工具调用卡片（可看见，人性化文案） -->
      <div v-if="message.toolCalls?.length" class="tool-calls">
        <div v-for="(call, i) in message.toolCalls" :key="i" class="tool-card">
          <span class="tool-icon">{{ toolDisplay(call).icon }}</span>
          <span class="tool-name">{{ toolDisplay(call).text }}</span>
        </div>
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

      <!-- 秘书转派：delegate 接手按钮 -->
      <button
        v-for="(d, i) in delegateActions"
        :key="'dlg' + i"
        class="msg-action-btn"
        @click="handleDelegate(d)"
      >
        <span class="action-arrow">⇄</span>
        转接给 {{ d.agent_name || '数字员工' }}
      </button>

      <!-- 表单填充建议卡片 -->
      <FormFillCard v-if="message.formFill" :suggestion="message.formFill" />

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
