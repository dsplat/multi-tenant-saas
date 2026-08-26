<template>
  <div class="oauth-page">
    <div class="page-header"><h2>第三方登录配置</h2></div>

    <div class="panel">
      <div v-if="config.idp.enabled" class="alert-warning">
        已启用认证中心（IdP）委托模式：邮箱/短信登录及其他第三方登录将全部关闭，用户仅能通过认证中心登录。
      </div>

      <!-- 横向 tab -->
      <ul class="nav nav-tabs">
        <li v-for="tab in tabs" :key="tab.key" class="nav-item">
          <button
            class="nav-link"
            :class="{ active: activeTab === tab.key, disabled: tab.key !== 'idp' && config.idp.enabled }"
            :disabled="tab.key !== 'idp' && config.idp.enabled"
            type="button"
            @click="activeTab = tab.key"
          >
            {{ tab.label }}
            <span v-if="isEnabled(tab.key)" class="badge">已启用</span>
          </button>
        </li>
      </ul>

      <!-- 认证中心（IdP） -->
      <div v-show="activeTab === 'idp'" class="tab-body">
        <div class="enable-row">
          <span>启用认证中心委托登录</span>
          <label class="switch"><input type="checkbox" v-model="config.idp.enabled" /><span class="slider"></span></label>
        </div>
        <div v-if="config.idp.enabled">
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
            <input v-model="config.idp.client_secret" />
          </div>
          <div class="form-group">
            <label>前往登录路径</label>
            <input v-model="config.idp.login_path" :placeholder="config.idp.protocol === 'standard' ? '默认 /authorize' : '默认 /login/{provider}'" />
            <div class="form-tip">相对认证中心地址的路径，留空使用协议默认值</div>
          </div>
          <div class="form-group">
            <label>回跳地址</label>
            <input v-model="config.idp.redirect_uri" :placeholder="config.idp.redirect_uri_default || 'https://<租户域名>/api/v1/auth/{provider}/callback'" />
            <div class="form-tip">认证完成后回调本系统的地址，留空自动按租户域名推导；{provider} 为占位符</div>
          </div>
          <div class="form-group">
            <label>字段映射（可选，JSON）</label>
            <textarea v-model="config.idp.field_mapping" rows="3" placeholder='如 {"phone": "mobile"}'></textarea>
          </div>
        </div>

        <div class="help-box">
          <div class="help-title">📖 配置指引</div>
          <ol>
            <li>在贵司认证中心（IdP）侧将本系统注册为 OAuth 客户端，获得 <b>Client ID</b> 与 <b>Client Secret</b> 填入本页。</li>
            <li>在认证中心侧登记回跳地址（Redirect URI）：<code>{{ config.idp.redirect_uri || config.idp.redirect_uri_default || 'https://<租户域名>/api/v1/auth/{provider}/callback' }}</code>，须与本页「回跳地址」完全一致。</li>
            <li>协议选择：认证中心支持标准 OAuth2 授权码流程选「标准协议」；仅支持签发 JWT 直传的旧系统选「兼容模式」。</li>
            <li>如认证中心返回的用户字段名与本系统不同（如手机号字段叫 mobile），在「字段映射」中以 JSON 声明。</li>
          </ol>
          <div class="help-title">🛠 常见问题排查</div>
          <ul>
            <li><b>回跳后报 state 不匹配 / redirect_uri 不合法</b>：认证中心侧登记的回跳地址与实际回调地址不一致（含 http/https、端口、路径差异）。</li>
            <li><b>标准协议换取 token 失败</b>：核对 Client Secret 是否有效、认证中心 token 端点是否为 <code>{base_url}/oauth/token</code> 规范路径。</li>
            <li><b>兼容模式 JWT 校验失败</b>：检查两侧服务器时钟偏差与签名密钥配置。</li>
            <li><b>启用后无法用邮箱登录</b>：属预期行为，委托模式与其他登录方式互斥；需恢复请关闭本开关并保存。</li>
          </ul>
        </div>
      </div>

      <!-- 企业微信 -->
      <div v-show="activeTab === 'wechat_work'" class="tab-body">
        <!-- 平台代开发应用授权（suite 模式，双轨之一） -->
        <div class="suite-box">
          <div class="help-title">🤝 平台代开发应用授权（推荐）</div>
          <p class="form-tip">
            企业微信自建应用的可信域名须与认证主体一致，租户自有域名无法作为平台回调域（auth.neihang.com）。
            平台已注册企微服务商（代开发模式），授权后扫码登录将优先走服务商代跑，回调域使用平台统一域；下方「自建应用」配置保留为降级备用。
          </p>
          <template v-if="suiteAuth.status === 'authorized'">
            <div class="form-group">
              <label>Corp ID</label>
              <input :value="suiteAuth.corp_id" readonly />
            </div>
            <div class="form-group">
              <label>Agent ID</label>
              <input :value="suiteAuth.agent_id" readonly />
            </div>
            <div class="form-tip">授权时间：{{ suiteAuth.authorized_at || '—' }}</div>
            <div style="margin-top: 10px">
              <button class="btn-primary" style="margin-top: 0" :disabled="suiteRevoking" @click="revokeSuiteAuth">{{ suiteRevoking ? '解除中...' : '解除授权' }}</button>
              <button type="button" class="suite-link" @click="fetchSuiteStatus">刷新状态</button>
            </div>
          </template>
          <template v-else>
            <div style="margin-top: 6px">
              <button class="btn-primary" style="margin-top: 0" :disabled="suiteAuthorizing" @click="startSuiteAuth">{{ suiteAuthorizing ? '跳转中...' : '使用平台代开发应用扫码授权' }}</button>
              <button type="button" class="suite-link" @click="fetchSuiteStatus">刷新状态</button>
            </div>
            <p v-if="suiteAuth.status === 'revoked'" class="form-tip" style="margin-top: 6px">当前状态：已解除，可重新扫码授权</p>
            <p v-if="suiteAuthHint" class="form-tip" style="margin-top: 6px">{{ suiteAuthHint }}</p>
            <p v-if="suiteAuthError" class="form-tip" style="margin-top: 6px; color: #dc3545">{{ suiteAuthError }}</p>
          </template>
        </div>

        <div class="enable-row">
          <span>启用企业微信扫码登录</span>
          <label class="switch"><input type="checkbox" v-model="config.wechat_work.enabled" /><span class="slider"></span></label>
        </div>
        <div v-if="config.wechat_work.enabled">
          <div class="form-group"><label>Corp ID</label><input v-model="config.wechat_work.corp_id" placeholder="ww1234567890abcdef" /></div>
          <div class="form-group"><label>Agent ID</label><input v-model="config.wechat_work.agent_id" placeholder="1000001" /></div>
          <div class="form-group"><label>Secret</label><input v-model="config.wechat_work.secret" /></div>
          <div class="form-group" v-if="config.wechat_work.redirect">
            <label>回调地址</label>
            <input :value="config.wechat_work.redirect" readonly />
          </div>
        </div>

        <div class="help-box">
          <div class="help-title">📖 配置指引（企业微信管理后台）</div>
          <ol>
            <li>管理员登录 <a href="https://work.weixin.qq.com/wework_admin/" target="_blank" rel="noopener">企业微信管理后台</a> →「应用管理」→「自建」→「创建应用」。</li>
            <li>进入应用详情页，复制 <b>AgentId</b> 和 <b>Secret</b> 填入本页。</li>
            <li>「我的企业」→「企业信息」页面底部，复制 <b>企业 ID（CorpID）</b> 填入本页。</li>
            <li>应用详情页 →「企业微信授权登录」→ 设置「授权回调域」为本系统域名（即回调地址中的域名部分，不含 https:// 与路径）。</li>
            <li>应用详情页 →「开发者接口」→「企业可信 IP」，添加本系统服务器的<b>出口 IP</b>（如不确定请联系平台方获取）。</li>
          </ol>
          <div class="help-title">🛠 常见问题排查</div>
          <ul>
            <li><b>扫码后提示 redirect_uri 域名不合法（50001）</b>：「授权回调域」未配置或与回调地址域名不一致。</li>
            <li><b>报错 60020 not allow to access from your ip</b>：服务器出口 IP 未加入「企业可信 IP」列表。</li>
            <li><b>Secret 无效（40001）</b>：填的不是该自建应用的 Secret（勿使用通讯录同步等其他 Secret）；Secret 重置后需同步更新本页。</li>
            <li><b>扫码成功但登录失败</b>：确认扫码人属于该应用的「可见范围」。</li>
          </ul>
        </div>
      </div>

      <!-- 微信 -->
      <div v-show="activeTab === 'wechat'" class="tab-body">
        <div class="enable-row">
          <span>启用微信登录</span>
          <label class="switch"><input type="checkbox" v-model="config.wechat.enabled" /><span class="slider"></span></label>
        </div>
        <div v-if="config.wechat.enabled">
          <div class="form-group"><label>AppID</label><input v-model="config.wechat.client_id" placeholder="wx1234567890abcdef" /></div>
          <div class="form-group"><label>AppSecret</label><input v-model="config.wechat.client_secret" /></div>
          <div class="form-group" v-if="config.wechat.redirect">
            <label>回调地址</label>
            <input :value="config.wechat.redirect" readonly />
          </div>
        </div>

        <div class="help-box">
          <div class="help-title">📖 配置指引（微信开放平台）</div>
          <ol>
            <li>登录 <a href="https://open.weixin.qq.com" target="_blank" rel="noopener">微信开放平台</a> →「管理中心」→「网站应用」→「创建网站应用」，提交资料等待审核通过。</li>
            <li>审核通过后，在应用详情页获取 <b>AppID</b>，并生成/查看 <b>AppSecret</b> 填入本页。</li>
            <li>应用详情 →「开发信息」→「授权回调域」，填写本系统域名（仅域名，不含 https:// 与路径）。</li>
            <li>如使用公众号网页授权（H5 内），则在 <a href="https://mp.weixin.qq.com" target="_blank" rel="noopener">公众号后台</a>「设置与开发」→「公众号设置」→「功能设置」中配置「网页授权域名」。</li>
          </ol>
          <div class="help-title">🛠 常见问题排查</div>
          <ul>
            <li><b>redirect_uri 参数错误（10003）</b>：授权回调域与回调地址域名不一致，或应用尚未审核通过。</li>
            <li><b>AppSecret 错误（40125）</b>：AppSecret 被重置后未同步更新本页。</li>
            <li><b>扫码后一直转圈</b>：网站应用与公众号是两套凭证，确认使用场景与凭证类型匹配。</li>
          </ul>
        </div>
      </div>

      <!-- 钉钉 -->
      <div v-show="activeTab === 'dingtalk'" class="tab-body">
        <div class="enable-row">
          <span>启用钉钉登录</span>
          <label class="switch"><input type="checkbox" v-model="config.dingtalk.enabled" /><span class="slider"></span></label>
        </div>
        <div v-if="config.dingtalk.enabled">
          <div class="form-group"><label>Client ID</label><input v-model="config.dingtalk.client_id" placeholder="原 AppKey" /></div>
          <div class="form-group"><label>Client Secret</label><input v-model="config.dingtalk.client_secret" /></div>
        </div>

        <div class="help-box">
          <div class="help-title">📖 配置指引（钉钉开放平台）</div>
          <ol>
            <li>登录 <a href="https://open-dev.dingtalk.com" target="_blank" rel="noopener">钉钉开发者后台</a> →「应用开发」→「创建应用」（企业自建应用）。</li>
            <li>应用「凭证与基础信息」页，复制 <b>Client ID</b>（原 AppKey）与 <b>Client Secret</b>（原 AppSecret）填入本页。</li>
            <li>「安全设置」中添加回调域名：<code>{{ callbackUrl('dingtalk') }}</code>。</li>
            <li>「权限管理」中开通「个人手机号信息」与「通讯录个人信息读权限」。</li>
          </ol>
          <div class="help-title">🛠 常见问题排查</div>
          <ul>
            <li><b>回调地址不在白名单</b>：安全设置中的回调域名与实际回调地址不一致（须含完整路径）。</li>
            <li><b>获取用户信息失败</b>：所需权限未开通或未发布应用版本。</li>
          </ul>
        </div>
      </div>

      <!-- 飞书 -->
      <div v-show="activeTab === 'feishu'" class="tab-body">
        <div class="enable-row">
          <span>启用飞书登录</span>
          <label class="switch"><input type="checkbox" v-model="config.feishu.enabled" /><span class="slider"></span></label>
        </div>
        <div v-if="config.feishu.enabled">
          <div class="form-group"><label>App ID</label><input v-model="config.feishu.client_id" placeholder="cli_xxxxxxxx" /></div>
          <div class="form-group"><label>App Secret</label><input v-model="config.feishu.client_secret" /></div>
        </div>

        <div class="help-box">
          <div class="help-title">📖 配置指引（飞书开放平台）</div>
          <ol>
            <li>登录 <a href="https://open.feishu.cn" target="_blank" rel="noopener">飞书开放平台</a> →「开发者后台」→「创建企业自建应用」。</li>
            <li>「凭证与基础信息」页复制 <b>App ID</b> 与 <b>App Secret</b> 填入本页。</li>
            <li>「安全设置」→「重定向 URL」添加：<code>{{ callbackUrl('feishu') }}</code>。</li>
            <li>「应用发布」中创建版本并提交，经企业管理员审核通过后生效。</li>
          </ol>
          <div class="help-title">🛠 常见问题排查</div>
          <ul>
            <li><b>20029 redirect_uri 请求不合法</b>：重定向 URL 未添加或不完全一致。</li>
            <li><b>扫码后无反应 / 无权限</b>：应用版本未发布或未通过管理员审核；确认用户在应用可用范围内。</li>
          </ul>
        </div>
      </div>

      <button class="btn-primary" :disabled="saving" @click="handleSave">{{ saving ? '保存中...' : '保存配置' }}</button>
      <div v-if="message" class="alert" :class="messageType === 'success' ? 'alert-success' : 'alert-danger'">{{ message }}</div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'

