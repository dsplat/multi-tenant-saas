/**
 * useAiListContext — 列表页接入 AI 数据摘要采集
 *
 * 使用方式（在任何列表页面）：
 * ```ts
 * const { filters, pagination, tableData } = useListPage(...)
 *
 * useAiListContext({
 *   module: 'Customer',
 *   entity_type: 'customer',
 *   filters,          // reactive 筛选条件
 *   pagination,       // reactive { page, per_page, total }
 *   columns: ['name', 'phone', 'status', 'created_at'],
 *   getRows: () => tableData.value,   // 当前页数据
 *   getKeyMetrics: () => ({ total: pagination.total, new_today: 12 }),
 * })
 * ```
 *
 * 自动构建 visible_data_summary 并 provide 给 AI 助手。
 * AI 助手据此回答"转化率怎样""哪些客户快流失"等问题。
 *
 * 铁律落地：
 *  - 旁挂非串联：采集纯被动，不修改列表逻辑
 *  - 非阻塞：debounce 500ms，不影响列表渲染性能
 *  - 可完全关闭：AI 助手关闭时不采集
 */
import { provide, watch, ref, type Ref } from 'vue'
import { AI_PAGE_CONTEXT_KEY, type AiPageContextInjection } from './usePageContext'

export interface AiListContextOptions {
  /** 模块名 */
  module: string
  /** 实体类型 */
  entity_type: string
  /** 筛选条件（reactive 对象） */
  filters?: Record<string, any>
  /** 分页信息 */
  pagination?: { page?: number; per_page?: number; total?: number }
  /** 当前列头（用于 AI 理解数据结构） */
  columns?: string[]
  /** 获取当前页行数据 */
  getRows?: () => Record<string, any>[]
  /** 获取关键指标（如总数、今日新增等） */
  getKeyMetrics?: () => Record<string, number | string>
  /** 排序信息 */
  sortBy?: Ref<string> | (() => string)
}

/**
 * 构建 visible_data_summary 文本（AI 可理解的格式）。
 */
function buildSummary(opts: AiListContextOptions): string {
  const parts: string[] = []

  // 模块 + 实体
  parts.push(`[列表] ${opts.module} / ${opts.entity_type}`)

  // 筛选条件
  if (opts.filters) {
    const active = Object.entries(opts.filters).filter(([, v]) => v !== '' && v !== null && v !== undefined)
    if (active.length > 0) {
      parts.push(`[筛选] ${active.map(([k, v]) => `${k}=${v}`).join(', ')}`)
    }
  }

  // 分页
  if (opts.pagination) {
    const { page = 1, per_page = 20, total = 0 } = opts.pagination
    parts.push(`[分页] 第${page}页, 每页${per_page}条, 共${total}条`)
  }

  // 列头
  if (opts.columns?.length) {
    parts.push(`[列头] ${opts.columns.join(', ')}`)
  }

  // 关键指标
  if (opts.getKeyMetrics) {
    try {
      const metrics = opts.getKeyMetrics()
      const entries = Object.entries(metrics)
      if (entries.length > 0) {
        parts.push(`[指标] ${entries.map(([k, v]) => `${k}: ${v}`).join(', ')}`)
      }
    } catch { /* 采集失败静默跳过 */ }
  }

  // 当前页数据摘要（前 5 条）
  if (opts.getRows) {
    try {
      const rows = opts.getRows().slice(0, 5)
      if (rows.length > 0) {
        const summary = rows.map((row, i) => {
          const keys = opts.columns?.slice(0, 4) || Object.keys(row).slice(0, 4)
          return `  ${i + 1}. ${keys.map(k => `${k}=${row[k] ?? '-'}`).join(', ')}`
        })
        parts.push(`[前${rows.length}条数据]\n${summary.join('\n')}`)
      }
    } catch { /* 采集失败静默跳过 */ }
  }

  return parts.join('\n')
}

/**
 * 列表页 AI 上下文注册。
 * 自动 provide 给 AI 助手的 usePageContext inject。
 */
export function useAiListContext(opts: AiListContextOptions) {
  const summary = ref(buildSummary(opts))

  // 构建注入对象
  const injection: AiPageContextInjection = {
    module: opts.module,
    entity_type: opts.entity_type,
    get visible_data_summary() {
      return summary.value
    },
  } as any

  provide(AI_PAGE_CONTEXT_KEY, injection)

  // 监听筛选/分页变化，debounce 更新摘要
  let timer: ReturnType<typeof setTimeout> | null = null
  function refreshSummary() {
    if (timer) clearTimeout(timer)
    timer = setTimeout(() => {
      summary.value = buildSummary(opts)
    }, 500)
  }

  // 深度监听 filters 和 pagination
  if (opts.filters) {
    watch(() => opts.filters, refreshSummary, { deep: true })
  }
  if (opts.pagination) {
    watch(() => [opts.pagination!.page, opts.pagination!.per_page, opts.pagination!.total], refreshSummary)
  }

  return { summary, refreshSummary }
}
