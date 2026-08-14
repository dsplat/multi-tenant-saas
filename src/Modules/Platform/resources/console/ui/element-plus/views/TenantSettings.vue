<template>
  <div class="page">
    <div class="page-header"><h2>租户设置</h2></div>

    <el-card shadow="never" style="max-width: 720px">
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

        <el-tab-pane label="品牌设置" name="branding">
          <el-form :model="profile" label-width="140px" @submit.prevent="handleSaveProfile">
            <el-form-item label="团队名称"><el-input v-model="profile.name" /></el-form-item>
            <el-form-item label="团队介绍">
              <el-input v-model="profile.description" type="textarea" :rows="3" maxlength="1000" show-word-limit placeholder="一句话介绍你的团队，将展示在前台登录页" />
            </el-form-item>
            <el-form-item label="Logo URL">
              <div style="display: flex; gap: 12px; align-items: center; width: 100%">
                <el-input v-model="profile.logo" placeholder="https://..." style="flex: 1" />
                <img v-if="profile.logo" :src="profile.logo" style="width: 36px; height: 36px; object-fit: contain; border: 1px solid var(--el-border-color, #dcdfe6); border-radius: 4px" @error="(e: Event) => (e.target as HTMLImageElement).style.display = 'none'" />
              </div>
            </el-form-item>
            <el-form-item label="主色调">
              <div style="display: flex; gap: 8px; align-items: center">
                <el-color-picker v-model="profile.primary_color" />
                <el-input v-model="profile.primary_color" style="width: 140px" placeholder="#1890ff" />
              </div>
            </el-form-item>
            <el-form-item label="辅助色">
              <div style="display: flex; gap: 8px; align-items: center">
                <el-color-picker v-model="profile.secondary_color" />
                <el-input v-model="profile.secondary_color" style="width: 140px" placeholder="#666666" />
              </div>
            </el-form-item>
            <el-form-item label="登录页欢迎语">
              <el-input v-model="profile.login_page_message" placeholder="展示在前台登录页的欢迎文案" maxlength="500" />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="saving" @click="handleSaveProfile">保存配置</el-button>
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <el-tab-pane label="域名设置" name="domain">
          <el-form label-width="140px">
            <el-form-item label="二级域名">
              <div style="display: flex; gap: 8px; width: 100%; flex-wrap: wrap">
                <el-input v-model="slug" style="max-width: 240px" placeholder="小写字母/数字/短横线" />
                <el-button :loading="slugChecking" @click="handleCheckSlug">检查可用性</el-button>
                <el-button type="primary" :loading="slugSaving" @click="handleSaveSlug">保存</el-button>
              </div>
              <div v-if="slugCheckMsg" style="margin-top: 6px; font-size: 12px" :style="{ color: slugAvailable ? 'var(--el-color-success, #67c23a)' : 'var(--el-color-danger, #f56c6c)' }">{{ slugCheckMsg }}</div>
              <div style="margin-top: 4px; font-size: 12px; color: var(--el-text-color-secondary, #909399)">
                保存后前台访问地址为
                <template v-if="domainInfo.wildcard_base">
                  <a v-if="slug" :href="`https://${slug}.${domainInfo.wildcard_base}`" target="_blank" rel="noopener">https://{{ slug }}.{{ domainInfo.wildcard_base }}</a>
                  <span v-else>https://&lt;二级域名&gt;.{{ domainInfo.wildcard_base }}</span>
                </template>
                <span v-else>https://&lt;二级域名&gt;.平台域名</span>
              </div>
              <div v-if="isDefaultSlug" style="margin-top: 4px; font-size: 12px; color: var(--el-color-warning, #e6a23c)">当前为系统自动分配的二级域名（t- 前缀为系统保留），可随时自定义替换</div>
            </el-form-item>

            <el-divider content-position="left">自定义域名</el-divider>

            <el-alert type="info" :closable="false" style="margin-bottom: 16px">
              <template #title>绑定前请确认</template>
              <ol style="margin: 4px 0 0; padding-left: 18px; line-height: 1.9">
                <li>域名必须已完成 <b>ICP 备案</b>，未备案域名无法绑定</li>
                <li>在域名服务商处将域名通过 <b>CNAME</b> 解析指向 <code>{{ domainInfo.cname_target || 'app.平台域名' }}</code></li>
                <li>提交绑定并完成归属验证后，由平台审核生效</li>
              </ol>
            </el-alert>

            <el-form-item label="当前状态">
              <el-tag v-if="domainInfo.domain" :type="domainStatusType">{{ domainStatusLabel }}：{{ domainInfo.domain }}</el-tag>
              <span v-else style="color: var(--el-text-color-secondary, #909399)">尚未配置自定义域名</span>
            </el-form-item>
            <el-form-item label="绑定域名">
              <div style="display: flex; gap: 8px; width: 100%">
                <el-input v-model="newDomain" style="max-width: 320px" placeholder="如：app.example.com" />
                <el-button type="primary" :loading="domainSaving" @click="handleSaveDomain">提交绑定</el-button>
              </div>
            </el-form-item>

            <template v-if="domainInfo.domain">
              <el-form-item label="归属验证">
                <el-button :loading="verifying" type="primary" plain @click="handleVerify">验证域名归属</el-button>
                <el-button :loading="tokenGenning" @click="handleGenToken">重新生成验证文件</el-button>
              </el-form-item>
              <el-form-item v-if="verifyInfo.file_path" label="验证文件">
                <div style="font-size: 12px; line-height: 2">
                  <div>在域名根目录创建文件：<code>{{ verifyInfo.file_path }}</code></div>
                  <div>文件内容：<code>{{ verifyInfo.file_content }}</code></div>
                  <div v-if="verifyInfo.verify_url">验证地址：<a :href="verifyInfo.verify_url" target="_blank" rel="noopener">{{ verifyInfo.verify_url }}</a></div>
                  <div>剩余尝试次数：{{ verifyAttemptsLeft }} / {{ verifyInfo.max_attempts ?? 5 }}</div>
                </div>
              </el-form-item>
            </template>
          </el-form>
        </el-tab-pane>
      </el-tabs>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted, watch } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'
