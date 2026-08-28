<template>
  <div class="page">
    <div class="page-header"><h2>第三方登录配置</h2></div>

    <el-card shadow="never" style="max-width: 860px">
      <el-alert
        v-if="config.idp.enabled"
        type="warning"
        :closable="false"
        show-icon
        style="margin-bottom: 16px"
      >
        <template #title>
          已启用认证中心（IdP）委托模式：邮箱/短信登录及其他第三方登录将<b>全部关闭</b>，用户仅能通过认证中心登录。
        </template>
      </el-alert>

      <el-tabs v-model="activeTab">
        <!-- 认证中心（Delegated IdP）：启用后与其他登录方式互斥 -->
        <el-tab-pane name="idp">
          <template #label>
            <span>认证中心（IdP）<el-tag v-if="config.idp.enabled" type="warning" size="small" style="margin-left: 6px">已启用</el-tag></span>
          </template>

          <div class="tab-body">
            <div class="enable-row">
              <span>启用认证中心委托登录</span>
              <el-switch v-model="config.idp.enabled" />
            </div>

            <el-form v-if="config.idp.enabled" label-width="110px" class="config-form">
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
                <el-input v-model="config.idp.client_secret" />
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
        </el-tab-pane>

        <!-- 企业微信（扫码登录） -->
        <el-tab-pane name="wechat_work" :disabled="config.idp.enabled">
          <template #label>
            <span>企业微信<el-tag v-if="config.wechat_work.enabled" type="success" size="small" style="margin-left: 6px">已启用</el-tag></span>
          </template>

          <div class="tab-body">
            <div class="enable-row">
              <span>启用企业微信扫码登录</span>
              <el-switch
                v-model="config.wechat_work.enabled"
                :disabled="config.wechat_work.mode === 'suite'"
                :loading="wwSwitchSaving"
                @change="handleWechatWorkToggle"
              />
            </div>
            <p class="form-tip" style="max-width: 560px">
              <template v-if="config.wechat_work.mode === 'suite'">已通过平台代开发授权自动启用，无需开关控制。</template>
              企微接入方式（平台代开发扫码授权 / 自建应用凭证）与可信域名验证文件（WW_verify），请在「企业微信配置」页完成。
            </p>
          </div>
        </el-tab-pane>

        <!-- 微信（开放平台扫码 / 公众号网页授权） -->
        <el-tab-pane name="wechat" :disabled="config.idp.enabled">
          <template #label>
            <span>微信<el-tag v-if="config.wechat.enabled" type="success" size="small" style="margin-left: 6px">已启用</el-tag></span>
          </template>

          <div class="tab-body">
            <div class="enable-row">
              <span>启用微信登录</span>
              <el-switch v-model="config.wechat.enabled" />
            </div>

            <el-form v-if="config.wechat.enabled" label-width="90px" class="config-form">
              <el-form-item label="AppID"><el-input v-model="config.wechat.client_id" placeholder="wx1234567890abcdef" /></el-form-item>
              <el-form-item label="AppSecret"><el-input v-model="config.wechat.client_secret" /></el-form-item>
              <el-form-item v-if="config.wechat.redirect" label="回调地址">
                <el-input :model-value="config.wechat.redirect" readonly />
              </el-form-item>
              <el-form-item label="域名验证文件">
                <div style="width: 100%; font-size: 12px">
                  <div style="color: var(--el-text-color-secondary); margin-bottom: 6px">微信开放平台/公众号设置「授权回调域/网页授权域名」时下发的验证文件名（如 MP_verify_xxx）。微信验证的是回调域名（{{ verifyDomain || '未配置回调域' }}），填入后系统自动在该域名根路径提供该文件</div>
                  <div v-for="f in verifyFiles" :key="f" style="display: flex; align-items: center; gap: 6px; margin-bottom: 4px">
                    <code>{{ f }}</code>
                    <a v-if="verifyDomain" :href="`https://${verifyDomain}/${f}`" target="_blank" rel="noopener">验证</a>
                    <el-button link type="danger" size="small" @click="handleRemoveVerifyFile(f)">删除</el-button>
                  </div>
                  <div style="display: flex; gap: 6px; margin-top: 4px">
                    <el-input v-model="verifyFileInput" size="small" style="max-width: 280px" placeholder="如：MP_verify_xxxxxxxx" @keyup.enter="handleAddVerifyFile" />
                    <el-button size="small" type="primary" :loading="verifyFilesSaving" @click="handleAddVerifyFile">添加</el-button>
                  </div>
                </div>
              </el-form-item>
            </el-form>

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
        </el-tab-pane>

        <!-- 钉钉 -->
        <el-tab-pane name="dingtalk" :disabled="config.idp.enabled">
          <template #label>
            <span>钉钉<el-tag v-if="config.dingtalk.enabled" type="success" size="small" style="margin-left: 6px">已启用</el-tag></span>
          </template>

          <div class="tab-body">
            <div class="enable-row">
              <span>启用钉钉登录</span>
              <el-switch v-model="config.dingtalk.enabled" />
            </div>

            <el-form v-if="config.dingtalk.enabled" label-width="90px" class="config-form">
              <el-form-item label="Client ID"><el-input v-model="config.dingtalk.client_id" placeholder="原 AppKey" /></el-form-item>
              <el-form-item label="Client Secret"><el-input v-model="config.dingtalk.client_secret" /></el-form-item>
            </el-form>

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
        </el-tab-pane>

        <!-- 飞书 -->
        <el-tab-pane name="feishu" :disabled="config.idp.enabled">
          <template #label>
            <span>飞书<el-tag v-if="config.feishu.enabled" type="success" size="small" style="margin-left: 6px">已启用</el-tag></span>
          </template>

          <div class="tab-body">
            <div class="enable-row">
              <span>启用飞书登录</span>
              <el-switch v-model="config.feishu.enabled" />
            </div>

            <el-form v-if="config.feishu.enabled" label-width="90px" class="config-form">
              <el-form-item label="App ID"><el-input v-model="config.feishu.client_id" placeholder="cli_xxxxxxxx" /></el-form-item>
              <el-form-item label="App Secret"><el-input v-model="config.feishu.client_secret" /></el-form-item>
            </el-form>

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
        </el-tab-pane>
      </el-tabs>

      <el-button type="primary" :loading="saving" style="margin-top: 16px" @click="handleSave">保存配置</el-button>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'
