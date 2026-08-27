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
            <!-- 平台代开发应用授权（suite 模式，双轨之一） -->
            <div class="suite-box">
              <div class="help-title">🤝 平台代开发应用授权（推荐）</div>
              <p class="form-tip">
                企业微信自建应用的可信域名须与认证主体一致，租户自有域名无法作为平台回调域（auth.neihang.com）。
                平台已注册企微服务商（代开发模式），授权后扫码登录将优先走服务商代跑，回调域使用平台统一域；下方「自建应用」配置保留为降级备用。
              </p>
              <template v-if="suiteAuth.status === 'authorized'">
                <el-descriptions :column="2" size="small" border style="margin: 8px 0">
                  <el-descriptions-item label="Corp ID">{{ suiteAuth.corp_id }}</el-descriptions-item>
                  <el-descriptions-item label="Agent ID">{{ suiteAuth.agent_id }}</el-descriptions-item>
                  <el-descriptions-item label="授权时间">{{ suiteAuth.authorized_at || '—' }}</el-descriptions-item>
                  <el-descriptions-item label="状态"><el-tag type="success" size="small">已授权</el-tag></el-descriptions-item>
                </el-descriptions>
                <div v-if="suiteAuthPermissions.length" style="margin: 4px 0 10px">
                  <span class="form-tip" style="margin-right: 6px">已获得模板权限：</span>
                  <el-tag v-for="p in suiteAuthPermissions" :key="p.key" size="small" style="margin-right: 6px">{{ p.label }}</el-tag>
                </div>
                <!-- 应用回调链路状态（模板统一地址 + 自动带出） -->
                <div v-if="suiteAuth.callback" class="suite-callback">
                  <div class="callback-row">
                    <span class="callback-label">应用回调 URL（模板统一地址，企微自动带出）：</span>
                    <code class="callback-code">{{ suiteAuth.callback.app_callback_url }}</code>
                    <el-button link type="primary" size="small" @click="copyText(suiteAuth.callback.app_callback_url)">复制</el-button>
                  </div>
                  <div v-if="suiteAuth.callback.app_callback_url_legacy" class="callback-row" style="margin-top: 4px">
                    <span class="callback-label">备用地址（手填时使用）：</span>
                    <code class="callback-code">{{ suiteAuth.callback.app_callback_url_legacy }}</code>
                    <el-button link type="primary" size="small" @click="copyText(suiteAuth.callback.app_callback_url_legacy)">复制</el-button>
                  </div>
                  <el-alert v-if="!suiteAuth.callback.app_callback_configured" type="warning" :closable="false" show-icon style="margin-top: 6px">
                    <template #title>
                      应用回调尚未配置：请平台在<b>管理后台 → 企微服务商</b>配置模板级应用回调 Token / EncodingAESKey。一次配置后，每家企业「开始代开发应用」时企微自动带出模板的 URL / Token / EncodingAESKey，无需逐企业填写。配置完成前应用无法接收事件推送。
                    </template>
                  </el-alert>
                  <el-alert v-else type="success" :closable="false" show-icon style="margin-top: 6px">
                    <template #title>应用回调已配置，回调链路就绪。</template>
                  </el-alert>
                  <p class="form-tip">可信域名须填 <b>{{ suiteCallbackDomain }}</b>（回调 URL 的域名部分，不含 https:// 与路径）；应用主页可填 club.lanyantu.com 等终端站点（与认证无关）。</p>
                </div>
                <div style="display: flex; align-items: center; gap: 8px">
                  <el-button type="danger" plain size="small" :loading="suiteRevoking" @click="revokeSuiteAuth">解除授权</el-button>
                  <el-button link size="small" @click="fetchSuiteStatus">刷新状态</el-button>
                </div>
              </template>
              <template v-else>
                <!-- 页面内展示授权二维码（qrcode_url 需自行渲染为二维码，非图片直链） -->
                <div v-if="suiteAuthUrl" class="suite-qr-box">
                  <div class="suite-qr">
                    <QrcodeVue :value="suiteAuthUrl" :size="176" level="M" render-as="canvas" />
                  </div>
                  <p class="form-tip" style="margin: 8px 0 0; text-align: center">
                    请使用<b>企业微信</b>扫描二维码，由企业管理员确认授权；授权完成后点击「刷新状态」
                  </p>
                  <div style="display: flex; gap: 8px; justify-content: center; margin-top: 8px">
                    <el-button size="small" :loading="suiteAuthorizing" @click="startSuiteAuth">重新生成二维码</el-button>
                    <el-button size="small" @click="fetchSuiteStatus">刷新状态</el-button>
                  </div>
                  <div v-if="suiteAuthPermissions.length" class="suite-perms">
                    <div class="help-title" style="font-size: 13px">授权后将获得以下模板权限（可信域名/回调域由服务商代管，无需逐项配置）</div>
                    <div style="margin-top: 4px">
                      <el-tag v-for="p in suiteAuthPermissions" :key="p.key" size="small" style="margin-right: 6px">{{ p.label }}</el-tag>
                    </div>
                  </div>
                </div>
                <div v-else style="display: flex; align-items: center; gap: 8px; margin-top: 8px">
                  <el-button type="primary" :loading="suiteAuthorizing" @click="startSuiteAuth">使用平台代开发应用扫码授权</el-button>
                  <el-button link @click="fetchSuiteStatus">刷新状态</el-button>
                </div>
                <p v-if="suiteAuth.status === 'revoked'" class="form-tip" style="margin-top: 6px">当前状态：已解除，可重新扫码授权</p>
                <p v-if="suiteAuthHint" class="form-tip" style="margin-top: 6px">{{ suiteAuthHint }}</p>
                <p v-if="suiteAuthError" class="form-tip" style="margin-top: 6px; color: var(--el-color-danger)">{{ suiteAuthError }}</p>
              </template>
            </div>

            <div class="enable-row">
              <span>启用企业微信扫码登录</span>
              <el-switch v-model="config.wechat_work.enabled" />
            </div>

            <el-form v-if="config.wechat_work.enabled" label-width="90px" class="config-form">
              <el-form-item label="Corp ID"><el-input v-model="config.wechat_work.corp_id" placeholder="ww1234567890abcdef" /></el-form-item>
              <el-form-item label="Agent ID"><el-input v-model="config.wechat_work.agent_id" placeholder="1000001" /></el-form-item>
              <el-form-item label="Secret"><el-input v-model="config.wechat_work.secret" /></el-form-item>
              <el-form-item v-if="config.wechat_work.redirect" label="回调地址">
                <el-input :model-value="config.wechat_work.redirect" readonly />
              </el-form-item>
              <el-form-item label="域名验证文件">
                <div style="width: 100%; font-size: 12px">
                  <div style="color: var(--el-text-color-secondary); margin-bottom: 6px">企业微信设置「授权回调域/可信域名」时下发的验证文件名（如 WW_verify_xxx）。企微验证的是回调域名（{{ verifyDomain || '未配置回调域' }}），填入后系统自动在该域名根路径提供该文件</div>
                  <div v-for="f in verifyFiles" :key="f" style="display: flex; align-items: center; gap: 6px; margin-bottom: 4px">
                    <code>{{ f }}</code>
                    <a v-if="verifyDomain" :href="`https://${verifyDomain}/${f}`" target="_blank" rel="noopener">验证</a>
                    <el-button link type="danger" size="small" @click="handleRemoveVerifyFile(f)">删除</el-button>
                  </div>
                  <div style="display: flex; gap: 6px; margin-top: 4px">
                    <el-input v-model="verifyFileInput" size="small" style="max-width: 280px" placeholder="如：WW_verify_mLUxXhK2fEC6jPsB" @keyup.enter="handleAddVerifyFile" />
                    <el-button size="small" type="primary" :loading="verifyFilesSaving" @click="handleAddVerifyFile">添加</el-button>
                  </div>
                </div>
              </el-form-item>
            </el-form>

            <div class="help-box">
              <div class="help-title">📖 配置指引（企业微信管理后台）</div>
              <ol>
                <li>管理员登录 <a href="https://work.weixin.qq.com/wework_admin/" target="_blank" rel="noopener">企业微信管理后台</a> →「应用管理」→「自建」→「创建应用」。</li>
                <li>进入应用详情页，复制 <b>AgentId</b> 和 <b>Secret</b> 填入本页。</li>
                <li>「我的企业」→「企业信息」页面底部，复制 <b>企业 ID（CorpID）</b> 填入本页。</li>
                <li>应用详情页 →「企业微信授权登录」→ 设置「授权回调域」为本系统域名（即上方回调地址中的域名部分，不含 https:// 与路径）。</li>
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
import { ElMessage, ElMessageBox } from 'element-plus'
import QrcodeVue from 'qrcode.vue'
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

