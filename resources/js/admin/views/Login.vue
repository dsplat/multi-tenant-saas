<template>
  <div class="login-page">
    <div class="login-card">
      <h2>系统后台登录</h2>
      <form @submit.prevent="handleLogin">
        <div class="form-group">
          <label>邮箱</label>
          <input v-model="form.email" type="email" placeholder="请输入邮箱" required />
        </div>
        <div class="form-group">
          <label>密码</label>
          <input v-model="form.password" type="password" placeholder="请输入密码" required />
        </div>
        <button type="submit" class="login-btn" :disabled="loading">
          {{ loading ? '登录中...' : '登录' }}
        </button>
        <p v-if="error" class="error-msg">{{ error }}</p>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useUserStore } from '../stores/user'

const router = useRouter()
const route = useRoute()
const userStore = useUserStore()

const loading = ref(false)
const error = ref('')

const form = reactive({
  email: '',
  password: '',
})

const handleLogin = async () => {
  loading.value = true
  error.value = ''
  try {
    await userStore.login(form.email, form.password)
    const redirect = (route.query.redirect as string) || '/admin/'
    router.push(redirect)
  } catch (e: any) {
    error.value = e.response?.data?.message || '登录失败'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.login-page {
  height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--bg-color-page, #f5f7fa);
}

.login-card {
  width: 380px;
  padding: 32px;
  background: var(--bg-color, #fff);
  border-radius: 8px;
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1);
}

.login-card h2 {
  text-align: center;
  margin: 0 0 24px;
  color: var(--text-color-primary, #333);
}

.form-group {
  margin-bottom: 16px;
}

.form-group label {
  display: block;
  margin-bottom: 6px;
  font-size: 14px;
  color: var(--text-color-secondary, #666);
}

.form-group input {
  width: 100%;
  padding: 10px 12px;
  border: 1px solid var(--border-color, #ddd);
  border-radius: 6px;
  font-size: 14px;
  background: var(--bg-color, #fff);
  color: var(--text-color-primary, #333);
  box-sizing: border-box;
}

.form-group input:focus {
  outline: none;
  border-color: var(--link-color);
}

.login-btn {
  width: 100%;
  padding: 10px;
  border: none;
  border-radius: 6px;
  background: var(--primary-color, #409eff);
  color: #fff;
  font-size: 15px;
  cursor: pointer;
  margin-top: 8px;
}

.login-btn:disabled {
  opacity: 0.6;
  cursor: not-allowed;
}

.error-msg {
  color: var(--link-danger);
  font-size: 13px;
  text-align: center;
  margin-top: 12px;
}
</style>
