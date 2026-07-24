<script setup lang="ts">
/**
 * WorkflowProgress — 长流程编排步骤进度
 *
 * 展示 AI 一次性生成的多步骤草稿，用户逐步审阅/确认。
 * 铁律落地：
 *  - 写操作必经既定 Service：最终确认调的是 submit_endpoint（既有业务 API）
 *  - 可控制：每步可展开编辑，不强制一次性接受
 *  - 可理解：步骤状态一目了然（✓完成 / ⚠需决策 / ○待审）
 *  - AI 产出必标注：明确标记"AI 编排草稿"
 */
import { ref, computed } from 'vue'
import axios from 'axios'
import type { WorkflowSuggestion, WorkflowStep } from '../types'

const props = defineProps<{
  workflow: WorkflowSuggestion
}>()

const expandedStep = ref<string | null>(null)
const submitted = ref(false)
const submitting = ref(false)
const submitError = ref('')

const steps = computed(() => props.workflow.steps || [])
const doneCount = computed(() => steps.value.filter(s => s.status === 'done').length)
const warningCount = computed(() => steps.value.filter(s => s.status === 'warning').length)
const allReady = computed(() => steps.value.every(s => s.status === 'done' || s.status === 'warning'))

function statusIcon(status: WorkflowStep['status']): string {
  switch (status) {
    case 'done': return '✓'
    case 'warning': return '⚠'
    case 'error': return '✗'
    case 'current': return '●'
    default: return '○'
  }
}

function toggleStep(key: string) {
  expandedStep.value = expandedStep.value === key ? null : key
}

/** 最终确认提交（调既定业务 API，非 AI 私有路径） */
async function handleSubmit() {
  if (!props.workflow.submit_endpoint || !props.workflow.submit_payload) return

  submitting.value = true
  submitError.value = ''

  try {
    await axios.post(props.workflow.submit_endpoint, props.workflow.submit_payload)
    submitted.value = true
  } catch (e: any) {
    submitError.value = e?.response?.data?.message || '提交失败，请使用页面原有功能操作。'
  } finally {
    submitting.value = false
  }
}
</script>

<template>
  <div class="workflow-card" :class="{ submitted }">
    <div class="wf-header">
      <span class="wf-badge">🤖 AI 编排草稿</span>
      <span class="wf-name">{{ workflow.name }}</span>
      <span class="wf-progress">{{ doneCount }}/{{ steps.length }} 步</span>
    </div>

    <div v-if="workflow.explanation" class="wf-explanation">{{ workflow.explanation }}</div>

    <!-- 步骤条 -->
    <div class="wf-steps">
      <div
        v-for="step in steps"
        :key="step.key"
        class="wf-step"
        :class="[`status-${step.status}`, { expanded: expandedStep === step.key }]"
        @click="toggleStep(step.key)"
      >
        <div class="step-header">
          <span class="step-icon">{{ statusIcon(step.status) }}</span>
          <span class="step-label">{{ step.label }}</span>
          <span v-if="step.status === 'warning'" class="step-warn">需决策</span>
        </div>

        <!-- 展开的草稿详情 -->
        <div v-if="expandedStep === step.key && step.draft" class="step-draft">
          <div v-for="(val, key) in step.draft" :key="key" class="draft-row">
            <span class="draft-key">{{ key }}</span>
            <span class="draft-val">{{ val }}</span>
          </div>
          <div v-if="step.message" class="step-message" :class="{ warn: step.status === 'warning' }">
            {{ step.message }}
          </div>
        </div>
      </div>
    </div>

    <!-- 提交区 -->
    <div class="wf-footer">
      <template v-if="!submitted">
        <button
          class="btn-submit"
          :disabled="!allReady || submitting || !workflow.submit_endpoint"
          @click="handleSubmit"
        >
          {{ submitting ? '提交中…' : '✓ 确认创建' }}
        </button>
        <span v-if="!workflow.submit_endpoint" class="no-endpoint-hint">
          请在对应页面完成最终提交
        </span>
        <span v-if="submitError" class="submit-error">{{ submitError }}</span>
      </template>
      <span v-else class="submitted-label">✓ 已创建成功</span>
    </div>
  </div>
