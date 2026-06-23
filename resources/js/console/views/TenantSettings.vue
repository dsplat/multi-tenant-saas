<template>
  <div class="settings-page">
    <div class="page-header"><h2>租户设置</h2></div>

    <div class="panel">
      <div class="tabs">
        <button :class="['tab-btn', { active: activeTab === 'mail' }]" @click="activeTab = 'mail'">邮件配置</button>
        <button :class="['tab-btn', { active: activeTab === 'auth' }]" @click="activeTab = 'auth'">认证配置</button>
        <button :class="['tab-btn', { active: activeTab === 'registration' }]" @click="activeTab = 'registration'">注册配置</button>
      </div>

      <div class="tab-content">
        <form v-if="activeTab === 'mail'" @submit.prevent="handleSaveMail">
          <div class="form-group">
            <label>SMTP 主机</label>
            <input v-model="mail.host" placeholder="smtp.example.com" />
          </div>
          <div class="form-group">
            <label>SMTP 端口</label>
            <input v-model="mail.port" type="number" placeholder="587" />
          </div>
          <div class="form-group">
            <label>用户名</label>
            <input v-model="mail.username" />
          </div>
          <div class="form-group">
            <label>密码</label>
            <input v-model="mail.password" type="password" placeholder="******" />
          </div>
          <div class="form-group">
            <label>发件人地址</label>
            <input v-model="mail.from_address" type="email" placeholder="noreply@example.com" />
          </div>
          <div class="form-group">
            <label>发件人名称</label>
            <input v-model="mail.from_name" placeholder="系统通知" />
          </div>
          <button type="submit" class="primary-btn" :disabled="saving">{{ saving ? '保存中...' : '保存配置' }}</button>
        </form>

        <form v-if="activeTab === 'auth'" @submit.prevent="handleSaveAuth">
          <div class="form-group form-inline">
            <label>允许手机号登录</label>
            <label class="switch">
              <input type="checkbox" v-model="auth.allow_phone_login" />
              <span class="slider"></span>
            </label>
          </div>
          <div class="form-group form-inline">
            <label>允许密码登录</label>
            <label class="switch">
              <input type="checkbox" v-model="auth.allow_password_login" />
              <span class="slider"></span>
            </label>
          </div>
          <div class="form-group">
            <label>邮箱域名白名单</label>
            <textarea v-model="auth.email_domain_whitelist" rows="3" placeholder="每行一个域名，如：example.com"></textarea>
          </div>
          <button type="submit" class="primary-btn" :disabled="saving">{{ saving ? '保存中...' : '保存配置' }}</button>
        </form>

        <form v-if="activeTab === 'registration'" @submit.prevent="handleSaveRegistration">
          <div class="form-group form-inline">
            <label>开放注册</label>
            <label class="switch">
              <input type="checkbox" v-model="registration.open_registration" />
              <span class="slider"></span>
            </label>
          </div>
          <div class="form-group">
            <label>欢迎积分</label>
            <input v-model.number="registration.welcome_credits" type="number" min="0" placeholder="0" />
          </div>
          <button type="submit" class="primary-btn" :disabled="saving">{{ saving ? '保存中...' : '保存配置' }}</button>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'

const activeTab = ref('mail')
const saving = ref(false)

const mail = reactive({
  host: '', port: 587, username: '', password: '', from_address: '', from_name: ''
})

const auth = reactive({
  allow_phone_login: true, allow_password_login: true, email_domain_whitelist: ''
})

const registration = reactive({
  open_registration: true, welcome_credits: 0
})

const getTenantId = () => localStorage.getItem('console_tenant_id')

const fetchMail = async () => {
  try {
    const res = await axios.get(`/api/v1/tenants/${getTenantId()}/settings/mail`)
    const data = res.data.data || res.data
    if (data) Object.assign(mail, data)
  } catch {}
}

const fetchAuth = async () => {
  try {
    const res = await axios.get(`/api/v1/tenants/${getTenantId()}/settings/auth`)
    const data = res.data.data || res.data
    if (data) Object.assign(auth, data)
  } catch {}
}

const fetchRegistration = async () => {
  try {
    const res = await axios.get(`/api/v1/tenants/${getTenantId()}/settings/registration`)
    const data = res.data.data || res.data
    if (data) Object.assign(registration, data)
  } catch {}
}

const handleSaveMail = async () => {
  saving.value = true
  try {
    await axios.put(`/api/v1/tenants/${getTenantId()}/settings/mail`, mail)
    alert('邮件配置保存成功')
  } catch {
    alert('保存失败')
  } finally {
    saving.value = false
  }
}

const handleSaveAuth = async () => {
  saving.value = true
  try {
    await axios.put(`/api/v1/tenants/${getTenantId()}/settings/auth`, auth)
    alert('认证配置保存成功')
  } catch {
    alert('保存失败')
  } finally {
    saving.value = false
  }
}

const handleSaveRegistration = async () => {
  saving.value = true
  try {
    await axios.put(`/api/v1/tenants/${getTenantId()}/settings/registration`, registration)
    alert('注册配置保存成功')
  } catch {
    alert('保存失败')
  } finally {
    saving.value = false
  }
}

onMounted(() => {
  fetchMail()
  fetchAuth()
  fetchRegistration()
})
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; max-width: 600px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.tabs { display: flex; gap: 0; border-bottom: 1px solid var(--border-color, #eee); margin-bottom: 20px; }
.tab-btn { padding: 10px 20px; border: none; background: none; cursor: pointer; font-size: 14px; color: var(--text-color-secondary, #666); border-bottom: 2px solid transparent; }
.tab-btn.active { color: var(--primary-color, #409eff); border-bottom-color: var(--primary-color, #409eff); }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 6px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input, .form-group textarea { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 14px; box-sizing: border-box; background: var(--bg-color, #fff); color: var(--text-color-primary, #333); }
.form-group textarea { resize: vertical; }
.form-inline { display: flex; justify-content: space-between; align-items: center; }
.form-inline label { margin-bottom: 0; }
.primary-btn { padding: 10px 24px; border: none; border-radius: 6px; background: var(--primary-color, #409eff); color: #fff; font-size: 14px; cursor: pointer; }
.primary-btn:disabled { opacity: 0.6; cursor: not-allowed; }

.switch { position: relative; display: inline-block; width: 44px; height: 24px; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background: #ccc; border-radius: 24px; transition: 0.3s; }
.slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: 0.3s; }
input:checked + .slider { background: var(--primary-color, #409eff); }
input:checked + .slider:before { transform: translateX(20px); }
</style>
