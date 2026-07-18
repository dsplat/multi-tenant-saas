<template>
  <div class="container">
    <div class="card">
      <h2>忘记密码</h2>
      <div v-if="success" class="msg msg-success">重置密码邮件已发送，请查收邮箱。</div>
      <div v-if="error" class="msg msg-error">{{ error }}</div>
      <form v-if="!success" @submit.prevent="handleForgot">
        <div class="form-group">
          <label>邮箱</label>
          <input v-model="email" type="email" required placeholder="请输入注册邮箱" />
        </div>
        <button type="submit" class="btn btn-primary" :disabled="loading">
          {{ loading ? '发送中...' : '发送重置链接' }}
        </button>
      </form>
      <div class="text-center mt-16">
        <router-link to="/login">返回登录</router-link>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'

const loading = ref(false)
const error = ref('')
const success = ref(false)
const email = ref('')

async function handleForgot() {
  loading.value = true
  error.value = ''
  try {
    const res = await fetch('/api/v1/operator-auth/forgot-password', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: email.value }),
    })
    const data = await res.json()
    if (data.success) success.value = true
    else error.value = data.message || '发送失败'
  } catch {
    error.value = '网络错误，请稍后重试'
  } finally {
    loading.value = false
  }
}
</script>
