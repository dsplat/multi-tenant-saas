<template>
  <div class="oauth-page">
    <div class="page-header"><h2>第三方登录配置</h2></div>

    <div class="panel">
      <div class="config-section">
        <!-- 认证中心（Delegated IdP）：启用后与其他登录方式互斥 -->
        <div class="config-card" :class="{ 'config-card--active': config.idp.enabled }">
          <div class="config-header">
            <h4>公司认证中心（IdP）<span v-if="config.idp.enabled" class="badge">互斥模式</span></h4>
            <label class="switch">
              <input type="checkbox" v-model="config.idp.enabled" />
              <span class="slider"></span>
            </label>
          </div>
          <div class="config-body" v-if="config.idp.enabled">
            <div class="alert-warning">启用后，邮箱/短信登录及下方所有第三方登录将全部关闭，用户仅能通过认证中心登录。</div>
            <div class="form-group">
              <label>认证中心地址</label>
              <input v-model="config.idp.base_url" placeholder="https://id.example.com" />
            </div>
            <div class="form-group">
              <label>协议版本</label>
              <select v-model="config.idp.protocol">
                <option value="standard">标准协议（authorization_code）</option>
                <option value="legacy">兼容模式（JWT 直传）</option>
              </select>
            </div>
            <div class="form-group">
              <label>Client ID</label>
              <input v-model="config.idp.client_id" placeholder="scrm_prod" />
            </div>
            <div class="form-group">
              <label>Client Secret</label>
              <input v-model="config.idp.client_secret" type="password" placeholder="******" />
            </div>
            <div class="form-group">
              <label>前往登录路径</label>
              <input v-model="config.idp.login_path" :placeholder="config.idp.protocol === 'standard' ? '默认 /authorize' : '默认 /login/{provider}'" />
            </div>
            <div class="form-group">
              <label>回跳地址</label>
              <input v-model="config.idp.redirect_uri" :placeholder="config.idp.redirect_uri_default || 'https://<租户域名>/api/v1/auth/{provider}/callback'" />
            </div>
            <div class="form-group">
              <label>字段映射（可选，JSON）</label>
              <textarea v-model="config.idp.field_mapping" rows="3" placeholder='如 {"phone": "mobile"}'></textarea>
            </div>
          </div>
        </div>

        <!-- 企业微信 -->
        <div class="config-card" :class="{ 'config-card--disabled': config.idp.enabled }">
          <div class="config-header">
            <h4>企业微信</h4>
            <label class="switch">
              <input type="checkbox" v-model="config.wechat_work.enabled" :disabled="config.idp.enabled" />
              <span class="slider"></span>
            </label>
          </div>
          <div class="config-body" v-if="config.wechat_work.enabled && !config.idp.enabled">
            <div class="form-group">
              <label>Corp ID</label>
              <input v-model="config.wechat_work.corp_id" placeholder="ww1234567890abcdef" />
            </div>
            <div class="form-group">
              <label>Agent ID</label>
              <input v-model="config.wechat_work.agent_id" placeholder="1000001" />
            </div>
            <div class="form-group">
              <label>Secret</label>
              <input v-model="config.wechat_work.secret" type="password" placeholder="******" />
            </div>
            <div class="form-group" v-if="config.wechat_work.redirect">
              <label>回调地址（企微后台配置授权回调域 + 企业可信 IP）</label>
              <input :value="config.wechat_work.redirect" readonly />
            </div>
          </div>
        </div>

        <!-- 微信 -->
        <div class="config-card" :class="{ 'config-card--disabled': config.idp.enabled }">
          <div class="config-header">
            <h4>微信</h4>
            <label class="switch">
              <input type="checkbox" v-model="config.wechat.enabled" :disabled="config.idp.enabled" />
              <span class="slider"></span>
            </label>
          </div>
          <div class="config-body" v-if="config.wechat.enabled && !config.idp.enabled">
            <div class="form-group">
              <label>AppID</label>
              <input v-model="config.wechat.client_id" placeholder="wx1234567890abcdef" />
            </div>
            <div class="form-group">
              <label>AppSecret</label>
              <input v-model="config.wechat.client_secret" type="password" placeholder="******" />
            </div>
          </div>
        </div>

        <!-- 钉钉 -->
        <div class="config-card" :class="{ 'config-card--disabled': config.idp.enabled }">
          <div class="config-header">
            <h4>钉钉</h4>
            <label class="switch">
              <input type="checkbox" v-model="config.dingtalk.enabled" :disabled="config.idp.enabled" />
              <span class="slider"></span>
            </label>
          </div>
          <div class="config-body" v-if="config.dingtalk.enabled && !config.idp.enabled">
            <div class="form-group">
              <label>App Key</label>
              <input v-model="config.dingtalk.client_id" />
            </div>
            <div class="form-group">
              <label>App Secret</label>
              <input v-model="config.dingtalk.client_secret" type="password" placeholder="******" />
            </div>
          </div>
        </div>

        <!-- 飞书 -->
        <div class="config-card" :class="{ 'config-card--disabled': config.idp.enabled }">
          <div class="config-header">
            <h4>飞书</h4>
            <label class="switch">
              <input type="checkbox" v-model="config.feishu.enabled" :disabled="config.idp.enabled" />
              <span class="slider"></span>
            </label>
          </div>
          <div class="config-body" v-if="config.feishu.enabled && !config.idp.enabled">
            <div class="form-group">
              <label>App ID</label>
              <input v-model="config.feishu.client_id" />
            </div>
            <div class="form-group">
              <label>App Secret</label>
              <input v-model="config.feishu.client_secret" type="password" placeholder="******" />
            </div>
          </div>
        </div>

        <button class="primary-btn" @click="handleSave" :disabled="saving">{{ saving ? '保存中...' : '保存配置' }}</button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'

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
  if (config.idp.enabled && config.idp.field_mapping.trim() !== '') {
    try {
      JSON.parse(config.idp.field_mapping)
    } catch {
      alert('字段映射必须是合法的 JSON')
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
    alert('保存成功')
  } catch {
    alert('保存失败')
  } finally {
    saving.value = false
  }
}

