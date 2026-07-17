<template>
  <div class="page">
    <div class="page-header"><h2>租户设置</h2></div>

    <el-card shadow="never" style="max-width: 600px">
      <el-tabs v-model="activeTab">
        <el-tab-pane label="邮件配置" name="mail">
          <el-form :model="mail" label-width="100px" @submit.prevent="handleSaveMail">
            <el-form-item label="SMTP 主机"><el-input v-model="mail.host" placeholder="smtp.example.com" /></el-form-item>
            <el-form-item label="SMTP 端口"><el-input v-model="mail.port" type="number" placeholder="587" /></el-form-item>
            <el-form-item label="用户名"><el-input v-model="mail.username" /></el-form-item>
            <el-form-item label="密码"><el-input v-model="mail.password" type="password" show-password placeholder="******" /></el-form-item>
            <el-form-item label="发件人地址"><el-input v-model="mail.from_address" type="email" placeholder="noreply@example.com" /></el-form-item>
            <el-form-item label="发件人名称"><el-input v-model="mail.from_name" placeholder="系统通知" /></el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="saving" @click="handleSaveMail">保存配置</el-button>
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <el-tab-pane label="认证配置" name="auth">
          <el-form :model="auth" label-width="140px" @submit.prevent="handleSaveAuth">
            <el-form-item label="允许手机号登录">
              <el-switch v-model="auth.allow_phone_login" />
            </el-form-item>
            <el-form-item label="允许密码登录">
              <el-switch v-model="auth.allow_password_login" />
            </el-form-item>
            <el-form-item label="邮箱域名白名单">
              <el-input v-model="auth.email_domain_whitelist" type="textarea" :rows="3" placeholder="每行一个域名，如：example.com" />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="saving" @click="handleSaveAuth">保存配置</el-button>
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <el-tab-pane label="注册配置" name="registration">
          <el-form :model="registration" label-width="100px" @submit.prevent="handleSaveRegistration">
            <el-form-item label="开放注册">
              <el-switch v-model="registration.open_registration" />
            </el-form-item>
            <el-form-item label="欢迎积分">
              <el-input-number v-model="registration.welcome_credits" :min="0" placeholder="0" />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="saving" @click="handleSaveRegistration">保存配置</el-button>
            </el-form-item>
          </el-form>
        </el-tab-pane>
      </el-tabs>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'
import { useUserStore } from '@stores/user'

const userStore = useUserStore()
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

const fetchMail = async () => {
  try {
    const res = await axios.get(`/api/v1/tenants/${userStore.tenantId}/settings/mail`)
    const data = res.data.data || res.data
    if (data) Object.assign(mail, data)
  } catch {}
}

const fetchAuth = async () => {
  try {
    const res = await axios.get(`/api/v1/tenants/${userStore.tenantId}/settings/auth`)
    const data = res.data.data || res.data
    if (data) Object.assign(auth, data)
  } catch {}
}

const fetchRegistration = async () => {
  try {
    const res = await axios.get(`/api/v1/tenants/${userStore.tenantId}/settings/registration`)
    const data = res.data.data || res.data
    if (data) Object.assign(registration, data)
  } catch {}
}

const handleSaveMail = async () => {
  saving.value = true
  try {
    await axios.put(`/api/v1/tenants/${userStore.tenantId}/settings/mail`, mail)
    ElMessage.success('邮件配置保存成功')
  } catch {
    ElMessage.error('保存失败')
  } finally {
    saving.value = false
  }
}

const handleSaveAuth = async () => {
  saving.value = true
  try {
    await axios.put(`/api/v1/tenants/${userStore.tenantId}/settings/auth`, auth)
    ElMessage.success('认证配置保存成功')
  } catch {
    ElMessage.error('保存失败')
  } finally {
    saving.value = false
  }
}

const handleSaveRegistration = async () => {
  saving.value = true
  try {
    await axios.put(`/api/v1/tenants/${userStore.tenantId}/settings/registration`, registration)
    ElMessage.success('注册配置保存成功')
  } catch {
    ElMessage.error('保存失败')
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
</style>
