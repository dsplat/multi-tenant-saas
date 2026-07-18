<template>
  <div class="container">
    <div class="card">
      <h2>邮箱验证</h2>
      <div v-if="status === 'verifying'" class="msg msg-info">正在验证邮箱...</div>
      <div v-if="status === 'success'" class="msg msg-success">邮箱验证成功！<router-link to="/login">前往登录</router-link></div>
      <div v-if="status === 'error'" class="msg msg-error">{{ message }}</div>
      <div v-if="status === 'resend'" class="msg msg-success">验证邮件已重新发送，请查收邮箱。</div>
      <div class="text-center mt-16">
        <button class="btn btn-link" @click="resendVerification">重新发送验证邮件</button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()
const status = ref<'verifying' | 'success' | 'error' | 'resend' | ''>('')
const message = ref('')

onMounted(async () => {
  const token = route.query.token as string
  if (!token) {
    status.value = 'error'
    message.value = '缺少验证令牌'
    return
  }
  status.value = 'verifying'
  try {
    const res = await fetch('/api/v1/operator-auth/verify-email', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token }),
    })
    const data = await res.json()
    if (data.success) {
      status.value = 'success'
    } else {
      status.value = 'error'
      message.value = data.message || '验证失败'
    }
  } catch {
    status.value = 'error'
    message.value = '网络错误'
  }
})

async function resendVerification() {
  const email = route.query.email as string
  if (!email) {
    status.value = 'error'
    message.value = '缺少邮箱信息'
    return
  }
  try {
    const res = await fetch('/api/v1/operator-auth/resend-verification', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email }),
    })
    const data = await res.json()
    if (data.success) status.value = 'resend'
    else { status.value = 'error'; message.value = data.message || '发送失败' }
  } catch {
    status.value = 'error'
    message.value = '网络错误'
  }
}
</script>
