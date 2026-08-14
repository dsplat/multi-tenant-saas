<template>
  <div class="settings-page">
    <div class="page-header"><h2>租户设置</h2></div>

    <div class="panel">
      <div class="tabs">
        <button :class="['tab-btn', { active: activeTab === 'mail' }]" @click="activeTab = 'mail'">邮件配置</button>
        <button :class="['tab-btn', { active: activeTab === 'auth' }]" @click="activeTab = 'auth'">认证配置</button>
        <button :class="['tab-btn', { active: activeTab === 'registration' }]" @click="activeTab = 'registration'">注册配置</button>
        <button :class="['tab-btn', { active: activeTab === 'branding' }]" @click="activeTab = 'branding'">品牌设置</button>
        <button :class="['tab-btn', { active: activeTab === 'domain' }]" @click="activeTab = 'domain'">域名设置</button>
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

        <form v-if="activeTab === 'branding'" @submit.prevent="handleSaveProfile">
          <div class="form-group">
            <label>团队名称</label>
            <input v-model="profile.name" />
          </div>
          <div class="form-group">
            <label>团队介绍</label>
            <textarea v-model="profile.description" rows="3" maxlength="1000" placeholder="一句话介绍你的团队，将展示在前台登录页"></textarea>
          </div>
          <div class="form-group">
            <label>Logo URL</label>
            <input v-model="profile.logo" placeholder="https://..." />
            <img v-if="profile.logo" :src="profile.logo" style="width: 36px; height: 36px; object-fit: contain; margin-top: 8px; border: 1px solid #eee; border-radius: 4px" />
          </div>
          <div class="form-group">
            <label>主色调</label>
            <div style="display: flex; gap: 8px">
              <input v-model="profile.primary_color" type="color" style="width: 48px; padding: 2px" />
              <input v-model="profile.primary_color" placeholder="#1890ff" />
            </div>
          </div>
          <div class="form-group">
            <label>辅助色</label>
            <div style="display: flex; gap: 8px">
              <input v-model="profile.secondary_color" type="color" style="width: 48px; padding: 2px" />
              <input v-model="profile.secondary_color" placeholder="#666666" />
            </div>
          </div>
          <div class="form-group">
            <label>登录页欢迎语</label>
            <input v-model="profile.login_page_message" maxlength="500" placeholder="展示在前台登录页的欢迎文案" />
          </div>
          <button type="submit" class="primary-btn" :disabled="saving">{{ saving ? '保存中...' : '保存配置' }}</button>
        </form>

        <div v-if="activeTab === 'domain'">
          <div class="form-group">
            <label>二级域名（slug）</label>
            <div style="display: flex; gap: 8px">
              <input v-model="slug" placeholder="小写字母/数字/短横线" />
              <button class="secondary-btn" :disabled="slugChecking" @click="handleCheckSlug">{{ slugChecking ? '检查中...' : '检查可用性' }}</button>
              <button class="primary-btn" :disabled="slugSaving" @click="handleSaveSlug">{{ slugSaving ? '保存中...' : '保存' }}</button>
            </div>
            <div v-if="slugCheckMsg" :style="{ color: slugAvailable ? '#52c41a' : '#f5222d', fontSize: '12px', marginTop: '6px' }">{{ slugCheckMsg }}</div>
            <div style="font-size: 12px; color: #999; margin-top: 6px">
              保存后前台访问地址为
              <a v-if="slug && domainInfo.wildcard_base" :href="`https://${slug}.${domainInfo.wildcard_base}`" target="_blank" rel="noopener">https://{{ slug }}.{{ domainInfo.wildcard_base }}</a>
              <span v-else>https://&lt;二级域名&gt;.平台域名</span>
            </div>
            <div v-if="isDefaultSlug" style="font-size: 12px; color: #e6a23c; margin-top: 4px">当前为系统自动分配的二级域名（t- 前缀为系统保留），可随时自定义替换</div>
          </div>

          <hr />

          <div style="background: #f0f7ff; border: 1px solid #d6e4ff; border-radius: 6px; padding: 10px 14px; margin-bottom: 16px; font-size: 13px">
            <b>绑定前请确认：</b>
            <ol style="margin: 4px 0 0; padding-left: 18px; line-height: 1.9">
              <li>域名必须已完成 <b>ICP 备案</b>，未备案域名无法绑定</li>
              <li>在域名服务商处将域名通过 <b>CNAME</b> 解析指向 <code>{{ domainInfo.cname_target || 'app.平台域名' }}</code></li>
              <li>提交绑定并完成归属验证后，由平台审核生效</li>
            </ol>
          </div>

          <div class="form-group">
            <label>自定义域名 — 当前状态</label>
            <div v-if="domainInfo.domain">{{ domainStatusLabel }}：{{ domainInfo.domain }}</div>
            <div v-else style="color: #999">尚未配置自定义域名</div>
          </div>
          <div class="form-group">
            <label>绑定域名</label>
            <div style="display: flex; gap: 8px">
              <input v-model="newDomain" placeholder="如：app.example.com" />
              <button class="primary-btn" :disabled="domainSaving" @click="handleSaveDomain">{{ domainSaving ? '提交中...' : '提交绑定' }}</button>
            </div>
          </div>

          <template v-if="domainInfo.domain">
            <div class="form-group">
              <label>归属验证</label>
              <div style="display: flex; gap: 8px">
                <button class="primary-btn" :disabled="verifying" @click="handleVerify">{{ verifying ? '验证中...' : '验证域名归属' }}</button>
                <button class="secondary-btn" :disabled="tokenGenning" @click="handleGenToken">{{ tokenGenning ? '生成中...' : '重新生成验证文件' }}</button>
              </div>
            </div>
            <div v-if="verifyInfo.file_path" class="form-group" style="font-size: 12px; line-height: 2">
              <div>在域名根目录创建文件：<code>{{ verifyInfo.file_path }}</code></div>
              <div>文件内容：<code>{{ verifyInfo.file_content }}</code></div>
              <div v-if="verifyInfo.verify_url">验证地址：<a :href="verifyInfo.verify_url" target="_blank" rel="noopener">{{ verifyInfo.verify_url }}</a></div>
              <div>剩余尝试次数：{{ verifyAttemptsLeft }} / {{ verifyInfo.max_attempts ?? 5 }}</div>
            </div>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import axios from 'axios'

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

