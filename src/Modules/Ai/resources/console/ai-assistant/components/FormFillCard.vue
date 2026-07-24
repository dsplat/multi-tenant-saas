<script setup lang="ts">
/**
 * FormFillCard — AI 表单填充建议操作卡片
 *
 * 展示 AI 建议的字段值，用户确认后应用到页面表单。
 * 铁律落地：
 *  - 可控制：必须用户点"应用"才填入，不自动写入
 *  - 可理解：每个字段附带填写理由
 *  - AI 产出必标注：卡片明确标记"AI 生成建议"
 *  - 可回退：应用后页面表单可撤销
 */
import { ref, computed } from 'vue'
import type { FormFillSuggestion } from '../types'
import { emitFormFill, hasFormFillListener } from '../composables/useAiFormFill'

const props = defineProps<{
  suggestion: FormFillSuggestion
}>()

const applied = ref(false)
const showDetails = ref(false)

const fieldEntries = computed(() =>
  Object.entries(props.suggestion.fields || {}).map(([key, value]) => ({
    key,
    value: String(value ?? ''),
    note: props.suggestion.field_notes?.[key] || '',
  }))
)

const confidence = computed(() => props.suggestion.confidence ?? 0.8)
const confidenceLabel = computed(() =>
  confidence.value >= 0.8 ? '高置信' : confidence.value >= 0.6 ? '中置信' : '低置信（请仔细核对）'
)

const canApply = computed(() => hasFormFillListener())

function handleApply() {
  emitFormFill(props.suggestion)
  applied.value = true
}
</script>

<template>
  <div class="form-fill-card" :class="{ applied, 'low-confidence': confidence < 0.6 }">
    <div class="card-badge">🤖 AI 生成建议</div>

    <div v-if="suggestion.explanation" class="card-explanation">
      {{ suggestion.explanation }}
    </div>

    <!-- 字段预览 -->
    <div class="card-fields" :class="{ collapsed: !showDetails && fieldEntries.length > 3 }">
      <div v-for="field in (showDetails ? fieldEntries : fieldEntries.slice(0, 3))" :key="field.key" class="field-row">
        <span class="field-key">{{ field.key }}</span>
        <span class="field-value">{{ field.value }}</span>
        <span v-if="field.note" class="field-note" :title="field.note">💡</span>
      </div>
      <button
        v-if="fieldEntries.length > 3"
        class="toggle-details"
        @click="showDetails = !showDetails"
      >
        {{ showDetails ? '收起' : `展开全部 ${fieldEntries.length} 个字段` }}
      </button>
    </div>

    <!-- 置信度 -->
    <div class="card-confidence">
      <span class="confidence-dot" :class="{ high: confidence >= 0.8, mid: confidence >= 0.6 && confidence < 0.8, low: confidence < 0.6 }" />
      {{ confidenceLabel }}
    </div>

    <!-- 操作按钮 -->
    <div class="card-actions">
      <template v-if="!applied">
        <button class="btn-apply" :disabled="!canApply" @click="handleApply">
          ✓ 应用到表单
        </button>
        <span v-if="!canApply" class="no-form-hint">当前页面无表单</span>
      </template>
      <span v-else class="applied-label">✓ 已应用（可在表单中撤销）</span>
    </div>
  </div>
</template>

<style scoped>
.form-fill-card {
  border: 1px solid color-mix(in srgb, var(--ac, #10b981) 30%, transparent);
  border-radius: 10px;
  padding: 12px;
  margin: 8px 0;
  background: color-mix(in srgb, var(--ac, #10b981) 4%, var(--bg-color, #fff));
}
.form-fill-card.applied {
  opacity: 0.7;
  border-color: var(--border-color, #e2e8f0);
}
.form-fill-card.low-confidence {
  border-color: color-mix(in srgb, var(--badge-warning-fg, #fa8c16) 50%, transparent);
  background: color-mix(in srgb, var(--badge-warning-fg, #fa8c16) 5%, var(--bg-color, #fff));
}

.card-badge {
  font-size: 10px;
  font-weight: 600;
  color: var(--ac, #10b981);
  margin-bottom: 6px;
}
.card-explanation {
  font-size: 12px;
  color: var(--text-color-secondary, #64748b);
  margin-bottom: 10px;
  line-height: 1.5;
}

.card-fields { display: flex; flex-direction: column; gap: 4px; }
.card-fields.collapsed { max-height: 88px; overflow: hidden; }
.field-row {
  display: flex; align-items: center; gap: 8px;
  font-size: 12px; padding: 3px 6px;
  border-radius: 4px; background: var(--fill-color, #f8fafc);
}
.field-key {
  font-weight: 500; color: var(--text-color-primary, #0f172a);
  min-width: 72px; flex-shrink: 0;
}
.field-value {
  color: var(--text-color-secondary, #64748b);
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}
.field-note { font-size: 11px; cursor: help; flex-shrink: 0; }
.toggle-details {
  border: none; background: none; font-size: 11px;
  color: var(--ac, #10b981); cursor: pointer; padding: 4px 0;
  text-align: left;
}

.card-confidence {
  display: flex; align-items: center; gap: 5px;
  font-size: 10px; color: var(--text-color-secondary, #64748b);
  margin-top: 8px;
}
.confidence-dot {
  width: 6px; height: 6px; border-radius: 50%;
}
.confidence-dot.high { background: #52c41a; }
.confidence-dot.mid { background: #fa8c16; }
.confidence-dot.low { background: #f5222d; }

.card-actions {
  display: flex; align-items: center; gap: 10px;
  margin-top: 10px;
}
.btn-apply {
  padding: 6px 14px; border-radius: 6px; font-size: 12px;
  border: none; cursor: pointer; font-weight: 500;
  background: var(--ac, #10b981); color: #fff;
  transition: opacity 0.15s;
}
.btn-apply:hover:not(:disabled) { opacity: 0.85; }
.btn-apply:disabled { opacity: 0.4; cursor: not-allowed; }
.no-form-hint { font-size: 10px; color: var(--text-color-secondary, #64748b); }
.applied-label { font-size: 12px; color: #52c41a; font-weight: 500; }
</style>