// ─── 平台代开发应用授权（suite 模式） ───────────────────
const suiteAuth = reactive({ status: 'pending', corp_id: '', agent_id: '', authorized_at: '', callback: null as any })
const suiteAuthorizing = ref(false)
const suiteRevoking = ref(false)
const suiteAuthError = ref('')
const suiteAuthHint = ref('')
// 授权二维码内容（qrcode_url 为授权链接，由前端渲染为二维码）与模板权限清单
const suiteAuthUrl = ref('')
const suiteAuthPermissions = ref<{ key: string; label: string }[]>([])

// 可信域名 = 应用回调 URL 的域名（企微可信域名须与回调域名一致，不含 https:// 与路径）
const suiteCallbackDomain = computed(() => {
  const url = suiteAuth.callback?.app_callback_url || ''
  try { return new URL(url).host } catch { return 'auth.neihang.com' }
})

const copyText = async (text: string) => {
  if (!text) return
  try {
    await navigator.clipboard.writeText(text)
    ElMessage.success('已复制')
  } catch {
    ElMessage.error('复制失败，请手动复制')
  }
}

const fetchSuiteStatus = async () => {
  try {
    const res = await axios.get('/api/v1/tenant/wechat-work/status')
    const data = res.data.data || {}
    Object.assign(suiteAuth, data)
    suiteAuthPermissions.value = data.permissions || []
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
    const data = res.data.data || {}
    const url = data.url
    if (!url) throw new Error('未返回授权 URL')
    // 页面内直接展示二维码（企微 qrcode_url 是授权链接，需自行渲染为二维码图片）
    suiteAuthUrl.value = url
    suiteAuthPermissions.value = data.provider?.permissions || []
    suiteAuthHint.value = '已生成授权二维码，请用企业微信扫码；授权完成后点击「刷新状态」确认。'
  } catch (e: any) {
    suiteAuthError.value = e.response?.data?.message || '生成授权二维码失败'
  } finally {
    suiteAuthorizing.value = false
  }
}

const revokeSuiteAuth = async () => {
  try {
    await ElMessageBox.confirm('确认解除平台代开发授权？解除后登录将回退自建应用配置（如有）。', '提示', { type: 'warning' })
    suiteRevoking.value = true
    await axios.post('/api/v1/tenant/wechat-work/revoke')
    ElMessage.success('已解除企微代开发授权')
    await fetchSuiteStatus()
  } catch (e: any) {
    if (e?.response) ElMessage.error(e.response.data?.message || '解除授权失败')
  } finally {
    suiteRevoking.value = false
  }
}

onMounted(() => {
  loadConfig()
  loadVerifyFiles()
  fetchSuiteStatus()
})
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
