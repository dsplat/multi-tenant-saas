<template>
  <div class="container" style="max-width: 560px;">
    <div class="card">
      <h2>申请租户</h2>
      <div v-if="error" class="msg msg-error">{{ error }}</div>
      <div v-if="submitted" class="msg msg-success">
        申请已提交！编号: {{ submitted.code }}
        <br />
        <router-link :to="`/apply/status/${submitted.code}`">查看进度</router-link>
      </div>
      <form v-if="!submitted" @submit.prevent="handleApply">
        <div v-for="field in visibleFields" :key="field.name" class="form-group">
          <label>{{ field.label }} <span v-if="field.required" style="color:#f56c6c">*</span></label>
          <input v-if="field.type === 'text' || field.type === 'tel' || field.type === 'email'"
            v-model="form[field.name]" :type="field.type" :required="field.required" :placeholder="`请输入${field.label}`" />
          <textarea v-else-if="field.type === 'textarea'"
            v-model="form[field.name]" rows="3" :required="field.required" :placeholder="`请输入${field.label}`" />
          <select v-else-if="field.type === 'select'" v-model="form[field.name]" :required="field.required">
            <option value="">请选择</option>
            <option v-for="opt in field.options" :key="opt" :value="opt">{{ opt }}</option>
          </select>
        </div>
        <button type="submit" class="btn btn-primary" :disabled="loading">
          {{ loading ? '提交中...' : '提交申请' }}
        </button>
      </form>
      <div class="text-center mt-16">
        已有账号？<router-link to="/login">登录</router-link>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted, computed } from 'vue'

const loading = ref(false)
const error = ref('')
const submitted = ref<any>(null)
const fields = ref<any[]>([])
const form = reactive<Record<string, any>>({
  org_name: '', org_industry: '', org_size: '', contact_name: '', contact_phone: '', description: '',
})

const visibleFields = computed(() => fields.value.filter(f => f.enabled !== false))

onMounted(async () => {
  try {
    const res = await fetch('/api/v1/public/apply-fields')
    const data = await res.json()
    if (data.success) fields.value = data.data.fields
  } catch {}
})

async function handleApply() {
  const token = localStorage.getItem('operator_token')
  if (!token) {
    error.value = '请先登录后再申请'
    return
  }
  loading.value = true
  error.value = ''
  try {
    const res = await fetch('/api/v1/operator/apply', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`,
      },
      body: JSON.stringify({
        org_name: form.org_name,
        org_industry: form.org_industry || undefined,
        org_size: form.org_size || undefined,
        contact_info: {
          name: form.contact_name,
          phone: form.contact_phone,
        },
      }),
    })
    const data = await res.json()
    if (data.success) {
      submitted.value = data.data.application
    } else {
      error.value = data.message || '提交失败'
    }
  } catch {
    error.value = '网络错误，请稍后重试'
  } finally {
    loading.value = false
  }
}
</script>
