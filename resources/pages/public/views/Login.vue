<template>
  <div class="container">
    <div class="card">
      <!-- 租户品牌 -->
      <div v-if="tenant" class="tenant-brand">
        <img v-if="tenant.logo" :src="tenant.logo" alt="logo" class="tenant-logo" />
        <h2>{{ tenant.name }}</h2>
        <p v-if="tenant.branding?.login_page_message" class="tenant-message">
          {{ tenant.branding.login_page_message }}
        </p>
      </div>
      <h2 v-else>登录</h2>

      <!-- 邮箱未验证提示 -->
      <div v-if="showUnverifiedBanner" class="msg msg-warning">
        <div class="banner-title">邮箱未验证</div>
        <div class="banner-desc">您的邮箱尚未验证，部分功能可能受限。</div>
        <div class="banner-actions">
          <button class="btn btn-link" @click="resendVerification" :disabled="resendLoading">
            {{ resendLoading ? '发送中...' : '重新发送验证邮件' }}
          </button>
          <button class="btn btn-link" @click="continueAnyway">仍然继续</button>
        </div>
        <div v-if="resendMsg" :class="['banner-msg', resendSuccess ? 'text-success' : 'text-error']">
          {{ resendMsg }}
        </div>
      </div>

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

      <!-- OAuth 登录按钮 -->
      <div v-if="oauthProviders.length > 0" class="oauth-section">
        <div class="divider"><span>或</span></div>
        <div class="oauth-buttons">
          <button
            v-for="provider in oauthProviders"
            :key="provider.provider"
            class="btn btn-oauth"
            @click="handleOAuthLogin(provider.provider)"
          >
            {{ provider.name }} 登录
          </button>
        </div>
      </div>

      <!-- SSO 登录按钮 -->
      <div v-if="ssoProviders.length > 0" class="oauth-section">
        <div class="divider"><span>企业 SSO</span></div>
        <div class="oauth-buttons">
          <button
            v-for="provider in ssoProviders"
            :key="provider.provider"
            class="btn btn-oauth"
            @click="handleSsoLogin(provider.provider)"
          >
            {{ provider.name }}
          </button>
        </div>
      </div>

      <div class="text-center mt-16">
        <router-link to="/forgot-password">忘记密码？</router-link>
        <template v-if="allowRegister">
          &nbsp;|&nbsp;
          <router-link to="/register">注册账号</router-link>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()
const loading = ref(false)
const error = ref('')
const form = reactive({ email: '', password: '' })

// 租户品牌信息
const tenant = ref<any>(null)
const oauthProviders = ref<any[]>([])
const ssoProviders = ref<any[]>([])
const allowRegister = ref(true)

// 邮箱未验证状态
const showUnverifiedBanner = ref(false)
const resendLoading = ref(false)
const resendMsg = ref('')
const resendSuccess = ref(false)
const lastOperator = reactive({ email: '', token: '' })

// 当前域名
const currentHost = window.location.host

onMounted(async () => {
  await resolveTenant()
  await loadLoginConfig()
})

async function resolveTenant() {
  try {
    const res = await fetch(`/api/v1/tenant/resolve?domain=${encodeURIComponent(currentHost)}`)
    const data = await res.json()
    if (data.success) {
      tenant.value = data.data
    }
  } catch {
    // 租户解析失败不阻断登录
  }
}

async function loadLoginConfig() {
  try {
    const res = await fetch(`/api/v1/tenant/login-config?domain=${encodeURIComponent(currentHost)}`)
    const data = await res.json()
    if (data.success) {
      oauthProviders.value = data.data.oauth_providers || []
      ssoProviders.value = data.data.sso_providers || []
      allowRegister.value = data.data.allow_register ?? true
    }
  } catch {
    // 配置加载失败不阻断登录
  }
}

function handleOAuthLogin(provider: string) {
  // 跳转到 OAuth 授权端点
  window.location.href = `/api/v1/auth/${provider}/redirect?tenant_id=${tenant.value?.tenant_id || ''}`
}

function handleSsoLogin(provider: string) {
  const ssoName = provider.replace('sso:', '')
  window.location.href = `/api/v1/auth/sso/${ssoName}/redirect`
}

async function handleLogin() {
  loading.value = true
  error.value = ''
  showUnverifiedBanner.value = false

  try {
    const res = await fetch('/api/v1/operator-auth/login', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(form),
    })
    const data = await res.json()

    if (data.success) {
      const operator = data.data.operator || {}
      const token = data.data.auth_token || data.data.token  // 兼容字段名
      lastOperator.email = operator.email || form.email
      lastOperator.token = token

      // 检测邮箱是否已验证
      if (operator.email_verified === false) {
        // 保存 token 但不跳转，显示未验证提示
        localStorage.setItem('operator_token', token)
        localStorage.setItem('operator', JSON.stringify(operator))
        showUnverifiedBanner.value = true
        return
      }

      // 已验证，正常跳转
      localStorage.setItem('operator_token', token)
      localStorage.setItem('operator', JSON.stringify(operator))
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

async function resendVerification() {
  if (!lastOperator.email) return
  resendLoading.value = true
  resendMsg.value = ''

  try {
    const res = await fetch('/api/v1/operator-auth/resend-verification', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: lastOperator.email }),
    })
    const data = await res.json()
    if (data.success) {
      resendSuccess.value = true
      resendMsg.value = '验证邮件已重新发送，请查收邮箱'
    } else {
      resendSuccess.value = false
      resendMsg.value = data.message || '发送失败'
    }
  } catch {
    resendSuccess.value = false
    resendMsg.value = '网络错误，请稍后重试'
  } finally {
    resendLoading.value = false
  }
}

function continueAnyway() {
  // 用户选择仍然继续，跳转到 console
  window.location.href = '/console'
}
</script>

<style scoped>
.tenant-brand {
  text-align: center;
  margin-bottom: 24px;
}
.tenant-logo {
  max-height: 48px;
  margin-bottom: 12px;
}
.tenant-message {
  color: #666;
  font-size: 14px;
  margin-top: 8px;
}
.oauth-section {
  margin-top: 20px;
}
.divider {
  display: flex;
  align-items: center;
  margin: 16px 0;
  color: #999;
  font-size: 13px;
}
.divider::before,
.divider::after {
  content: '';
  flex: 1;
  border-top: 1px solid #e0e0e0;
}
.divider span {
  padding: 0 12px;
}
.oauth-buttons {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.btn-oauth {
  width: 100%;
  padding: 10px;
  border: 1px solid #d0d0d0;
  border-radius: 6px;
  background: #fff;
  cursor: pointer;
  font-size: 14px;
  transition: background 0.2s;
}
.btn-oauth:hover {
  background: #f5f5f5;
}
.msg-warning {
  background: #fff8e1;
  border: 1px solid #ffe082;
  border-radius: 4px;
  padding: 16px;
  margin-bottom: 16px;
}
.banner-title {
  font-weight: 600;
  color: #f57c00;
  margin-bottom: 4px;
}
.banner-desc {
  color: #616161;
  font-size: 13px;
  margin-bottom: 12px;
}
.banner-actions {
  display: flex;
  gap: 16px;
}
.banner-msg {
  margin-top: 8px;
  font-size: 13px;
}
.text-success { color: #2e7d32; }
.text-error { color: #c62828; }
</style>
