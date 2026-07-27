<template>
  <div class="page">
    <div class="page-header"><h2>第三方登录配置</h2></div>

    <el-card shadow="never" style="max-width: 640px">
      <div class="config-section">
        <!-- 认证中心（Delegated IdP）：启用后与其他登录方式互斥 -->
        <el-card shadow="never" class="config-card config-card--idp" :class="{ 'config-card--active': config.idp.enabled }">
          <template #header>
            <div class="config-header">
              <div>
                <span style="font-size: 15px; font-weight: 500">公司认证中心（IdP）</span>
                <el-tag v-if="config.idp.enabled" type="warning" size="small" style="margin-left: 8px">互斥模式</el-tag>
              </div>
              <el-switch v-model="config.idp.enabled" />
            </div>
          </template>

          <el-alert
            v-if="config.idp.enabled"
            type="warning"
            :closable="false"
            show-icon
            style="margin-bottom: 16px"
          >
            <template #title>
              启用后，邮箱/短信登录及下方所有第三方登录将<b>全部关闭</b>，用户仅能通过认证中心登录。
            </template>
          </el-alert>

          <el-form v-if="config.idp.enabled" label-width="110px" style="max-width: 520px">
            <el-form-item label="认证中心地址">
              <el-input v-model="config.idp.base_url" placeholder="https://id.example.com" />
            </el-form-item>
            <el-form-item label="协议版本">
              <el-select v-model="config.idp.protocol" style="width: 100%">
                <el-option label="标准协议（authorization_code）" value="standard" />
                <el-option label="兼容模式（JWT 直传）" value="legacy" />
              </el-select>
            </el-form-item>
            <el-form-item label="Client ID">
              <el-input v-model="config.idp.client_id" placeholder="scrm_prod" />
            </el-form-item>
            <el-form-item label="Client Secret">
              <el-input v-model="config.idp.client_secret" type="password" show-password placeholder="******" />
            </el-form-item>
            <el-form-item label="前往登录路径">
              <el-input
                v-model="config.idp.login_path"
                :placeholder="config.idp.protocol === 'standard' ? '默认 /authorize' : '默认 /login/{provider}，如 /login/wechat'"
              />
              <div class="form-tip">相对认证中心地址的路径，留空使用协议默认值</div>
            </el-form-item>
            <el-form-item label="回跳地址">
              <el-input
                v-model="config.idp.redirect_uri"
                :placeholder="config.idp.redirect_uri_default || 'https://<租户域名>/api/v1/auth/{provider}/callback'"
              />
              <div class="form-tip">认证完成后回调本系统的地址，留空自动按租户域名推导；{provider} 为占位符</div>
            </el-form-item>
            <el-form-item label="字段映射">
              <el-input
                v-model="config.idp.field_mapping"
                type="textarea"
                :rows="3"
                placeholder='可选，JSON 格式。如 {"phone": "mobile"}'
              />
            </el-form-item>
          </el-form>
        </el-card>

        <!-- 企业微信（扫码登录） -->
        <el-card shadow="never" class="config-card" :class="{ 'config-card--disabled': config.idp.enabled }">
          <template #header>
            <div class="config-header">
              <span style="font-size: 15px; font-weight: 500">企业微信</span>
              <el-switch v-model="config.wechat_work.enabled" :disabled="config.idp.enabled" />
            </div>
          </template>
          <el-form v-if="config.wechat_work.enabled && !config.idp.enabled" label-width="90px" style="max-width: 500px">
            <el-form-item label="Corp ID"><el-input v-model="config.wechat_work.corp_id" placeholder="ww1234567890abcdef" /></el-form-item>
            <el-form-item label="Agent ID"><el-input v-model="config.wechat_work.agent_id" placeholder="1000001" /></el-form-item>
            <el-form-item label="Secret"><el-input v-model="config.wechat_work.secret" type="password" show-password placeholder="******" /></el-form-item>
            <el-form-item v-if="config.wechat_work.redirect" label="回调地址">
              <el-input :model-value="config.wechat_work.redirect" readonly />
              <div class="form-tip">请在企业微信应用后台将此域名配置为「授权回调域」，并将服务器出口 IP 加入「企业可信 IP」</div>
            </el-form-item>
          </el-form>
        </el-card>

        <!-- 微信（开放平台扫码 / 公众号网页授权） -->
        <el-card shadow="never" class="config-card" :class="{ 'config-card--disabled': config.idp.enabled }">
          <template #header>
            <div class="config-header">
              <span style="font-size: 15px; font-weight: 500">微信</span>
              <el-switch v-model="config.wechat.enabled" :disabled="config.idp.enabled" />
            </div>
          </template>
          <el-form v-if="config.wechat.enabled && !config.idp.enabled" label-width="90px" style="max-width: 500px">
            <el-form-item label="AppID"><el-input v-model="config.wechat.client_id" placeholder="wx1234567890abcdef" /></el-form-item>
            <el-form-item label="AppSecret"><el-input v-model="config.wechat.client_secret" type="password" show-password placeholder="******" /></el-form-item>
            <el-form-item v-if="config.wechat.redirect" label="回调地址">
              <el-input :model-value="config.wechat.redirect" readonly />
              <div class="form-tip">请在微信开放平台/公众号后台配置此授权回调域名</div>
            </el-form-item>
          </el-form>
        </el-card>

        <!-- 钉钉 -->
        <el-card shadow="never" class="config-card" :class="{ 'config-card--disabled': config.idp.enabled }">
          <template #header>
            <div class="config-header">
              <span style="font-size: 15px; font-weight: 500">钉钉</span>
              <el-switch v-model="config.dingtalk.enabled" :disabled="config.idp.enabled" />
            </div>
          </template>
          <el-form v-if="config.dingtalk.enabled && !config.idp.enabled" label-width="90px" style="max-width: 500px">
            <el-form-item label="App Key"><el-input v-model="config.dingtalk.client_id" /></el-form-item>
            <el-form-item label="App Secret"><el-input v-model="config.dingtalk.client_secret" type="password" show-password placeholder="******" /></el-form-item>
          </el-form>
        </el-card>

        <!-- 飞书 -->
        <el-card shadow="never" class="config-card" :class="{ 'config-card--disabled': config.idp.enabled }">
          <template #header>
            <div class="config-header">
              <span style="font-size: 15px; font-weight: 500">飞书</span>
              <el-switch v-model="config.feishu.enabled" :disabled="config.idp.enabled" />
            </div>
          </template>
          <el-form v-if="config.feishu.enabled && !config.idp.enabled" label-width="90px" style="max-width: 500px">
            <el-form-item label="App ID"><el-input v-model="config.feishu.client_id" /></el-form-item>
            <el-form-item label="App Secret"><el-input v-model="config.feishu.client_secret" type="password" show-password placeholder="******" /></el-form-item>
          </el-form>
        </el-card>
      </div>

      <el-button type="primary" :loading="saving" style="margin-top: 16px" @click="handleSave">保存配置</el-button>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'

