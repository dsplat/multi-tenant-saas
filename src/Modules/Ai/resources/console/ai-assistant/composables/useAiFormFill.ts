/**
 * useAiFormFill — 页面表单接入 AI 智能填充
 *
 * 使用方式（在任何含 el-form 的页面）：
 * ```ts
 * const form = reactive({ name: '', budget: 0, ... })
 * const { aiFilledFields, applyFormFill, resetAiFill } = useAiFormFill(form)
 * ```
 *
 * 模板中根据 aiFilledFields 高亮 AI 填充的字段：
 * ```html
 * <el-form-item :class="{ 'ai-filled': aiFilledFields.has('name') }">
 * ```
 *
 * 铁律落地：
 *  - 旁挂非串联：不拦截表单 submit，不修改校验逻辑
 *  - 可控制：用户可逐字段撤销 AI 填充（resetAiFill）
 *  - AI 产出必标注：aiFilledFields 驱动蓝色底纹标记
 *  - 可回退：resetAiFill() 恢复原始值
 */
import { ref, reactive, watch, onUnmounted, type UnwrapNestedRefs } from 'vue'
import type { FormFillSuggestion } from '../types'

/** 全局事件总线（AI 助手 → 页面表单） */
type FormFillHandler = (suggestion: FormFillSuggestion) => void
const formFillListeners = new Set<FormFillHandler>()

/**
 * AI 助手侧调用：向当前页面表单广播填充建议。
 * 由 AssistantPanel / FormFillCard 在用户确认后调用。
 */
export function emitFormFill(suggestion: FormFillSuggestion) {
  formFillListeners.forEach(fn => fn(suggestion))
}

/** 当前是否有页面表单在监听 */
export function hasFormFillListener(): boolean {
  return formFillListeners.size > 0
}

/**
 * 页面表单侧 composable：注册监听、管理 AI 填充状态。
 *
 * @param formModel - 表单响应式对象（reactive({...})）
 * @returns
 *  - aiFilledFields: Set<string> 被 AI 填充过的字段名集合
 *  - applyFormFill: 手动应用一组建议（通常由事件自动触发）
 *  - resetAiFill: 撤销所有 AI 填充，恢复原始值
 *  - resetField: 撤销单个字段的 AI 填充
 *  - isAiFilled: 判断某字段是否被 AI 填充
 */
export function useAiFormFill<T extends Record<string, any>>(formModel: UnwrapNestedRefs<T>) {
  /** 被 AI 填充的字段集合（驱动 UI 标记） */
  const aiFilledFields = ref<Set<string>>(new Set())

  /** 记录 AI 填充前的原始值（用于撤销） */
  const originalValues = reactive<Record<string, any>>({})

  /** 应用 AI 填充建议到表单 */
  function applyFormFill(suggestion: FormFillSuggestion) {
    const { fields } = suggestion
    if (!fields || typeof fields !== 'object') return

    for (const [key, value] of Object.entries(fields)) {
      if (!(key in formModel)) continue // 只填表单中存在的字段

      // 保存原始值（仅首次，避免覆盖用户手动修改）
      if (!aiFilledFields.value.has(key)) {
        originalValues[key] = (formModel as any)[key]
      }

      ;(formModel as any)[key] = value
      aiFilledFields.value.add(key)
    }

    // 触发新 Set 引用（确保响应式更新）
    aiFilledFields.value = new Set(aiFilledFields.value)
  }

  /** 撤销所有 AI 填充 */
  function resetAiFill() {
    for (const key of aiFilledFields.value) {
      if (key in originalValues) {
        ;(formModel as any)[key] = originalValues[key]
      }
    }
    aiFilledFields.value = new Set()
  }

  /** 撤销单个字段 */
  function resetField(key: string) {
    if (!aiFilledFields.value.has(key)) return
    if (key in originalValues) {
      ;(formModel as any)[key] = originalValues[key]
    }
    aiFilledFields.value.delete(key)
    aiFilledFields.value = new Set(aiFilledFields.value)
  }

  /** 判断字段是否被 AI 填充 */
  function isAiFilled(key: string): boolean {
    return aiFilledFields.value.has(key)
  }

  // 注册全局监听
  const handler: FormFillHandler = (suggestion) => applyFormFill(suggestion)
  formFillListeners.add(handler)

  onUnmounted(() => {
    formFillListeners.delete(handler)
  })

  return {
    aiFilledFields,
    applyFormFill,
    resetAiFill,
    resetField,
    isAiFilled,
  }
}