</template>

<style scoped>
.workflow-card {
  border: 1px solid color-mix(in srgb, var(--ac, #10b981) 30%, transparent);
  border-radius: 12px;
  padding: 14px;
  margin: 8px 0;
  background: color-mix(in srgb, var(--ac, #10b981) 3%, var(--bg-color, #fff));
}
.workflow-card.submitted { opacity: 0.7; }

.wf-header {
  display: flex; align-items: center; gap: 8px;
  margin-bottom: 8px;
}
.wf-badge { font-size: 10px; font-weight: 600; color: var(--ac, #10b981); }
.wf-name { font-size: 13px; font-weight: 600; color: var(--text-color-primary, #0f172a); }
.wf-progress { font-size: 11px; color: var(--text-color-secondary, #64748b); margin-left: auto; }

.wf-explanation {
  font-size: 12px; color: var(--text-color-secondary, #64748b);
  margin-bottom: 12px; line-height: 1.5;
}

.wf-steps { display: flex; flex-direction: column; gap: 4px; }
.wf-step {
  border-radius: 8px; padding: 8px 10px;
  cursor: pointer; transition: background 0.15s;
  border: 1px solid transparent;
}
.wf-step:hover { background: var(--fill-color, #f8fafc); }
.wf-step.expanded { border-color: var(--border-color, #e2e8f0); background: var(--fill-color, #f8fafc); }

.step-header { display: flex; align-items: center; gap: 8px; }
.step-icon { font-size: 13px; width: 18px; text-align: center; }
.status-done .step-icon { color: #52c41a; }
.status-warning .step-icon { color: #fa8c16; }
.status-error .step-icon { color: #f5222d; }
.status-current .step-icon { color: var(--ac, #10b981); }
.status-pending .step-icon { color: var(--text-color-secondary, #94a3b8); }

.step-label { font-size: 12px; font-weight: 500; color: var(--text-color-primary, #0f172a); }
.step-warn {
  font-size: 10px; padding: 1px 6px; border-radius: 8px;
  background: color-mix(in srgb, #fa8c16 12%, transparent);
  color: #fa8c16; margin-left: auto;
}

.step-draft { margin-top: 8px; padding-left: 26px; }
.draft-row {
  display: flex; gap: 8px; font-size: 11px; padding: 2px 0;
}
.draft-key { color: var(--text-color-secondary, #64748b); min-width: 80px; flex-shrink: 0; }
.draft-val { color: var(--text-color-primary, #0f172a); }
.step-message {
  margin-top: 6px; font-size: 11px; padding: 6px 8px;
  border-radius: 6px; background: var(--fill-color, #f8fafc);
  color: var(--text-color-secondary, #64748b);
}
.step-message.warn {
  background: color-mix(in srgb, #fa8c16 8%, transparent);
  color: #d46b08;
}

.wf-footer {
  display: flex; align-items: center; gap: 10px;
  margin-top: 12px; padding-top: 10px;
  border-top: 1px solid var(--border-color, #e2e8f0);
}
.btn-submit {
  padding: 7px 16px; border-radius: 8px; font-size: 12px;
  border: none; cursor: pointer; font-weight: 600;
  background: var(--ac, #10b981); color: #fff;
  transition: opacity 0.15s;
}
.btn-submit:hover:not(:disabled) { opacity: 0.85; }
.btn-submit:disabled { opacity: 0.4; cursor: not-allowed; }
.no-endpoint-hint { font-size: 10px; color: var(--text-color-secondary, #64748b); }
.submit-error { font-size: 11px; color: #f5222d; }
.submitted-label { font-size: 13px; color: #52c41a; font-weight: 600; }
</style>