const saving = ref(false)
const message = ref('')
const messageType = ref('success')
const activeTab = ref('idp')

const tabs = [
  { key: 'idp', label: '认证中心（IdP）' },
  { key: 'wechat_work', label: '企业微信' },
  { key: 'wechat', label: '微信' },
  { key: 'dingtalk', label: '钉钉' },
  { key: 'feishu', label: '飞书' },
]

const config = reactive({
  idp: { enabled: false, base_url: '', protocol: 'standard', client_id: '', client_secret: '', login_path: '', redirect_uri: '', redirect_uri_default: '', field_mapping: '' },
  wechat_work: { enabled: false, corp_id: '', agent_id: '', secret: '', redirect: '' },
  wechat: { enabled: false, client_id: '', client_secret: '', redirect: '' },
  dingtalk: { enabled: false, client_id: '', client_secret: '' },
  feishu: { enabled: false, client_id: '', client_secret: '' },
})

const isEnabled = (key: string) => (config as any)[key]?.enabled

// ─── 平台代开发应用授权（suite 模式） ───────────────────
const suiteAuth = reactive({ status: 'pending', corp_id: '', agent_id: '', authorized_at: '' })
const suiteAuthorizing = ref(false)
const suiteRevoking = ref(false)
const suiteAuthError = ref('')
const suiteAuthHint = ref('')

