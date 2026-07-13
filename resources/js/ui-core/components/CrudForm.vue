<template>
  <div class="crud-form">
    <div v-if="title" class="form-header">
      <h3>{{ title }}</h3>
      <button v-if="closable" class="close-btn" @click="$emit('close')">&times;</button>
    </div>

    <form @submit.prevent="handleSubmit">
      <div v-for="field in fields" :key="field.key" class="form-group">
        <label :for="field.key">
          {{ field.label }}
          <span v-if="field.required" class="required">*</span>
        </label>

        <input
          v-if="!field.type || field.type === 'text' || field.type === 'email' || field.type === 'password' || field.type === 'number'"
          :id="field.key"
          v-model="formState[field.key]"
          :type="field.type || 'text'"
          :placeholder="field.placeholder"
          :required="field.required"
          :disabled="field.disabled"
        />

        <select
          v-else-if="field.type === 'select'"
          :id="field.key"
          v-model="formState[field.key]"
          :required="field.required"
          :disabled="field.disabled"
        >
          <option value="" disabled>{{ field.placeholder || '请选择' }}</option>
          <option v-for="opt in field.options" :key="opt.value" :value="opt.value">
            {{ opt.label }}
          </option>
        </select>

        <textarea
          v-else-if="field.type === 'textarea'"
          :id="field.key"
          v-model="formState[field.key]"
          :placeholder="field.placeholder"
          :required="field.required"
          :disabled="field.disabled"
          rows="4"
        />

        <p v-if="field.hint" class="form-hint">{{ field.hint }}</p>
        <p v-if="errors[field.key]" class="form-error">{{ errors[field.key] }}</p>
      </div>

      <div class="form-actions">
        <slot name="actions">
          <button type="button" class="cancel-btn" @click="$emit('close')">取消</button>
          <button type="submit" class="submit-btn" :disabled="submitting">
            {{ submitting ? '提交中...' : submitText }}
          </button>
        </slot>
      </div>
    </form>
  </div>
</template>

<script setup lang="ts">
import { reactive, watch } from 'vue'

export interface FormField {
  key: string
  label: string
  type?: 'text' | 'email' | 'password' | 'number' | 'select' | 'textarea'
  placeholder?: string
  required?: boolean
  disabled?: boolean
  hint?: string
  options?: { label: string; value: any }[]
  default?: any
}

const props = withDefaults(defineProps<{
  fields: FormField[]
  modelValue?: Record<string, any>
  title?: string
  closable?: boolean
  submitText?: string
  submitting?: boolean
  errors?: Record<string, string>
}>(), {
  modelValue: () => ({}),
  closable: true,
  submitText: '保存',
  submitting: false,
  errors: () => ({}),
})

const emit = defineEmits<{
  (e: 'update:modelValue', value: Record<string, any>): void
  (e: 'submit', value: Record<string, any>): void
  (e: 'close'): void
}>()

const formState = reactive<Record<string, any>>({})

// 初始化表单状态
const initForm = () => {
  for (const field of props.fields) {
    formState[field.key] = props.modelValue[field.key] ?? field.default ?? ''
  }
}

watch(() => props.modelValue, initForm, { immediate: true, deep: true })

const handleSubmit = () => {
  emit('update:modelValue', { ...formState })
  emit('submit', { ...formState })
}
</script>

<style scoped>
.crud-form { background: var(--bg-color, #fff); border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.form-header { display: flex; justify-content: space-between; align-items: center; padding: 20px 24px; border-bottom: 1px solid var(--border-color, #eee); }
.form-header h3 { margin: 0; font-size: 16px; }
.close-btn { background: none; border: none; font-size: 24px; cursor: pointer; color: var(--text-color-secondary, #999); }
form { padding: 24px; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 6px; font-size: 13px; color: var(--text-color-secondary, #666); }
.required { color: #f56c6c; }
.form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 14px; box-sizing: border-box; background: var(--bg-color, #fff); color: var(--text-color-primary, #333); }
.form-group input:focus, .form-group select:focus, .form-group textarea:focus { outline: none; border-color: var(--primary-color, #409eff); }
.form-hint { margin: 4px 0 0; font-size: 12px; color: var(--text-color-secondary, #999); }
.form-error { margin: 4px 0 0; font-size: 12px; color: #f56c6c; }
.form-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 24px; padding-top: 16px; border-top: 1px solid var(--border-color, #eee); }
.cancel-btn { padding: 8px 20px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; background: var(--bg-color, #fff); cursor: pointer; font-size: 13px; color: var(--text-color-secondary, #666); }
.submit-btn { padding: 8px 20px; border: none; border-radius: 6px; background: var(--primary-color, #409eff); color: #fff; font-size: 13px; cursor: pointer; }
.submit-btn:disabled { opacity: 0.6; cursor: not-allowed; }
</style>
