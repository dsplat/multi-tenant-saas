/**
 * useAiFormContext — 表单页接入 AI 上下文采集
 *
 * 使用方式（在任何表单页面）：
 * ```ts
 * const form = reactive({ name: '', budget: 0, start_time: '', end_time: '' })
 *
 * useAiFormContext({
 *   module: 'Marketing',
 *   entity_type: 'campaign',
 *   entity_id: route.params.id ? Number(route.params.id) : null,
 *   formModel: form,
 *   fields: ['name', 'budget', 'start_time', 'end_time', 'target_audience'],
 *   requiredFields: ['name', 'budget', 'start_time'],
 * })
 * ```
 *
 * 自动采集表单状态并 provide 给 AI 助手。
 * AI 助手据此实现"一句话填全表"、字段建议、提交前审查。
 *
 * 铁律落地：
 *  - 旁挂非串联：只读取表单状态，不修改表单逻辑
 *  - 非阻塞：debounce 500ms 采集
 *  - 可控制：AI 填充需用户确认（见 useAiFormFill）
 */
import { provide, watch, ref, type UnwrapNestedRefs } from 'vue'
import { AI_PAGE_CONTEXT_KEY, type AiPageContextInjection } from './usePageContext'

export interface AiFormContextOptions {
  /** 模块名 */
  module: string
  /** 实体类型 */
  entity_type: string
  /** 实体 ID（编辑模式有值，创建模式 null） */
  entity_id?: number | null
  /** 表单响应式对象 */
  formModel: UnwrapNestedRefs<Record<string, any>>
  /** 所有字段名列表 */
  fields?: string[]
  /** 必填字段 */
  requiredFields?: string[]
}

/**
 * 表单页 AI 上下文注册。
 */
export function useAiFormContext(opts: AiFormContextOptions) {
  const formState = ref<Record<string, any>>({})

  function collectState() {
    const state: Record<string, any> = {}
    const keys = opts.fields || Object.keys(opts.formModel)
    for (const key of keys) {
      state[key] = (opts.formModel as any)[key] ?? null
    }
    formState.value = state
  }

  // 初始采集
  collectState()

  // 构建注入对象
  const injection: AiPageContextInjection = {
    module: opts.module,
    entity_type: opts.entity_type,
    entity_id: opts.entity_id ?? null,
    get form_state() {
      return formState.value
    },
  } as any

  provide(AI_PAGE_CONTEXT_KEY, injection)

  // 监听表单变化（debounce 500ms）
  let timer: ReturnType<typeof setTimeout> | null = null
  watch(
    () => opts.formModel,
    () => {
      if (timer) clearTimeout(timer)
      timer = setTimeout(collectState, 500)
    },
    { deep: true }
  )

  return { formState, refresh: collectState }
}