const fetchSuiteStatus = async () => {
  try {
    const res = await axios.get('/api/v1/tenant/wechat-work/status')
    Object.assign(suiteAuth, res.data.data || {})
  } catch (e: any) {
    suiteAuthError.value = e.response?.data?.message || '查询授权状态失败'
  }
}

const startSuiteAuth = async () => {
  suiteAuthorizing.value = true
  suiteAuthError.value = ''
  suiteAuthHint.value = ''
  try {
    const res = await axios.post('/api/v1/tenant/wechat-work/authorize')
    const url = res.data.data?.url
    if (!url) throw new Error('未返回授权 URL')
    window.open(url, '_blank')
    suiteAuthHint.value = '已打开企微授权页，请完成扫码；授权完成后点击「刷新状态」确认。'
  } catch (e: any) {
    suiteAuthError.value = e.response?.data?.message || '生成授权链接失败'
  } finally {
    suiteAuthorizing.value = false
  }
}

const revokeSuiteAuth = async () => {
  if (!confirm('确认解除平台代开发授权？解除后登录将回退自建应用配置（如有）。')) return
  suiteRevoking.value = true
  try {
    await axios.post('/api/v1/tenant/wechat-work/revoke')
    message.value = '已解除企微代开发授权'
    messageType.value = 'success'
    await fetchSuiteStatus()
  } catch (e: any) {
    message.value = e.response?.data?.message || '解除授权失败'
    messageType.value = 'danger'
  } finally {
    suiteRevoking.value = false
  }
}