import { useUserStore } from '@stores/user'

const userStore = useUserStore()
const activeTab = ref('mail')
const saving = ref(false)

// 后端错误消息提取（Laravel 422 message 可能为字符串或字段数组）
const errMsg = (e: any): string => {
  const m = e?.response?.data?.message
  if (typeof m === 'string') return m
  if (m && typeof m === 'object') return Object.values(m).flat().join('；')
  return '操作失败'
}

const mail = reactive({
  host: '', port: 587, username: '', password: '', from_address: '', from_name: ''
})

const auth = reactive({
  allow_phone_login: true, allow_password_login: true, email_domain_whitelist: ''
})

const registration = reactive({
  open_registration: true, welcome_credits: 0
})

// ─── 品牌设置（租户 profile + branding） ───────────────────
const profile = reactive({
  name: '', description: '', logo: '',
  primary_color: '', secondary_color: '', login_page_message: ''
})

const fetchProfile = async () => {
  try {
    const res = await axios.get('/api/v1/tenant/profile')
    const data = res.data.data || res.data
    if (!data) return
    profile.name = data.name || ''
    profile.description = data.description || ''
    profile.logo = data.logo || ''
    const branding = data.branding || {}
    profile.primary_color = branding.primary_color || ''
    profile.secondary_color = branding.secondary_color || ''
    profile.login_page_message = branding.login_page_message || ''
    if (!slug.value) slug.value = (data.slug || '').toLowerCase()
  } catch {}
}

const handleSaveProfile = async () => {
  saving.value = true
  try {
    await axios.put('/api/v1/tenant/profile', {
      name: profile.name,
      description: profile.description || null,
      logo: profile.logo || null,
      branding: {
        primary_color: profile.primary_color || null,
        secondary_color: profile.secondary_color || null,
        login_page_message: profile.login_page_message || null,
      },
    })
    ElMessage.success('品牌配置保存成功')
  } catch (e) {
    ElMessage.error(errMsg(e))
  } finally {
    saving.value = false
  }
}

// ─── 域名设置（slug + 自定义域名） ────────────────────────
const slug = ref('')
// slug 只允许小写字母/数字/短横线，输入时实时归一化（兼容存量大小写混合 slug）
watch(slug, (v) => {
  const lower = v.toLowerCase()
  if (lower !== v) slug.value = lower
})
const slugChecking = ref(false)
const slugSaving = ref(false)
const slugAvailable = ref(false)
const slugCheckMsg = ref('')

