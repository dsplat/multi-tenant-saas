<template>
  <div class="page">
    <h1 class="page-title">安全设置</h1>

    <!-- 修改密码 -->
    <div class="panel">
      <h3 class="panel-title">修改密码</h3>
      <div v-if="pwdMsg" :class="['alert', pwdSuccess ? 'alert-success' : 'alert-error']">{{ pwdMsg }}</div>
      <form @submit.prevent="changePassword">
        <div class="form-group">
          <label>当前密码</label>
          <input v-model="pwdForm.current_password" type="password" required />
        </div>
        <div class="form-group">
          <label>新密码</label>
          <input v-model="pwdForm.password" type="password" required minlength="8" />
        </div>
        <div class="form-group">
          <label>确认新密码</label>
          <input v-model="pwdForm.password_confirmation" type="password" required minlength="8" />
        </div>
        <button type="submit" class="btn btn-primary" :disabled="pwdSaving">
          {{ pwdSaving ? '修改中...' : '修改密码' }}
        </button>
      </form>
    </div>

    <!-- MFA 设备 -->
    <div class="panel">
      <h3 class="panel-title">多因素认证 (MFA)</h3>
      <div v-if="devices.length === 0" class="empty">尚未绑定 MFA 设备</div>
      <div v-else class="device-list">
        <div v-for="device in devices" :key="device.id" class="device-item">
          <span class="device-name">{{ device.name }}</span>
          <span class="device-type">{{ device.type }}</span>
          <span v-if="device.is_primary" class="badge-primary">主设备</span>
        </div>
      </div>
    </div>

    <!-- 活跃会话 -->
    <div class="panel">
      <h3 class="panel-title">活跃会话</h3>
      <div v-if="sessions.length === 0" class="empty">无活跃会话</div>
      <div v-else class="session-list">
        <div v-for="session in sessions" :key="session.id" class="session-item">
          <div class="session-info">
            <span>{{ session.ip_address || '未知 IP' }}</span>
            <span class="session-agent">{{ session.user_agent }}</span>
          </div>
          <span v-if="session.is_current" class="badge-current">当前</span>
          <button v-else class="btn-sm btn-danger" @click="revoke(session.id)">撤销</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, reactive, ref } from 'vue'

const pwdSaving = ref(false)
const pwdMsg = ref('')
const pwdSuccess = ref(false)
const pwdForm = reactive({ current_password: '', password: '', password_confirmation: '' })
const devices = ref<any[]>([])
const sessions = ref<any[]>([])

onMounted(async () => {
  const token = localStorage.getItem('user_token')
  const headers = { Authorization: `Bearer ${token}` }

  try {
    const res = await fetch('/api/v1/mfa/devices', { headers })
    const data = await res.json()
    if (data.success) devices.value = data.data || []
  } catch {}

  try {
    const res = await fetch('/api/v1/mfa/sessions', { headers })
    const data = await res.json()
    if (data.success) sessions.value = data.data || []
  } catch {}
})

async function changePassword() {
  pwdSaving.value = true
  pwdMsg.value = ''
  try {
    const token = localStorage.getItem('user_token')
    const res = await fetch('/api/v1/auth/password', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify(pwdForm),
    })
    const data = await res.json()
    pwdSuccess.value = data.success
    pwdMsg.value = data.message || (data.success ? '密码修改成功' : '修改失败')
    if (data.success) {
      pwdForm.current_password = ''
      pwdForm.password = ''
      pwdForm.password_confirmation = ''
    }
  } catch {
    pwdSuccess.value = false
    pwdMsg.value = '网络错误'
  } finally {
    pwdSaving.value = false
  }
}

async function revoke(id: number) {
  const token = localStorage.getItem('user_token')
  try {
    await fetch(`/api/v1/mfa/sessions/${id}`, {
      method: 'DELETE',
      headers: { Authorization: `Bearer ${token}` },
    })
    sessions.value = sessions.value.filter(s => s.id !== id)
  } catch {}
}
</script>

<style scoped>
.page-title { font-size: 24px; margin-bottom: 24px; }
.panel { background: #fff; border-radius: 8px; padding: 24px; margin-bottom: 20px; max-width: 560px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
.panel-title { font-size: 16px; margin-bottom: 16px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 6px; }
.form-group input { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
.btn { padding: 10px 20px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; }
.btn-primary { background: #1976d2; color: #fff; }
.btn-primary:disabled { opacity: 0.6; }
.btn-sm { padding: 4px 10px; font-size: 12px; border: none; border-radius: 4px; cursor: pointer; }
.btn-danger { background: #e53935; color: #fff; }
.alert { padding: 10px 14px; border-radius: 6px; margin-bottom: 14px; font-size: 14px; }
.alert-success { background: #e8f5e9; color: #2e7d32; }
.alert-error { background: #fbe9e7; color: #c62828; }
.empty { color: #999; font-size: 14px; }
.device-item, .session-item { display: flex; align-items: center; gap: 12px; padding: 10px 0; border-bottom: 1px solid #f0f0f0; font-size: 14px; }
.device-type { color: #999; font-size: 12px; }
.badge-primary { background: #e3f2fd; color: #1565c0; font-size: 11px; padding: 2px 8px; border-radius: 4px; }
.badge-current { background: #e8f5e9; color: #2e7d32; font-size: 11px; padding: 2px 8px; border-radius: 4px; }
.session-info { flex: 1; display: flex; flex-direction: column; }
.session-agent { font-size: 12px; color: #999; }
</style>