// 按租户域名推导指定 provider 的回调地址（帮助文案展示用）
const callbackUrl = (provider: string) => {
  const tpl = config.idp.redirect_uri_default
  if (tpl) return tpl.replace('{provider}', provider)
  return `https://${window.location.host}/api/v1/auth/${provider}/callback`
}

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
    // 默认定位到首个已启用的 provider，便于直接查看/排查
    if (!config.idp.enabled) {
      const first = (['wechat_work', 'wechat', 'dingtalk', 'feishu'] as const).find(k => config[k].enabled)
      if (first) activeTab.value = first
    }
  } catch {}
}

const handleSave = async () => {
  message.value = ''
  // 字段映射 JSON 校验
  if (config.idp.enabled && config.idp.field_mapping.trim() !== '') {
    try {
      JSON.parse(config.idp.field_mapping)
    } catch {
      message.value = '字段映射必须是合法的 JSON'
      messageType.value = 'danger'
      return
    }
  }

  saving.value = true
  try {
    // IdP 始终保存（enabled 开关映射 oauth_mode）
    const { redirect_uri_default: _omit, ...idpPayload } = config.idp
    await axios.put('/api/v1/tenant/auth/oauth/idp', idpPayload)

    // 各直连提供商：仅保存已开启的 tab
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
    message.value = '保存成功'
    messageType.value = 'success'
  } catch {
    message.value = '保存失败'
    messageType.value = 'danger'
  } finally {
    saving.value = false
  }
}

