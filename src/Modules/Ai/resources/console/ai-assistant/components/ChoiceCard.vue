<script setup lang="ts">
/**
 * ChoiceCard — 选项卡片（ask_user_choice 工具渲染）
 *
 * AI 需要用户确认或选择时的人类交互点：秘书调用 ask_user_choice 后，
 * 工具结果帧透传 {question, options, multiple}，本卡片渲染可点选按钮：
 *  - 单选（multiple=false）：点击选项即提交，卡片锁定
 *  - 多选（multiple=true）：勾选后点「确认选择」提交
 *
 * 交互铁律落地：
 *  - 可控制：选择权在用户，AI 只给选项不代答
 *  - 防重复：提交后卡片锁定（answered 持久化在 store），组件重渲染保持锁定态
 *  - AI 产出必标注：沿用「AI 代操作」徽标风格
 */
import { ref, computed } from 'vue'
import type { UserChoiceData } from '../types'

const props = defineProps<{
  data: UserChoiceData
  /** 已提交的选项（父级/store 驱动锁定态，选择后不再可点） */
  answered?: string[] | null
}>()

const emit = defineEmits<{
  (e: 'choice', answers: string[]): void
}>()

/** 多选模式下的本地勾选态 */
const selected = ref<Set<string>>(new Set())
/** 本地提交标记（父级 answered 优先，组件重渲染后仍锁定） */
const submitted = ref(false)

const isAnswered = computed(() => submitted.value || (props.answered?.length ?? 0) > 0)
/** 已选中的选项集合（已提交时以 answered 为准） */
const chosenSet = computed<Set<string>>(() =>
  isAnswered.value ? new Set(props.answered ?? []) : selected.value,
)

function toggle(option: string) {
  if (isAnswered.value) return
  if (!props.data.multiple) {
    // 单选：点击即提交
    submitted.value = true
    emit('choice', [option])
    return
  }
  const next = new Set(selected.value)
  if (next.has(option)) next.delete(option)
  else next.add(option)
  selected.value = next
}

function confirmMultiple() {
  if (isAnswered.value || selected.value.size === 0) return
  const answers = props.data.options.filter(o => selected.value.has(o))
  submitted.value = true
  emit('choice', answers)
}
</script>

<template>
  <div class="choice-card" :class="{ answered: isAnswered }">
    <div class="card-badge">🤖 AI 提问 · 请选择</div>

    <div v-if="data.question" class="card-question">{{ data.question }}</div>

    <div class="card-options">
      <button
        v-for="option in data.options"
        :key="option"
        class="option-btn"
        :class="{
          picked: chosenSet.has(option),
          multi: data.multiple,
        }"
        :disabled="isAnswered"
        @click="toggle(option)"
      >
        <span v-if="data.multiple" class="option-check">{{ chosenSet.has(option) ? '☑' : '☐' }}</span>
        {{ option }}
      </button>
    </div>

    <div class="card-actions">
      <template v-if="data.multiple && !isAnswered">
        <button class="btn-submit" :disabled="selected.size === 0" @click="confirmMultiple">
          确认选择{{ selected.size > 0 ? `（${selected.size}）` : '' }}
        </button>
        <span class="hint">可多选</span>
      </template>
      <span v-else-if="isAnswered" class="status-label">✓ 已选择，已发送</span>
    </div>
  </div>
</template>

<style scoped>
.choice-card {
  border: 1px solid color-mix(in srgb, var(--ac, #10b981) 35%, transparent);
  border-radius: 10px;
  padding: 12px;
  margin: 8px 0;
  background: color-mix(in srgb, var(--ac, #10b981) 5%, var(--bg-color, #fff));
}
.choice-card.answered {
  opacity: 0.75;
  border-color: var(--border-color, #e2e8f0);
  background: var(--bg-color, #fff);
}

.card-badge {
  font-size: 10px;
  font-weight: 600;
  color: var(--ac, #10b981);
  margin-bottom: 6px;
}
.card-question {
  font-size: 13px;
  color: var(--text-color-primary, #0f172a);
  margin-bottom: 10px;
}

.card-options {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.option-btn {
  display: flex;
  align-items: center;
  gap: 6px;
  text-align: left;
  padding: 8px 12px;
  border-radius: 8px;
  font-size: 12px;
  line-height: 1.5;
  border: 1px solid var(--border-color, #e2e8f0);
  background: var(--bg-color, #fff);
  color: var(--text-color-primary, #0f172a);
  cursor: pointer;
  transition: border-color 0.15s, background 0.15s;
}
.option-btn:hover:not(:disabled) {
  border-color: var(--ac, #10b981);
  background: color-mix(in srgb, var(--ac, #10b981) 6%, var(--bg-color, #fff));
}
.option-btn.picked {
  border-color: var(--ac, #10b981);
  background: color-mix(in srgb, var(--ac, #10b981) 10%, var(--bg-color, #fff));
  font-weight: 500;
}
.option-btn:disabled {
  cursor: default;
}
.option-check {
  flex-shrink: 0;
  color: var(--ac, #10b981);
}

.card-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-top: 10px;
}
.btn-submit {
  padding: 6px 14px;
  border-radius: 6px;
  font-size: 12px;
  border: none;
  cursor: pointer;
  font-weight: 500;
  background: var(--ac, #10b981);
  color: #fff;
  transition: opacity 0.15s;
}
.btn-submit:hover:not(:disabled) { opacity: 0.85; }
.btn-submit:disabled { opacity: 0.45; cursor: not-allowed; }
.hint { font-size: 10px; color: var(--text-color-secondary, #64748b); }
.status-label {
  font-size: 12px;
  font-weight: 500;
  color: #52c41a;
}
</style>