onMounted(loadConfig)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.config-section { display: flex; flex-direction: column; gap: 16px; }
.config-card { border: 1px solid var(--border-color, #eee); border-radius: 8px; overflow: hidden; transition: opacity 0.2s; }
.config-card--active { border-color: #e6a23c; }
.config-card--disabled { opacity: 0.5; pointer-events: none; }
.config-header { display: flex; justify-content: space-between; align-items: center; padding: 16px; background: var(--fill-color, #f9f9f9); }
.config-header h4 { margin: 0; font-size: 14px; }
.badge { display: inline-block; margin-left: 8px; padding: 2px 8px; font-size: 12px; color: #e6a23c; background: #fdf6ec; border-radius: 4px; font-weight: normal; }
.alert-warning { padding: 10px 12px; margin-bottom: 12px; font-size: 13px; color: #e6a23c; background: #fdf6ec; border-radius: 6px; }
.config-body { padding: 16px; }
.form-group { margin-bottom: 12px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 12px; color: var(--text-color-secondary, #999); }
.form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 13px; box-sizing: border-box; font-family: inherit; }
.primary-btn { padding: 10px 24px; border: none; border-radius: 6px; background: var(--primary-color, #409eff); color: #fff; cursor: pointer; font-size: 14px; margin-top: 8px; }
.primary-btn:disabled { opacity: 0.6; cursor: not-allowed; }

.switch { position: relative; display: inline-block; width: 44px; height: 24px; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background: #ccc; border-radius: 24px; transition: 0.3s; }
.slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: 0.3s; }
input:checked + .slider { background: var(--primary-color, #409eff); }
input:checked + .slider:before { transform: translateX(20px); }
</style>