onMounted(() => { loadConfig(); fetchSuiteStatus() })
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.panel { background: #fff; border: 1px solid #dee2e6; border-radius: 6px; padding: 20px; max-width: 860px; }
.nav-tabs { margin-bottom: 16px; }
.nav-tabs .nav-link { cursor: pointer; }
.nav-tabs .nav-link.disabled { pointer-events: none; opacity: 0.5; }
.badge { background: #198754; color: #fff; font-size: 11px; padding: 2px 6px; border-radius: 4px; margin-left: 4px; }
.tab-body { padding-top: 4px; }
.enable-row { display: flex; align-items: center; justify-content: space-between; max-width: 560px; margin-bottom: 16px; font-size: 14px; }
.form-group { margin-bottom: 12px; max-width: 560px; }
.form-group label { display: block; font-size: 13px; margin-bottom: 4px; color: #495057; }
.form-group input, .form-group select, .form-group textarea { width: 100%; padding: 6px 10px; border: 1px solid #ced4da; border-radius: 4px; font-size: 14px; box-sizing: border-box; }
.form-tip { font-size: 12px; color: #6c757d; line-height: 1.5; margin-top: 4px; }
.switch { position: relative; display: inline-block; width: 40px; height: 22px; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider { position: absolute; cursor: pointer; inset: 0; background: #ccc; border-radius: 22px; transition: 0.2s; }
.slider::before { content: ''; position: absolute; width: 16px; height: 16px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: 0.2s; }
.switch input:checked + .slider { background: #0d6efd; }
.switch input:checked + .slider::before { transform: translateX(18px); }
.btn-primary { margin-top: 16px; background: #0d6efd; color: #fff; border: none; padding: 8px 20px; border-radius: 4px; cursor: pointer; }
.btn-primary:disabled { opacity: 0.6; }
.alert { margin-top: 12px; padding: 8px 12px; border-radius: 4px; font-size: 14px; }
.alert-success { background: #d1e7dd; color: #0f5132; }
.alert-danger { background: #f8d7da; color: #842029; }
.help-box { margin-top: 8px; padding: 12px 16px; background: #f8f9fa; border-radius: 6px; font-size: 13px; line-height: 1.8; color: #495057; }
.suite-box { margin-bottom: 16px; padding: 12px 16px; background: #f8f9fa; border: 1px solid #dee2e6; border-radius: 6px; }
.suite-box .form-group { margin-bottom: 8px; }
.suite-box input[readonly] { background: #e9ecef; color: #495057; }
.suite-link { background: none; border: none; color: #0d6efd; cursor: pointer; padding: 0; margin-left: 12px; font-size: 13px; }
.help-title { font-weight: 600; margin: 4px 0; color: #212529; }
.help-box ol, .help-box ul { margin: 4px 0 12px; padding-left: 20px; }
.help-box code { background: #e9ecef; padding: 1px 6px; border-radius: 3px; word-break: break-all; }
</style>
