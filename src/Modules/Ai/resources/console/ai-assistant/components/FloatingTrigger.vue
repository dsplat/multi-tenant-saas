<script setup lang="ts">
/**
 * FloatingTrigger — 右下角浮动 AI 入口
 *
 * 旁挂铁律：纯浮动 DOM，不介入任何业务流程。
 * 呼吸光效表示 AI 就绪；流式输出中显示动态环。
 */
import { useAssistantStore } from '../../stores/assistant'

const store = useAssistantStore()

function handleClick() {
  if (store.isOpen) {
    store.closePanel()
  } else {
    store.openPanel()
  }
}
</script>

<template>
  <button
    class="ai-fab"
    :class="{ active: store.isOpen, streaming: store.streaming }"
    :title="store.isOpen ? '收起 AI 助手' : '打开 AI 助手'"
    @click="handleClick"
  >
    <span class="fab-icon">{{ store.isOpen ? '✕' : '✦' }}</span>
    <span v-if="!store.isOpen" class="fab-pulse" />
  </button>
</template>

<style scoped>
.ai-fab {
  position: fixed;
  right: 24px;
  bottom: 24px;
  width: 54px;
  height: 54px;
  border-radius: 50%;
  border: none;
  cursor: pointer;
  z-index: 2000;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, var(--ac, #10b981), color-mix(in srgb, var(--ac, #10b981) 55%, #0ea5e9));
  color: #fff;
  box-shadow: 0 6px 20px color-mix(in srgb, var(--ac, #10b981) 40%, transparent);
  transition: transform 0.2s, box-shadow 0.2s;
}
.ai-fab:hover { transform: scale(1.08); box-shadow: 0 8px 26px color-mix(in srgb, var(--ac, #10b981) 55%, transparent); }
.ai-fab.active { background: var(--tx2, #64748b); box-shadow: 0 4px 14px rgba(0,0,0,0.2); }

.fab-icon {
  font-size: 22px;
  line-height: 1;
  position: relative;
  z-index: 2;
  transition: transform 0.2s;
}
.ai-fab.active .fab-icon { transform: rotate(90deg); }

/* 呼吸光效（AI 就绪） */
.fab-pulse {
  position: absolute;
  inset: 0;
  border-radius: 50%;
  background: inherit;
  animation: pulse 2.4s ease-out infinite;
  z-index: 1;
}
@keyframes pulse {
  0% { transform: scale(1); opacity: 0.5; }
  70% { transform: scale(1.55); opacity: 0; }
  100% { transform: scale(1.55); opacity: 0; }
}

/* 流式输出中的旋转环 */
.ai-fab.streaming::after {
  content: '';
  position: absolute;
  inset: -5px;
  border-radius: 50%;
  border: 2px solid transparent;
  border-top-color: var(--ac, #10b981);
  animation: spin 0.9s linear infinite;
}
@keyframes spin {
  to { transform: rotate(360deg); }
}
</style>
