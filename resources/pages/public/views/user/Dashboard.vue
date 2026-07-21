<template>
  <div class="page">
    <h1 class="page-title">概览</h1>
    <div class="cards">
      <div class="card">
        <div class="card-label">账户状态</div>
        <div class="card-value" :class="user?.email_verified_at ? 'text-success' : 'text-warning'">
          {{ user?.email_verified_at ? '已验证' : '未验证' }}
        </div>
      </div>
      <div class="card">
        <div class="card-label">未读通知</div>
        <div class="card-value">{{ unreadCount }}</div>
      </div>
      <div class="card">
        <div class="card-label">绑定账号</div>
        <div class="card-value">{{ oauthCount }}</div>
      </div>
    </div>

    <div class="section" v-if="user && !user.email_verified_at">
      <div class="alert alert-warning">
        您的邮箱尚未验证，部分功能可能受限。
        <button class="btn-link" @click="resendVerification">重新发送验证邮件</button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'

const user = ref<any>(null)
const unreadCount = ref(0)
const oauthCount = ref(0)

onMounted(async () => {
  try {
    const stored = localStorage.getItem('user_info')
    if (stored) user.value = JSON.parse(stored)
  } catch {}

  const token = localStorage.getItem('user_token')
  const headers = { Authorization: `Bearer ${token}` }

  // 获取未读数
  try {
    const res = await fetch('/api/v1/in-app-notifications/unread-count', { headers })
    const data = await res.json()
    unreadCount.value = data.unread_count ?? data.data?.unread_count ?? 0
  } catch {}

  // 获取 OAuth 绑定数
  try {
    const res = await fetch('/api/v1/auth/oauth-bindings', { headers })
    const data = await res.json()
    if (data.success) oauthCount.value = (data.data || []).length
  } catch {}
})

async function resendVerification() {
  const token = localStorage.getItem('user_token')
  try {
    await fetch('/api/v1/auth/resend-verification', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify({ email: user.value?.email }),
    })
    alert('验证邮件已发送')
  } catch {}
}
</script>

<style scoped>
.page-title { font-size: 24px; margin-bottom: 24px; }
.cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 24px; }
.card { background: #fff; border-radius: 8px; padding: 20px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
.card-label { font-size: 13px; color: #666; margin-bottom: 8px; }
.card-value { font-size: 28px; font-weight: 600; }
.text-success { color: #2e7d32; }
.text-warning { color: #f57c00; }
.alert { padding: 12px 16px; border-radius: 6px; font-size: 14px; }
.alert-warning { background: #fff8e1; border: 1px solid #ffe082; }
.btn-link { background: none; border: none; color: #1976d2; cursor: pointer; text-decoration: underline; margin-left: 8px; }
</style>
