/**
 * usePageContext — 自动采集当前页面上下文
 *
 * 职责：把「当前在哪个页面、哪个模块、什么表单/数据状态」标准化为 PageContext，
 * 供 AI 助手理解上下文。采集过程纯异步、不阻塞主线程（AI 可选性铁律）。
 *
 * 采集优先级：
 *  1. 模块页面通过 provide('aiPageContext', reactive({...})) 主动注入（最精确）
 *  2. 从 route.path / route.meta 自动推断 module（兜底）
 */
import { inject, computed, type ComputedRef } from 'vue'
import { useRoute } from 'vue-router'
import type { PageContext } from '../types'

/** 模块页面可注入的上下文扩展（表单状态 / 数据摘要 / 实体信息） */
export interface AiPageContextInjection {
  module?: string
  entity_type?: string | null
  entity_id?: number | null
  form_state?: Record<string, any>
  visible_data_summary?: string
}

/** provide / inject 键名 */
export const AI_PAGE_CONTEXT_KEY = 'aiPageContext'

/**
 * 从路由路径推断模块名。
 * 约定：console 模块路由形如 /customers、/marketing/campaigns、/events/:id …
 * 取第一段路径并转 PascalCase。
 */
function inferModuleFromPath(path: string): string {
  const seg = path.split('/').filter(Boolean)[0] || 'dashboard'
  // 去掉复数 s（customers → customer），保留常见不规则词
  const singular = seg.replace(/ies$/, 'y').replace(/s$/, '')
  return singular.charAt(0).toUpperCase() + singular.slice(1)
}

/**
 * 把路由路径转为点分 route 标识（如 marketing.campaign.create）。
 */
function routeToDotted(path: string): string {
  return path.split('/').filter(Boolean).join('.') || 'dashboard'
}

export function usePageContext(): { pageContext: ComputedRef<PageContext> } {
  const route = useRoute()

  // 模块页面主动注入的上下文（可选）
  const injected = inject<AiPageContextInjection | null>(AI_PAGE_CONTEXT_KEY, null)

  const pageContext = computed<PageContext>(() => {
    const path = route.path
    const module = injected?.module || (route.meta?.module as string) || inferModuleFromPath(path)

    return {
      route: routeToDotted(path),
      module,
      entity_type: injected?.entity_type ?? (route.meta?.entityType as string) ?? null,
      entity_id: injected?.entity_id ?? (route.params?.id ? Number(route.params.id) : null),
      form_state: injected?.form_state ?? undefined,
      visible_data_summary: injected?.visible_data_summary ?? '',
      user_intent: null,
      conversation_id: null,
    }
  })

  return { pageContext }
}
