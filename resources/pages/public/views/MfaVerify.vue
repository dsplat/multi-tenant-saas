<template>
  <div class="container">
    <div class="card">
      <h2>安全验证</h2>
      <p class="mfa-desc">您的账户启用了多因素认证，请完成以下验证。</p>

      <div v-if="error" class="msg msg-error">{{ error }}</div>

      <!-- 验证方式选择 -->
      <div v-if="availableTypes.length > 1 && !selectedType" class="mfa-types">
        <button
          v-for="type in availableTypes"
          :key="type"
          class="btn btn-outline mfa-type-btn"
          @click="selectType(type)"
        >
          {{ typeLabel(type) }}
        </button>
      </div>

      <!-- 验证码输入 -->
      <div v-if="selectedType" class="mfa-form">
        <div class="form-group">
          <label>{{ typeLabel(selectedType) }}</label>
          <div class="mfa-input-row">
            <input
              v-model="code"
              type="text"
              :maxlength="selectedType === 'totp' ? 6 : 8"
              placeholder="请输入验证码"
              class="mfa-code-input"
              @keyup.enter="handleVerify"
            />
            <button
              v-if="selectedType !== 'totp'"
              class="btn btn-link"
              @click="resendCode"
              :disabled="resendTimer > 0"
            >
              {{ resendTimer > 0 ? `${resendTimer}s` : '重新发送' }}
            </button>
          </div>
        </div>

        <button class="btn btn-primary" @click="handleVerify" :disabled="loading || !code">
          {{ loading ? '验证中...' : '验证' }}
        </button>

        <button v-if="availableTypes.length > 1" class="btn btn-link" @click="selectedType = ''">
          选择其他验证方式
        </button>
      </div>

      <div class="text-center mt-16">
        <router-link to="/login">返回登录</router-link>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'

const route = useRoute()
const router = useRouter()

const loading = ref(false)
const error = ref('')
const code = ref('')
const selectedType = ref('')
const resendTimer = ref(0)

const userId = computed(() => Number(route.query.user_id) || 0)
const availableTypes = computed(() => {
  const types = (route.query.types as string) || ''
  return types.split(',').filter(Boolean)
})

let timerInterval: ReturnType<typeof setInterval> | null = null

onMounted(() => {
  // 如果只有一种验证方式，自动选中
  if (availableTypes.value.length === 1) {
    selectType(availableTypes.value[0])
  }
})

function typeLabel(type: string): string {
  const labels: Record<string, string> = {
    totp: 'TOTP 验证器',
    email: '邮箱验证码',
    sms: '短信验证码',
  }
  return labels[type] || type
}

function selectType(type: string) {
  selectedType.value = type
  code.value = ''
  error.value = ''

  // 非 TOTP 需要发送验证码
  if (type !== 'totp') {
    resendCode()
  }
}

async function resendCode() {
  try {
    const endpoint = selectedType.value === 'email' ? '/api/v1/mfa/email/send' : '/api/v1/mfa/sms/send'
    const token = localStorage.getItem('user_token')

    await fetch(endpoint, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        ...(token ? { Authorization: `Bearer ${token}` } : {}),
      },
    })

    // 开始倒计时
    resendTimer.value = 60
    if (timerInterval) clearInterval(timerInterval)
    timerInterval = setInterval(() => {
      resendTimer.value--
      if (resendTimer.value <= 0 && timerInterval) {
        clearInterval(timerInterval)
      }
    }, 1000)
  } catch {
    error.value = '发送验证码失败'
  }
}

async function handleVerify() {
  if (!code.value || !selectedType.value) return

  loading.value = true
  error.value = ''

  try {
    const res = await fetch('/api/v1/auth/mfa/verify', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        user_id: userId.value,
        type: selectedType.value,
        code: code.value,
      }),
    })
    const data = await res.json()

    if (data.success && data.data) {
      const { token, user } = data.data
      localStorage.setItem('user_token', token)
      localStorage.setItem('user_info', JSON.stringify(user))
      window.location.href = '/dashboard'
    } else {
      error.value = data.message || '验证码错误'
    }
  } catch {
    error.value = '网络错误，请稍后重试'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.mfa-desc {
  color: #666;
  font-size: 14px;
  margin-bottom: 20px;
}
.mfa-types {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-bottom: 16px;
}
.mfa-type-btn {
  width: 100%;
  padding: 12px;
}
.mfa-input-row {
  display: flex;
  gap: 12px;
  align-items: center;
}
.mfa-code-input {
  flex: 1;
  font-size: 18px;
  letter-spacing: 4px;
  text-align: center;
  padding: 10px;
}
.mfa-form .btn {
  width: 100%;
  margin-top: 12px;
}
.btn-outline {
  border: 1px solid #d0d0d0;
  background: #fff;
  border-radius: 6px;
  cursor: pointer;
  font-size: 14px;
  transition: background 0.2s;
}
.btn-outline:hover {
  background: #f5f5f5;
}
</style>