import { useUserStore } from '@stores/user'

const userStore = useUserStore()

const saving = ref(false)
const activeTab = ref('idp')
const config = reactive({
  idp: { enabled: false, base_url: '', protocol: 'standard', client_id: '', client_secret: '', login_path: '', redirect_uri: '', redirect_uri_default: '', field_mapping: '' },
  wechat_work: { enabled: false, corp_id: '', agent_id: '', secret: '', redirect: '' },
  wechat: { enabled: false, client_id: '', client_secret: '', redirect: '' },
  dingtalk: { enabled: false, client_id: '', client_secret: '' },
  feishu: { enabled: false, client_id: '', client_secret: '' },
})

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
        // wechat_work 用后端 enabled（套件授权或开关）；其余用 configured 推导
        config[key].enabled = key === 'wechat_work' ? !!data[key].enabled : !!data[key].configured
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

    // 企业微信扫码登录：开关由 handleWechatWorkToggle 即时保存；凭证已迁至「企业微信配置」页，此处不再保存自建凭证
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

// ─── 域名验证文件（微信/企微回调域验证） ───────────────────
const tenantDomain = ref('')
const verifyFiles = ref<string[]>([])
const verifyFileInput = ref('')
const verifyFilesSaving = ref(false)

// 验证文件宿主域名 = 回调地址的域名（微信/企微验证的是回调域，而非租户自定义域名）；
// 未配置统一回调域时回退租户自定义域名（租户以自有域名作回调域的场景）
const verifyDomain = computed(() => {
  const url = config.wechat_work.redirect || config.wechat.redirect
  if (url) {
    try {
      return new URL(url).host
    } catch {}
  }
  return tenantDomain.value
})