const slugReasonMap: Record<string, string> = {
  invalid_format: '格式不合法（仅小写字母/数字/短横线，不以短横线开头或结尾）',
  too_short: '长度太短（至少 3 个字符）',
  reserved_prefix: '保留前缀，不可使用',
  blacklisted: '该标识为保留词，不可使用',
  taken: '该标识已被其他团队占用',
  ai_risk: '该标识存在风险，请更换',
}

const handleCheckSlug = async () => {
  if (!slug.value) return
  slugChecking.value = true
  try {
    const res = await axios.get('/api/v1/tenant/slug/check', { params: { slug: slug.value } })
    const data = res.data.data || {}
    slugAvailable.value = !!data.available
    slugCheckMsg.value = data.available ? '✓ 该标识可用' : (slugReasonMap[data.reason] || `不可用（${data.reason}）`)
  } catch (e) {
    slugAvailable.value = false
    slugCheckMsg.value = errMsg(e)
  } finally {
    slugChecking.value = false
  }
}

const handleSaveSlug = async () => {
  if (!slug.value) return
  slugSaving.value = true
  try {
    const res = await axios.put('/api/v1/tenant/slug', { slug: slug.value })
    ElMessage.success(res.data.message || '二级域名保存成功')
  } catch (e) {
    ElMessage.error(errMsg(e))
  } finally {
    slugSaving.value = false
  }
}

const domainInfo = reactive<Record<string, any>>({ domain: null, domain_status: null })
const newDomain = ref('')
const domainSaving = ref(false)
const verifying = ref(false)
const tokenGenning = ref(false)
const verifyInfo = reactive<Record<string, any>>({})

const domainStatusLabel = computed(() => ({
  pending: '待审核', approved: '已生效', rejected: '已驳回',
} as Record<string, string>)[domainInfo.domain_status] || domainInfo.domain_status)

const domainStatusType = computed(() => ({
  approved: 'success', rejected: 'danger', pending: 'warning',
} as Record<string, string>)[domainInfo.domain_status] || 'info')

const verifyAttemptsLeft = computed(() =>
  Math.max(0, (verifyInfo.max_attempts ?? 5) - (verifyInfo.attempts ?? 0)))

// t- 前缀为 SlugService 自动分配的系统默认二级域名（AUTO_PREFIX）
const isDefaultSlug = computed(() => /^t-[a-z0-9]+$/.test(slug.value))

const fetchDomainInfo = async () => {
  try {
    const res = await axios.get(`/api/v1/tenant/${userStore.tenantId}/domain`)
    const data = res.data.data || {}
    Object.assign(domainInfo, data)
    if (domainInfo.domain) newDomain.value = domainInfo.domain
    await fetchVerifyInfo()
  } catch {}
}

const fetchVerifyInfo = async () => {
  try {
    const res = await axios.get(`/api/v1/tenant/${userStore.tenantId}/domain/verify-info`)
    Object.assign(verifyInfo, res.data.data || {})
  } catch {}
}

const handleSaveDomain = async () => {
  if (!newDomain.value) return
  domainSaving.value = true
  try {
    await axios.put(`/api/v1/tenant/${userStore.tenantId}/domain`, { domain: newDomain.value })
    ElMessage.success('域名已提交，完成归属验证后由平台审核生效')
    await fetchDomainInfo()
  } catch (e) {
    ElMessage.error(errMsg(e))
  } finally {
    domainSaving.value = false
  }
}

const handleVerify = async () => {
  verifying.value = true
  try {
    const res = await axios.post(`/api/v1/tenant/${userStore.tenantId}/domain/verify`)
    ElMessage.success(res.data.message || '域名归属验证通过')
    await fetchDomainInfo()
  } catch (e) {
    ElMessage.error(errMsg(e))
    Object.assign(verifyInfo, e?.response?.data?.data || {})
  } finally {
    verifying.value = false
  }
}

const handleGenToken = async () => {
  tokenGenning.value = true
  try {
    const res = await axios.post(`/api/v1/tenant/${userStore.tenantId}/domain/verify-token`)
    Object.assign(verifyInfo, res.data.data || {})
    ElMessage.success('验证文件已重新生成')
  } catch (e) {
    ElMessage.error(errMsg(e))
  } finally {
    tokenGenning.value = false
  }
}

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
  fetchProfile()
  fetchDomainInfo()
})
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
</style>