const getTenantId = () => localStorage.getItem('console_tenant_id')

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
    if (!slug.value) slug.value = data.slug || ''
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
    alert('品牌配置保存成功')
  } catch (e) {
    alert(errMsg(e))
  } finally {
    saving.value = false
  }
}

// ─── 域名设置（slug + 自定义域名） ────────────────────────
const slug = ref('')
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
    alert(res.data.message || '二级域名保存成功')
  } catch (e) {
    alert(errMsg(e))
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

const verifyAttemptsLeft = computed(() =>
  Math.max(0, (verifyInfo.max_attempts ?? 5) - (verifyInfo.attempts ?? 0)))

// t- 前缀为 SlugService 自动分配的系统默认二级域名（AUTO_PREFIX）
const isDefaultSlug = computed(() => /^t-[a-z0-9]+$/.test(slug.value))

const fetchDomainInfo = async () => {
  try {
    const res = await axios.get(`/api/v1/tenant/${getTenantId()}/domain`)
    const data = res.data.data || {}
    Object.assign(domainInfo, data)
    if (domainInfo.domain) newDomain.value = domainInfo.domain
    await fetchVerifyInfo()
  } catch {}
}

const fetchVerifyInfo = async () => {
  try {
    const res = await axios.get(`/api/v1/tenant/${getTenantId()}/domain/verify-info`)
    Object.assign(verifyInfo, res.data.data || {})
  } catch {}
}

const handleSaveDomain = async () => {
  if (!newDomain.value) return
  domainSaving.value = true
  try {
    await axios.put(`/api/v1/tenant/${getTenantId()}/domain`, { domain: newDomain.value })
    alert('域名已提交，完成归属验证后由平台审核生效')
    await fetchDomainInfo()
  } catch (e) {
    alert(errMsg(e))
  } finally {
    domainSaving.value = false
  }
}

const handleVerify = async () => {
  verifying.value = true
  try {
    const res = await axios.post(`/api/v1/tenant/${getTenantId()}/domain/verify`)
    alert(res.data.message || '域名归属验证通过')
    await fetchDomainInfo()
  } catch (e) {
    alert(errMsg(e))
    Object.assign(verifyInfo, e?.response?.data?.data || {})
  } finally {
    verifying.value = false
  }
}

const handleGenToken = async () => {
  tokenGenning.value = true
  try {
    const res = await axios.post(`/api/v1/tenant/${getTenantId()}/domain/verify-token`)
    Object.assign(verifyInfo, res.data.data || {})
    alert('验证文件已重新生成')
  } catch (e) {
    alert(errMsg(e))
  } finally {
    tokenGenning.value = false
  }
}

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
  fetchProfile()
  fetchDomainInfo()
})
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; max-width: 600px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.tabs { display: flex; gap: 0; border-bottom: 1px solid var(--border-color, #eee); margin-bottom: 20px; }
.tab-btn { padding: 10px 20px; border: none; background: none; cursor: pointer; font-size: 14px; color: var(--text-color-secondary, #666); border-bottom: 2px solid transparent; }
.tab-btn.active { color: var(--link-color); border-bottom-color: var(--link-color); }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 6px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input, .form-group textarea { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 14px; box-sizing: border-box; background: var(--bg-color, #fff); color: var(--text-color-primary, #333); }
.form-group textarea { resize: vertical; }
.form-inline { display: flex; justify-content: space-between; align-items: center; }
.form-inline label { margin-bottom: 0; }
.primary-btn { padding: 10px 24px; border: none; border-radius: 6px; background: var(--primary-color, #409eff); color: #fff; font-size: 14px; cursor: pointer; }
.primary-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.secondary-btn { padding: 10px 16px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; background: var(--bg-color, #fff); color: var(--text-color-primary, #333); font-size: 14px; cursor: pointer; white-space: nowrap; }
.secondary-btn:disabled { opacity: 0.6; cursor: not-allowed; }

.switch { position: relative; display: inline-block; width: 44px; height: 24px; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background: #ccc; border-radius: 24px; transition: 0.3s; }
.slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: 0.3s; }
input:checked + .slider { background: var(--primary-color, #409eff); }
input:checked + .slider:before { transform: translateX(20px); }
</style>
