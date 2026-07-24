<script setup lang="ts">
/**
 * ChatMessage — 单条对话消息渲染
 *
 * 可看见铁律：工具调用以卡片形式展示（工具名 + 参数），不黑箱。
 * AI 产出标注：assistant 消息带「AI」徽标，与用户消息视觉区分。
 */
import { computed } from 'vue'
import type { ChatMessage } from '../types'
import FormFillCard from './FormFillCard.vue'
import WorkflowProgress from './WorkflowProgress.vue'

const props = defineProps<{ message: ChatMessage }>()

const isUser = computed(() => props.message.role === 'user')

/** 工具调用的展示名 */
function toolName(call: any): string {
  return call?.slug || call?.name || call?.function?.name || '工具'
}

/** 工具参数摘要（截断展示） */
function toolArgs(call: any): string {
  const args = call?.arguments || call?.function?.arguments
  if (!args) return ''
  const s = typeof args === 'string' ? args : JSON.stringify(args)
  return s.length > 80 ? s.slice(0, 80) + '…' : s
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
      <!-- 工具调用卡片（可看见） -->
      <div v-if="message.toolCalls?.length" class="tool-calls">
        <div v-for="(call, i) in message.toolCalls" :key="i" class="tool-card">
          <span class="tool-icon">⚙</span>
          <span class="tool-name">调用 {{ toolName(call) }}</span>
          <span v-if="toolArgs(call)" class="tool-args">{{ toolArgs(call) }}</span>
        </div>
      </div>

      <!-- 文本内容 -->
      <div v-if="message.content" class="msg-text" :class="{ 'error-text': message.isError }">
        {{ message.content }}
      </div>

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
.tool-name { font-weight: 600; color: var(--text-color-primary, #0f172a); white-space: nowrap; }
.tool-args {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  opacity: 0.7;
  font-family: ui-monospace, monospace;
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
