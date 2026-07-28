<script setup lang="ts">
/**
 * AiAssistant — 全局 AI 助手编排组件
 *
 * 挂载于 ConsoleLayout，负责：
 *  1. 异步探测可用性（不阻塞首屏，fail-open）
 *  2. 监听路由变化更新当前模块
 *  3. 渲染浮动入口 + 侧滑面板
 *
 * 铁律落地：
 *  - 旁挂非串联：本组件是 Layout 的旁挂 sibling，不包裹 router-view，不介入渲染链
 *  - 可完全关闭：store.visible 为 false 时不渲染任何 DOM、不发任何请求
 *  - 非阻塞：可用性探测异步执行，首屏不等待
 */
import { onMounted, watch } from 'vue'
import { useRoute } from 'vue-router'
import { useAssistantStore } from '../stores/assistant'
import { usePageContext } from './composables/usePageContext'
import { useAvailability } from './composables/useAvailability'
import { useAssistantHistory } from './composables/useAssistantHistory'
import FloatingTrigger from './components/FloatingTrigger.vue'
import AssistantPanel from './components/AssistantPanel.vue'

const store = useAssistantStore()
const route = useRoute()
const { pageContext } = usePageContext()
const { check } = useAvailability()
const { restore } = useAssistantHistory()

/** 推断当前模块并探测可用性（防抖：路由稳定后才探测） */
let probeTimer: ReturnType<typeof setTimeout> | null = null
function probeModule() {
  if (probeTimer) clearTimeout(probeTimer)
  probeTimer = setTimeout(() => {
    const mod = pageContext.value.module
    store.setModule(mod)
    check(mod)
  }, 300)
}

onMounted(() => {
  // 用户未关闭时才探测（可完全关闭铁律）
  if (store.userEnabled) {
    probeModule()
    // 刷新后异步恢复历史会话（不阻塞首屏，失败静默降级）
    restore()
  }
})

// 路由变化 → 更新模块 + 重新探测
watch(() => route.path, () => {
  if (store.userEnabled) probeModule()
})
</script>

<template>
  <!-- 可完全关闭：不可见时零 DOM、零请求 -->
  <template v-if="store.visible">
    <FloatingTrigger />

    <el-drawer
      :model-value="store.isOpen"
      :size="store.panelMode === 'pinned' ? '420px' : '420px'"
      direction="rtl"
      :with-header="false"
      :modal="false"
      :lock-scroll="false"
      :close-on-click-modal="false"
      :close-on-press-escape="false"
      :append-to-body="true"
      class="ai-assistant-drawer"
      @update:model-value="(v: boolean) => { if (!v) store.closePanel() }"
    >
      <AssistantPanel />
    </el-drawer>
  </template>
</template>

<style>
/* 抽屉无遮罩，不阻断主内容区操作（非阻塞铁律） */
.ai-assistant-drawer .el-drawer__body {
  padding: 0;
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

/* 最上层覆盖：高于 Element Plus 弹层（PopupManager 从 2000+ 递增）
   同时面板打开时页面仍可操作：全屏 overlay 不拦截事件，仅抽屉本体可交互。
   兼容不同 EP 版本的容器 class（.el-overlay / .el-modal-drawer） */
.el-overlay:has(> .ai-assistant-drawer),
.el-modal-drawer:has(> .ai-assistant-drawer) {
  z-index: 2147483000 !important;
  pointer-events: none !important;
  background: transparent !important;
}
.ai-assistant-drawer {
  z-index: 2147483000 !important;
  pointer-events: auto !important;
}

/* AI 填充字段全局标记（蓝色底纹 + 左侧边框）—— “AI 产出必标注”铁律 */
.ai-filled {
  position: relative;
  background: color-mix(in srgb, var(--ac, #10b981) 6%, transparent) !important;
  border-left: 3px solid var(--ac, #10b981) !important;
  border-radius: 4px;
  transition: background 0.3s, border-color 0.3s;
}
.ai-filled::after {
  content: 'AI';
  position: absolute;
  top: 2px;
  right: 4px;
  font-size: 9px;
  font-weight: 700;
  color: var(--ac, #10b981);
  opacity: 0.7;
}
</style>
