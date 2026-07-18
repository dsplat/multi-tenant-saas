<template>
  <div class="container">
    <div class="card">
      <h2>注册</h2>
      <div v-if="success" class="msg msg-success">注册成功！验证邮件已发送，请查收邮箱。</div>
      <div v-if="error" class="msg msg-error">{{ error }}</div>
      <form v-if="!success" @submit.prevent="handleRegister">
        <div class="form-group">
          <label>姓名</label>
          <input v-model="form.name" type="text" required placeholder="请输入姓名" />
        </div>
        <div class="form-group">
          <label>邮箱</label>
          <input v-model="form.email" type="email" required placeholder="请输入邮箱" />
        </div>
        <div class="form-group">
          <label>密码</label>
          <input v-model="form.password" type="password" required minlength="8" placeholder="至少8位" />
        </div>
        <div class="form-group">
          <label>确认密码</label>
          <input v-model="form.password_confirmation" type="password" required placeholder="再次输入密码" />
        </div>
        <button type="submit" class="btn btn-primary" :disabled="loading">
          {{ loading ? '注册中...' : '注册' }}
        </button>
      </form>
      <div class="text-center mt-16">
        已有账号？<router-link to="/login">登录</router-link>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'

const loading = ref(false)
const error = ref('')
const success = ref(false)
const form = reactive({ name: '', email: '', password: '', password_confirmation: '' })

async function handleRegister() {
  loading.value = true
  error.value = ''
  try {
    const res = await fetch('/api/v1/operator-auth/register', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(form),
    })
    const data = await res.json()
    if (data.success) {
      success.value = true
    } else {
      error.value = data.message || '注册失败'
      if (data.errors) {
        const first = Object.values(data.errors)[0]
        error.value = Array.isArray(first) ? first[0] : String(first)
      }
    }
  } catch {
    error.value = '网络错误，请稍后重试'
  } finally {
    loading.value = false
  }
}
</script>
