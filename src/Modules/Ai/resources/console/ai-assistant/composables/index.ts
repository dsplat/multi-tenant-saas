/**
 * AI 助手模块 — Composables 统一导出
 *
 * 页面接入示例：
 * ```ts
 * import { useAiFormFill, useAiFormContext } from '@modules/Ai/resources/console/ai-assistant/composables'
 * ```
 */
export { usePageContext, AI_PAGE_CONTEXT_KEY } from './usePageContext'
export type { AiPageContextInjection } from './usePageContext'

export { useAssistantStream } from './useAssistantStream'
export type { StreamCallbacks } from './useAssistantStream'

export { useAvailability } from './useAvailability'

export { useAiFormFill, emitFormFill, hasFormFillListener } from './useAiFormFill'
export { useAiFormContext } from './useAiFormContext'
export type { AiFormContextOptions } from './useAiFormContext'

export { useAiListContext } from './useAiListContext'
export type { AiListContextOptions } from './useAiListContext'
