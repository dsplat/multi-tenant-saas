<template>
  <div class="settings-page">
    <div class="page-header"><h2>系统设置</h2></div>

    <div class="tabs">
      <button v-for="tab in tabs" :key="tab.key" :class="['tab-btn', { active: activeTab === tab.key }]" @click="activeTab = tab.key">
        {{ tab.label }}
      </button>
    </div>

    <div class="panel">
      <!-- 系统配置 -->
      <form v-if="activeTab === 'system'" @submit.prevent="handleSave('system')">
        <div class="form-group">
          <label>系统名称</label>
          <input v-model="system.app_name" />
        </div>
        <div class="form-group">
          <label>系统 URL</label>
          <input v-model="system.app_url" />
        </div>
        <div class="form-group">
          <label>管理员邮箱</label>
          <input v-model="system.admin_email" type="email" />
        </div>
        <div class="form-group">
          <label>默认套餐</label>
          <select v-model="system.default_plan">
            <option value="free">免费版</option>
            <option value="pro">专业版</option>
            <option value="enterprise">企业版</option>
          </select>
        </div>
        <div class="form-group">
          <label>默认积分</label>
          <input v-model.number="system.default_credits" type="number" />
        </div>
        <div class="form-group">
          <label>平台租户 ID</label>
          <input v-model="system.platform_tenant_id" />
        </div>
        <button type="submit" class="primary-btn" :disabled="saving">保存</button>
      </form>

      <!-- 邮件配置 -->
      <form v-if="activeTab === 'mail'" @submit.prevent="handleSave('mail')">
        <div class="form-group">
          <label>邮件驱动</label>
          <select v-model="mail.driver">
            <option value="smtp">SMTP</option>
            <option value="log">仅日志</option>
          </select>
        </div>
        <div class="form-group">
          <label>SMTP 主机</label>
          <input v-model="mail.host" placeholder="smtp.example.com" />
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>端口</label>
            <input v-model.number="mail.port" type="number" />
          </div>
          <div class="form-group">
            <label>加密方式</label>
            <select v-model="mail.encryption">
              <option value="tls">TLS</option>
              <option value="ssl">SSL</option>
            </select>
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>用户名</label>
            <input v-model="mail.username" />
          </div>
          <div class="form-group">
            <label>密码</label>
            <input v-model="mail.password" type="password" placeholder="******" />
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>发件人邮箱</label>
            <input v-model="mail.from_address" type="email" />
          </div>
          <div class="form-group">
            <label>发件人名称</label>
            <input v-model="mail.from_name" />
          </div>
        </div>
        <button type="submit" class="primary-btn" :disabled="saving">保存</button>
      </form>

      <!-- 积分配置 -->
      <form v-if="activeTab === 'credit'" @submit.prevent="handleSave('credit')">
        <div class="form-group">
          <label>新用户欢迎积分</label>
          <input v-model.number="credit.welcome_credits" type="number" />
          <p class="form-hint">用户首次注册赠送的积分数量，0 表示关闭</p>
        </div>
        <div class="form-group">
          <label>老用户迁移积分</label>
          <input v-model.number="credit.mtedu_credits" type="number" />
          <p class="form-hint">馒头商学院老用户绑定手机号时额外赠送</p>
        </div>
        <div class="form-group">
          <label>积分过期天数</label>
          <input v-model.number="credit.expire_days" type="number" />
          <p class="form-hint">积分有效期（天），0 表示永不过期</p>
        </div>
        <button type="submit" class="primary-btn" :disabled="saving">保存</button>
      </form>

      <!-- Dify 配置 -->
      <form v-if="activeTab === 'dify'" @submit.prevent="handleSave('dify')">
        <div class="form-group">
          <label>Dify API 地址</label>
          <input v-model="dify.api_url" placeholder="https://api.dify.ai/v1" />
        </div>
        <div class="form-group">
          <label>API Key</label>
          <input v-model="dify.api_key" type="password" placeholder="app-xxxx" />
        </div>
        <div class="form-group">
          <label>默认工作流 ID</label>
          <input v-model="dify.default_workflow_id" />
        </div>
        <div class="form-group">
          <label>超时时间（秒）</label>
          <input v-model.number="dify.timeout" type="number" />
        </div>
        <button type="submit" class="primary-btn" :disabled="saving">保存</button>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'

const activeTab = ref('system')
const saving = ref(false)

const tabs = [
  { key: 'system', label: '系统配置' },
  { key: 'mail', label: '邮件配置' },
  { key: 'credit', label: '积分配置' },
  { key: 'dify', label: 'Dify 配置' },
]

const system = reactive({
  app_name: '',
  app_url: '',
  admin_email: '',
  default_plan: 'free',
  default_credits: 500,
  platform_tenant_id: '',
})

const mail = reactive({
  driver: 'smtp',
  host: '',
  port: 587,
  encryption: 'tls',
  username: '',
  password: '',
  from_address: '',
  from_name: '',
})

const credit = reactive({
  welcome_credits: 500,
  mtedu_credits: 200,
  expire_days: 0,
})

const dify = reactive({
  api_url: '',
  api_key: '',
  default_workflow_id: '',
  timeout: 30,
})

const loadSettings = async () => {
  try {
    const res = await axios.get('/api/v1/admin/settings')
    const data = res.data.data || {}
    if (data.system) Object.assign(system, data.system)
    if (data.mail) Object.assign(mail, data.mail)
    if (data.credit) Object.assign(credit, data.credit)
    if (data.dify) Object.assign(dify, data.dify)
  } catch {}
}

const handleSave = async (group: string) => {
  saving.value = true
  try {
    const data = group === 'system' ? system
      : group === 'mail' ? mail
      : group === 'credit' ? credit
      : dify
    await axios.put(`/api/v1/admin/settings/${group}`, data)
    alert('保存成功')
  } catch (e: any) {
    alert(e.response?.data?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

onMounted(loadSettings)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.tabs { display: flex; gap: 4px; margin-bottom: 16px; }
.tab-btn { padding: 8px 16px; border: 1px solid var(--border-color, #ddd); border-radius: 6px 6px 0 0; background: var(--fill-color, #f5f5f5); cursor: pointer; font-size: 13px; color: var(--text-color-secondary, #666); }
.tab-btn.active { background: var(--bg-color, #fff); border-bottom-color: var(--bg-color, #fff); color: var(--primary-color, #409eff); font-weight: 500; }
.panel { background: var(--bg-color, #fff); border-radius: 0 8px 8px 8px; padding: 24px; max-width: 600px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 6px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-hint { margin: 4px 0 0; font-size: 12px; color: var(--text-color-secondary, #999); }
.form-group input, .form-group select { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 14px; box-sizing: border-box; background: var(--bg-color, #fff); color: var(--text-color-primary, #333); }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.primary-btn { padding: 10px 24px; border: none; border-radius: 6px; background: var(--primary-color, #409eff); color: #fff; font-size: 14px; cursor: pointer; }
.primary-btn:disabled { opacity: 0.6; cursor: not-allowed; }
</style>
