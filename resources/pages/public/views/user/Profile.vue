<template>
  <div class="page">
    <h1 class="page-title">个人资料</h1>
    <div class="panel">
      <div v-if="msg" :class="['alert', success ? 'alert-success' : 'alert-error']">{{ msg }}</div>
      <form @submit.prevent="save">
        <div class="form-group">
          <label>姓名</label>
          <input v-model="form.name" type="text" placeholder="您的姓名" />
        </div>
        <div class="form-group">
          <label>邮箱</label>
          <input :value="user?.email" type="email" disabled class="disabled" />
          <span class="hint">邮箱不可修改</span>
        </div>
        <div class="form-group">
          <label>手机号</label>
          <input v-model="form.phone" type="text" placeholder="选填" />
        </div>
        <button type="submit" class="btn btn-primary" :disabled="saving">
          {{ saving ? '保存中...' : '保存修改' }}
        </button>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'

const user = ref<any>(null)
const saving = ref(false)
const msg = ref('')
const success = ref(false)
const form = reactive({ name: '', phone: '' })

onMounted(() => {
  try {
    const stored = localStorage.getItem('user_info')
    if (stored) {
      user.value = JSON.parse(stored)
      form.name = user.value.name || ''
      form.phone = user.value.phone || ''
    }
  } catch {}
})

async function save() {
  saving.value = true
  msg.value = ''
  try {
    const token = localStorage.getItem('user_token')
    const res = await fetch('/api/v1/auth/profile', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify(form),
    })
    const data = await res.json()
    if (data.success) {
      success.value = true
      msg.value = '保存成功'
      // 更新本地缓存
      if (data.data) {
        localStorage.setItem('user_info', JSON.stringify(data.data))
        user.value = data.data
      }
    } else {
      success.value = false
      msg.value = data.message || '保存失败'
    }
  } catch {
    success.value = false
    msg.value = '网络错误'
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.page-title { font-size: 24px; margin-bottom: 24px; }
.panel { background: #fff; border-radius: 8px; padding: 24px; max-width: 480px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 6px; }
.form-group input { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
.form-group input.disabled { background: #f5f5f5; color: #999; }
.hint { font-size: 12px; color: #999; margin-top: 4px; display: block; }
.btn { padding: 10px 20px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; }
.btn-primary { background: #1976d2; color: #fff; }
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.alert { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
.alert-success { background: #e8f5e9; color: #2e7d32; }
.alert-error { background: #fbe9e7; color: #c62828; }
</style>