const saving = ref(false)
const config = reactive({
  idp: { enabled: false, base_url: '', protocol: 'standard', client_id: '', client_secret: '', login_path: '', redirect_uri: '', redirect_uri_default: '', field_mapping: '' },
  wechat_work: { enabled: false, corp_id: '', agent_id: '', secret: '', redirect: '' },
  wechat: { enabled: false, client_id: '', client_secret: '', redirect: '' },
  dingtalk: { enabled: false, client_id: '', client_secret: '' },
  feishu: { enabled: false, client_id: '', client_secret: '' },
})

const loadConfig = async () => {
  try {
    const res = await axios.get('/api/v1/tenant/auth/oauth/config')
    const data = res.data.data || res.data
    if (data.idp) Object.assign(config.idp, data.idp)
    for (const key of ['wechat_work', 'wechat', 'dingtalk', 'feishu'] as const) {
      if (data[key]) {
        Object.assign(config[key], data[key])
        config[key].enabled = !!data[key].configured
      }
    }
  } catch {}
}

const handleSave = async () => {
  // 字段映射 JSON 校验
  if (config.idp.enabled && config.idp.field_mapping.trim() !== '') {
    try {
      JSON.parse(config.idp.field_mapping)
    } catch {
      ElMessage.error('字段映射必须是合法的 JSON')
      return
    }
  }

  saving.value = true
  try {
    // IdP 始终保存（enabled 开关映射 oauth_mode）
    const { redirect_uri_default: _omit, ...idpPayload } = config.idp
    await axios.put('/api/v1/tenant/auth/oauth/idp', idpPayload)

    // 各直连提供商：仅保存已开启的卡片（'********' 遮罩由后端跳过）
    if (config.wechat_work.enabled) {
      const { corp_id, agent_id, secret } = config.wechat_work
      await axios.put('/api/v1/tenant/auth/oauth/wechat_work', { corp_id, agent_id, secret })
    }
    if (config.wechat.enabled) {
      const { client_id, client_secret } = config.wechat
      await axios.put('/api/v1/tenant/auth/oauth/wechat', { client_id, client_secret })
    }
    if (config.dingtalk.enabled) {
      const { client_id, client_secret } = config.dingtalk
      await axios.put('/api/v1/tenant/auth/oauth/dingtalk', { client_id, client_secret })
    }
    if (config.feishu.enabled) {
      const { client_id, client_secret } = config.feishu
      await axios.put('/api/v1/tenant/auth/oauth/feishu', { client_id, client_secret })
    }
    ElMessage.success('保存成功')
  } catch {
    ElMessage.error('保存失败')
  } finally {
    saving.value = false
  }
}

onMounted(loadConfig)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.config-section { display: flex; flex-direction: column; gap: 16px; }
.config-header { display: flex; justify-content: space-between; align-items: center; }
.config-card { border: 1px solid var(--el-border-color); transition: opacity 0.2s; }
.config-card--idp.config-card--active { border-color: var(--el-color-warning); }
.config-card--disabled { opacity: 0.5; pointer-events: none; }
.form-tip { font-size: 12px; color: var(--el-text-color-secondary); line-height: 1.5; margin-top: 4px; }
</style>
