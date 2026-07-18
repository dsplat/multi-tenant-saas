<template>
  <div class="container">
    <div class="card">
      <h2>重置密码</h2>
      <div v-if="error" class="msg msg-error">{{ error }}</div>
      <div v-if="success" class="msg msg-success">密码已重置，请前往登录。</div>
      <form v-if="!success" @submit.prevent="handleReset">
        <div class="form-group">
          <label>新密码</label>
          <input v-model="password" type="password" required minlength="8" placeholder="至少8位" />
        </div>
        <div class="form-group">
          <label>确认密码</label>
          <input v-model="passwordConfirmation" type="password" required minlength="8" placeholder="再次输入" />
        </div>
        <button type="submit" class="btn btn-primary" :disabled="loading">
          {{ loading ? '提交中...' : '重置密码' }}
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
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()
const loading = ref(false)
const error = ref('')
const success = ref(false)
const password = ref('')
const passwordConfirmation = ref('')

async function handleReset() {
  if (password.value !== passwordConfirmation.value) {
    error.value = '两次输入的密码不一致'
    return
  }

  const token = route.query.token as string
  const email = route.query.email as string

  if (!token || !email) {
    error.value = '重置链接无效，请重新发起密码重置'
    return
  }

  loading.value = true
  error.value = ''
  try {
    // Operator 侧重置
    const res = await fetch('/api/v1/operator-auth/reset-password', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        email,
        token,
        password: password.value,
        password_confirmation: passwordConfirmation.value,
      }),
    })
    const data = await res.json()
    if (data.success) {
      success.value = true
      setTimeout(() => router.push('/login'), 2000)
    } else {
      error.value = data.message || '重置失败'
    }
  } catch {
    error.value = '网络错误，请稍后重试'
  } finally {
    loading.value = false
  }
}
</script>
