<template>
  <el-form ref="formRef" :model="model" :rules="mergedRules" :label-width="labelWidth" @submit.prevent>
    <el-form-item v-for="f in fields" :key="f.prop" :label="f.label" :prop="f.prop">
      <el-input v-if="!f.type || f.type === 'text'" v-model="model[f.prop]" :placeholder="f.placeholder" />
      <el-input v-else-if="f.type === 'textarea'" v-model="model[f.prop]" type="textarea" :rows="f.rows || 3"
        :placeholder="f.placeholder" />
      <el-input-number v-else-if="f.type === 'number'" v-model="model[f.prop]" :min="f.min" :max="f.max"
        :precision="f.precision" controls-position="right" style="width: 100%" />
      <el-select v-else-if="f.type === 'select'" v-model="model[f.prop]" :placeholder="f.placeholder" clearable
        style="width: 100%">
        <el-option v-for="opt in f.options" :key="opt.value" :label="opt.label" :value="opt.value" />
      </el-select>
      <el-switch v-else-if="f.type === 'switch'" v-model="model[f.prop]" />
      <el-date-picker v-else-if="f.type === 'date'" v-model="model[f.prop]" type="date"
        value-format="YYYY-MM-DD" style="width: 100%" />
      <el-date-picker v-else-if="f.type === 'datetime'" v-model="model[f.prop]" type="datetime"
        value-format="YYYY-MM-DD HH:mm:ss" style="width: 100%" />
    </el-form-item>
    <slot />
  </el-form>
</template>

<script setup lang="ts">
/**
 * Schema 驱动表单
 *
 * fields 声明字段类型与必填，自动生成 el-form 校验规则；
 * 可通过 rules prop 追加/覆盖自定义规则。
 */
import { computed, ref } from 'vue'
import type { FormInstance, FormRules } from 'element-plus'

export interface CrudFormField {
  prop: string
  label: string
  type?: 'text' | 'textarea' | 'number' | 'select' | 'switch' | 'date' | 'datetime'
  required?: boolean
  placeholder?: string
  options?: Array<{ label: string; value: string | number }>
  rows?: number
  min?: number
  max?: number
  precision?: number
}

const props = withDefaults(defineProps<{
  fields: CrudFormField[]
  model: Record<string, any>
  rules?: FormRules
  labelWidth?: string
}>(), {
  rules: () => ({}),
  labelWidth: '120px',
})

const formRef = ref<FormInstance>()

/** field 声明的 required 自动转规则，与外部 rules 合并（外部优先） */
const mergedRules = computed<FormRules>(() => {
  const auto: FormRules = {}
  for (const f of props.fields) {
    if (f.required) {
      auto[f.prop] = [{
        required: true,
        message: `请填写${f.label}`,
        trigger: ['blur', 'change'],
      }]
    }
  }
  return { ...auto, ...props.rules }
})

const validate = () => formRef.value?.validate() ?? Promise.resolve(true)
const resetFields = () => formRef.value?.resetFields()

defineExpose({ validate, resetFields })
</script>
