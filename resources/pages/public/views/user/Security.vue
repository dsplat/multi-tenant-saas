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

      <!-- 添加 MFA 设备 -->
      <div class="mfa-setup">
        <button v-if="!mfaSetupActive" class="btn btn-primary" @click="startMfaSetup">添加 TOTP 设备</button>

        <div v-if="mfaSetupActive" class="mfa-setup-flow">
          <p class="hint">使用 Google Authenticator 或其他 TOTP 应用扫描以下密钥：</p>
          <div class="mfa-secret">
            <code>{{ mfaSecret }}</code>
          </div>
          <p class="hint">或手动输入以上密钥到应用中，然后输入生成的 6 位验证码：</p>
          <div class="form-group">
            <input v-model="mfaCode" type="text" maxlength="6" placeholder="输入 6 位验证码" class="mfa-input" />
          </div>
          <div v-if="mfaMsg" :class="['alert', mfaSuccess ? 'alert-success' : 'alert-error']">{{ mfaMsg }}</div>
          <div class="mfa-actions">
            <button class="btn btn-primary" :disabled="mfaConfirming" @click="confirmMfaSetup">
              {{ mfaConfirming ? '验证中...' : '确认绑定' }}
            </button>
            <button class="btn btn-cancel" @click="mfaSetupActive = false">取消</button>
          </div>
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
const mfaSetupActive = ref(false)
const mfaSecret = ref('')
const mfaCode = ref('')
const mfaMsg = ref('')
const mfaSuccess = ref(false)
const mfaConfirming = ref(false)

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

async function startMfaSetup() {
  const token = localStorage.getItem('user_token')
  mfaMsg.value = ''
  mfaCode.value = ''
  try {
    const res = await fetch('/api/v1/mfa/totp/setup', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
    })
    const data = await res.json()
    if (data.success) {
      mfaSecret.value = data.data.secret
      mfaSetupActive.value = true
    } else {
      mfaMsg.value = data.message || '初始化失败'
    }
  } catch {
    mfaMsg.value = '网络错误'
  }
}

async function confirmMfaSetup() {
  if (mfaCode.value.length !== 6) {
    mfaMsg.value = '请输入 6 位验证码'
    mfaSuccess.value = false
    return
  }
  mfaConfirming.value = true
  mfaMsg.value = ''
  const token = localStorage.getItem('user_token')
  try {
    const res = await fetch('/api/v1/mfa/totp/confirm', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify({ code: mfaCode.value, secret: mfaSecret.value, label: 'TOTP' }),
    })
    const data = await res.json()
    if (data.success) {
      mfaSuccess.value = true
      mfaMsg.value = 'MFA 设备绑定成功'
      mfaSetupActive.value = false
      // 刷新设备列表
      const listRes = await fetch('/api/v1/mfa/devices', { headers: { Authorization: `Bearer ${token}` } })
      const listData = await listRes.json()
      if (listData.success) devices.value = listData.data || []
    } else {
      mfaSuccess.value = false
      mfaMsg.value = data.message || '验证码错误'
    }
  } catch {
    mfaSuccess.value = false
    mfaMsg.value = '网络错误'
  } finally {
    mfaConfirming.value = false
  }
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
.mfa-setup { margin-top: 16px; }
.mfa-setup-flow { margin-top: 12px; padding: 16px; background: #f9f9f9; border-radius: 8px; }
.mfa-secret { margin: 12px 0; padding: 12px; background: #fff; border: 1px dashed #ccc; border-radius: 6px; text-align: center; }
.mfa-secret code { font-size: 16px; letter-spacing: 2px; word-break: break-all; }
.mfa-input { width: 160px; text-align: center; font-size: 18px; letter-spacing: 4px; }
.mfa-actions { display: flex; gap: 12px; margin-top: 12px; }
.btn-cancel { background: #eee; color: #333; }
.hint { font-size: 13px; color: #666; margin-bottom: 8px; }
</style>
