<template>
  <div class="container">
    <div class="card text-center">
      <div v-if="loading" class="oauth-loading">
        <div class="spinner"></div>
        <p>正在处理第三方登录...</p>
      </div>
      <div v-else-if="error" class="oauth-error">
        <h3>登录失败</h3>
        <p class="msg msg-error">{{ error }}</p>
        <router-link to="/login" class="btn btn-primary">返回登录</router-link>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()
const loading = ref(true)
const error = ref('')

onMounted(async () => {
  const code = route.query.code as string
  const state = route.query.state as string
  const provider = route.params.provider as string

  if (!code) {
    error.value = '缺少授权码'
    loading.value = false
    return
  }

  try {
    // 调用后端 OAuth 回调端点换取 token
    const params = new URLSearchParams({ code })
    if (state) params.set('state', state)

    const res = await fetch(`/api/v1/auth/${provider}/callback?${params.toString()}`)
    const data = await res.json()

    if (data.success && data.data) {
      const { token, user } = data.data
      localStorage.setItem('user_token', token)
      localStorage.setItem('user_info', JSON.stringify(user))

      // 跳转到用户中心或原始目标
      const redirect = (route.query.redirect as string) || '/dashboard'
      window.location.href = redirect
    } else {
      error.value = data.message || '第三方登录失败'
      loading.value = false
    }
  } catch {
    error.value = '网络错误，请稍后重试'
    loading.value = false
  }
})
</script>

<style scoped>
.oauth-loading {
  padding: 40px 0;
}
.spinner {
  width: 32px;
  height: 32px;
  border: 3px solid #e0e0e0;
  border-top-color: #1976d2;
  border-radius: 50%;
  animation: spin 0.8s linear infinite;
  margin: 0 auto 16px;
}
@keyframes spin {
  to { transform: rotate(360deg); }
}
.oauth-error {
  padding: 20px 0;
}
.text-center {
  text-align: center;
}
</style>
