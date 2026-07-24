<script setup lang="ts">
/**
 * AssistantPanel — AI 助手侧滑面板
 *
 * 可控制铁律：写操作先草稿后人确认；随时可中断流式输出。
 * 可预期铁律：顶部展示当前 agent 角色 + 能力说明；快捷指令明示可做什么。
 */
import { ref, nextTick, watch, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAssistantStore } from '../../stores/assistant'
import { usePageContext } from '../composables/usePageContext'
import { useAssistantStream } from '../composables/useAssistantStream'
import ChatMessage from './ChatMessage.vue'

const store = useAssistantStore()
const router = useRouter()
const { pageContext } = usePageContext()
const { send, abort, streaming } = useAssistantStream()

const input = ref('')
const chatScroll = ref<HTMLElement | null>(null)

/** 快捷指令（可预期：明示 AI 能做什么） */
const quickCommands = [
  { label: '分析', icon: '📊', intent: '分析当前页面的数据，给出洞察和改进建议' },
  { label: '填表', icon: '✍️', intent: '根据我的描述智能填写当前表单（请告诉我具体需求）' },
  { label: '帮助', icon: '💡', intent: '告诉我当前页面可以做什么，给出操作指引' },
  { label: '创建', icon: '✨', intent: '帮我创建一个新内容（请告诉我具体需求）' },
]

const agentLabel = computed(() => {
  const mod = store.currentModule || pageContext.value.module
  return mod ? `${mod} 智能助手` : '智能助手'
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

/** 发送消息 */
async function handleSend(text?: string) {
  const intent = (text ?? input.value).trim()
  if (!intent || streaming.value) return

  input.value = ''
  store.pushUserMessage(intent)

  const assistantMsg = store.startAssistantMessage()
  store.setStreaming(true)
  await scrollToBottom()

  await send(pageContext.value, intent, {
    onText: (t) => store.appendText(assistantMsg.id, t),
    onToolCall: (calls) => store.appendToolCalls(assistantMsg.id, calls),
    onFormFill: (suggestion) => store.setFormFill(assistantMsg.id, suggestion),
    onWorkflow: (wf) => store.setWorkflow(assistantMsg.id, wf),
    onDone: () => store.finishMessage(assistantMsg.id),
    onError: (msg, action) => {
      store.finishMessage(assistantMsg.id)
      store.pushError(msg, action)
    },
  })

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

/** 清空对话 */
function handleClear() {
  store.clearMessages()
}

/** 跳转到数字员工页面 */
function goToAgents() {
  store.closePanel()
  router.push('/agents')
}


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
        <button class="icon-btn" title="清空对话" @click="handleClear">🗑</button>
        <button class="icon-btn" title="固定/取消固定" @click="store.togglePin()">📌</button>
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

    <!-- 对话区 -->
    <div ref="chatScroll" class="chat-scroll">
      <!-- 空状态引导 -->
      <div v-if="store.messages.length === 0" class="empty-state">
        <div class="empty-icon">🤖</div>
        <div class="empty-title">你好，我是页面智能助手</div>
        <div class="empty-desc">
          我可以帮你分析当前页面数据、辅助填写表单、解答操作疑问。<br />
          所有 AI 建议仅供参考，关键操作需你确认后执行。
        </div>
        <div class="empty-hints">
          <button
            v-for="cmd in quickCommands"
            :key="cmd.label"
            class="hint-chip"
            @click="handleQuick(cmd.intent)"
          >
            {{ cmd.icon }} {{ cmd.label }}
          </button>
        </div>
      </div>

      <!-- 消息列表 -->
      <ChatMessage v-for="msg in store.messages" :key="msg.id" :message="msg" />
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

    <!-- 输入区 -->
    <div class="input-area">
      <textarea
        v-model="input"
        class="chat-input"
        rows="1"
        placeholder="输入你的需求，Enter 发送…"
        :disabled="streaming"
        @keydown.enter.exact.prevent="handleSend()"
      />
      <button v-if="streaming" class="send-btn abort" title="中断输出" @click="handleAbort">■</button>
      <button v-else class="send-btn" :disabled="!input.trim()" title="发送" @click="handleSend()">➤</button>
    </div>

    <!-- 底部：AI 产出声明 -->
    <div v-if="store.available" class="panel-footer">
      <span class="ai-note">内容由 AI 生成，仅供参考</span>
    </div>
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

/* 输入区 */
.input-area {
  display: flex; align-items: flex-end; gap: 8px;
  padding: 12px 16px;
  border-top: 1px solid var(--border-color, #e2e8f0);
  flex-shrink: 0;
}
.chat-input {
  flex: 1; resize: none; border: 1px solid var(--border-color, #e2e8f0);
  border-radius: 10px; padding: 9px 12px; font-size: 13px;
  background: var(--fill-color, #f8fafc);
  color: var(--text-color-primary, #0f172a);
  outline: none; font-family: inherit; line-height: 1.5;
  max-height: 100px;
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