const loadVerifyFiles = async () => {
  try {
    const res = await axios.get(`/api/v1/tenant/${userStore.tenantId}/domain/verify-info`)
    const data = res.data.data || {}
    tenantDomain.value = data.domain || ''
    verifyFiles.value = data.third_party_verify_files || []
  } catch {}
}

const saveVerifyFiles = async (files: string[]) => {
  verifyFilesSaving.value = true
  try {
    const res = await axios.post(`/api/v1/tenant/${userStore.tenantId}/domain/verify-files`, { files })
    const data = res.data.data || {}
    verifyFiles.value = data.third_party_verify_files || []
    return true
  } catch (e) {
    const m = e?.response?.data?.message
    ElMessage.error(typeof m === 'string' ? m : '操作失败')
    return false
  } finally {
    verifyFilesSaving.value = false
  }
}

const handleAddVerifyFile = async () => {
  const name = verifyFileInput.value.trim()
  if (!name) return
  if (verifyFiles.value.includes(name) || verifyFiles.value.includes(name + '.txt')) {
    ElMessage.warning('该验证文件已存在')
    return
  }
  const ok = await saveVerifyFiles([...verifyFiles.value, name])
  if (ok) {
    verifyFileInput.value = ''
    ElMessage.success('验证文件已添加，微信/企微/支付宝可立即校验')
  }
}

const handleRemoveVerifyFile = async (file: string) => {
  const ok = await saveVerifyFiles(verifyFiles.value.filter(f => f !== file))
  if (ok) ElMessage.success('验证文件已删除')
}

onMounted(() => {
  loadConfig()
  loadVerifyFiles()
})

const wwSwitchSaving = ref(false)
const handleWechatWorkToggle = async (enabled: boolean) => {
  wwSwitchSaving.value = true
  try {
    await axios.put('/api/v1/tenant/auth/oauth/wechat_work', { enabled })
    ElMessage.success('保存成功')
  } catch {
    ElMessage.error('保存失败')
  } finally {
    wwSwitchSaving.value = false
  }
}

</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.tab-body { padding-top: 4px; }
.enable-row { display: flex; align-items: center; justify-content: space-between; max-width: 560px; margin-bottom: 16px; font-size: 14px; }
.config-form { max-width: 560px; margin-bottom: 8px; }
.form-tip { font-size: 12px; color: var(--el-text-color-secondary); line-height: 1.5; margin-top: 4px; }
.help-box { margin-top: 8px; padding: 12px 16px; background: var(--el-fill-color-light); border-radius: 6px; font-size: 13px; line-height: 1.8; color: var(--el-text-color-regular); }
.help-title { font-weight: 600; margin: 4px 0; color: var(--el-text-color-primary); }
.help-box ol, .help-box ul { margin: 4px 0 12px; padding-left: 20px; }
.help-box code { background: var(--el-fill-color); padding: 1px 6px; border-radius: 3px; word-break: break-all; }
.help-box a { color: var(--el-color-primary); }
.suite-box { margin-bottom: 16px; padding: 12px 16px; background: var(--el-fill-color-light); border: 1px solid var(--el-border-color-lighter); border-radius: 6px; }
.suite-box .form-tip { margin-top: 6px; }
.suite-qr-box { margin-top: 10px; }
.suite-qr { display: inline-block; padding: 10px; background: #fff; border: 1px solid var(--el-border-color-lighter); border-radius: 6px; }
.suite-perms { margin-top: 10px; padding: 8px 10px; background: var(--el-fill-color-light); border-radius: 4px; }
.suite-callback { margin-top: 10px; }
.callback-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.callback-label { font-size: 12px; color: var(--el-text-color-secondary); white-space: nowrap; }
.callback-code { font-size: 12px; background: var(--el-fill-color); padding: 2px 6px; border-radius: 3px; word-break: break-all; flex: 1; min-width: 0; }
</style>
