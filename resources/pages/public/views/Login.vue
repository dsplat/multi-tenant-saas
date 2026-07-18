<template>
  <div class="container">
    <div class="card">
      <h2>登录</h2>
      <div v-if="error" class="msg msg-error">{{ error }}</div>
      <form @submit.prevent="handleLogin">
        <div class="form-group">
          <label>邮箱</label>
          <input v-model="form.email" type="email" required placeholder="请输入邮箱" />
        </div>
        <div class="form-group">
          <label>密码</label>
          <input v-model="form.password" type="password" required placeholder="请输入密码" />
        </div>
        <button type="submit" class="btn btn-primary" :disabled="loading">
          {{ loading ? '登录中...' : '登录' }}
        </button>
      </form>
      <div class="text-center mt-16">
        <router-link to="/forgot-password">忘记密码？</router-link>
        &nbsp;|&nbsp;
        <router-link to="/register">注册账号</router-link>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()
const loading = ref(false)
const error = ref('')
const form = reactive({ email: '', password: '' })

async function handleLogin() {
  loading.value = true
  error.value = ''
  try {
    const res = await fetch('/api/v1/operator-auth/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(form),
    })
    const data = await res.json()
    if (data.success) {
      localStorage.setItem('operator_token', data.data.token)
      localStorage.setItem('operator', JSON.stringify(data.data.operator))
      window.location.href = '/console'
    } else {
      error.value = data.message || '登录失败'
    }
  } catch {
    error.value = '网络错误，请稍后重试'
  } finally {
    loading.value = false
  }
}
</script>
