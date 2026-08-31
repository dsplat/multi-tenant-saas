<template>
  <div class="page">
    <div class="page-header"><h2>微信配置</h2></div>

    <!-- 双轨登录模式总览（只读，随接入模式联动） -->
    <el-card v-if="capability" shadow="never" style="max-width: 860px; margin-bottom: 16px">
      <div class="mode-overview">
        <span class="mode-overview-label">当前登录方式：</span>
        <el-tag v-if="capability.login_mode === 'component'" type="success" size="small">服务商模式（微信内 H5 网页授权）</el-tag>
        <el-tag v-else-if="capability.login_mode === 'self'" type="primary" size="small">自建应用</el-tag>
        <el-tag v-else type="info" size="small">未配置</el-tag>
        <span class="form-tip" style="margin-left: 8px">
          {{ loginModeTip }}
        </span>
      </div>
      <p v-if="!capability.provider_ready" class="form-tip" style="margin: 6px 0 0">
        平台侧尚未配置微信第三方平台组件，服务商模式暂不可用：请联系平台管理员在「管理后台 → 微信服务商」完成配置。
      </p>
    </el-card>

    <!-- 接入模式 = 最外层 Tab（选项与内容一体，互斥切换） -->
    <el-card shadow="never" style="max-width: 860px">
      <el-tabs v-model="mode" class="mode-tabs" :before-leave="guardModeSwitch">
        <!-- ── 平台公众号授权（服务商模式） ── -->
        <el-tab-pane name="component">
          <template #label>
            <span class="tab-label">平台公众号授权
              <el-tag size="small" type="success">推荐</el-tag>
              <el-tag v-if="authAuthorized" size="small" style="margin-left: 4px">使用中</el-tag>
            </span>
          </template>

          <template v-if="authAuthorized">
            <!-- 左：操作区（状态/凭证/操作/回调链路）；右：权限清单 -->
            <div class="auth-grid">
              <div class="auth-info">
                <div class="state-line">
                  <el-tag type="success" size="small">已授权</el-tag>
                  <span>公众号微信内 H5 网页授权登录<b>已自动启用</b>，无需其他配置。</span>
                </div>
                <div class="auth-row"><span class="auth-label">账号类型</span><span>{{ authTypeLabel }}</span></div>
                <div class="auth-row"><span class="auth-label">公众号名称</span><span>{{ auth.nickname || '—' }}</span></div>
                <div class="auth-row"><span class="auth-label">Authorizer AppID</span><code>{{ auth.authorizer_appid }}</code></div>
                <div class="auth-row"><span class="auth-label">授权时间</span><span>{{ auth.authorized_at || '—' }}</span></div>
                <div class="auth-actions">
                  <el-button type="danger" plain size="small" :loading="authRevoking" @click="revokeAuth">解除授权</el-button>
                  <el-button link size="small" @click="fetchStatus">刷新状态</el-button>
                </div>
                <!-- 回调链路：平台级配置的技术细节，默认折叠 -->
                <el-collapse class="adv-collapse">
                  <el-collapse-item name="callback">
                    <template #title>平台回调链路（技术细节，平台级配置）</template>
                    <div class="callback-quote">
                      <div class="callback-row">
                        <span class="callback-label">消息接收 URL</span>
                        <code class="callback-code">{{ auth.callback?.callback_url || '—' }}</code>
                        <el-button link type="primary" size="small" @click="copyText(auth.callback?.callback_url)">复制</el-button>
                      </div>
                      <div class="callback-row">
                        <span class="callback-label">授权回调 URL</span>
                        <code class="callback-code">{{ auth.callback?.authorize_callback_url || '—' }}</code>
                        <el-button link type="primary" size="small" @click="copyText(auth.callback?.authorize_callback_url)">复制</el-button>
                      </div>
                      <div class="callback-tip">
                        均为平台级配置（开放平台第三方平台），由平台管理员在「管理后台 → 微信服务商」维护；公众号无需配置网页授权域名（第三方平台代替实现）。
                      </div>
                    </div>
                  </el-collapse-item>
                </el-collapse>
              </div>
              <div class="auth-perms">
                <div class="auth-label">已获得平台权限（授权即一次性获得）</div>
                <div class="perm-list">
                  <div v-for="p in authPermissions" :key="p.key" class="perm-line">
                    <el-icon class="perm-check"><Check /></el-icon>
                    <span>{{ p.label }}</span>
                  </div>
                  <span v-if="!authPermissions.length" class="form-tip">—</span>
                </div>
              </div>
            </div>
          </template>

          <template v-else>
            <div class="auth-grid">
              <div class="auth-info">
                <div class="state-line">
                  <span>尚未授权。授权完成后公众号微信内 H5 网页授权登录<b>自动启用</b>，无需去其他页面开启开关。</span>
                </div>
                <div class="auth-actions">
                  <el-button type="primary" size="small" :loading="authStarting" :disabled="!capability?.provider_ready" @click="startAuth">
                    前往微信授权页
                  </el-button>
                  <el-button link size="small" @click="fetchStatus">刷新状态</el-button>
                </div>
                <p v-if="auth.status === 'revoked'" class="form-tip" style="margin-top: 6px">
                  当前状态：已解除本地授权，可重新授权恢复（平台配置自动复用）；如需微信侧彻底取消，请公众号管理员在「公众平台-设置与开发-第三方平台-我的授权」中取消该平台授权。
                </p>
                <p v-if="authHint" class="form-tip" style="margin-top: 6px">{{ authHint }}</p>
                <p v-if="authError" class="form-tip" style="margin-top: 6px; color: var(--el-color-danger)">{{ authError }}</p>
                <div class="help-box" style="margin-top: 10px">
                  <div class="help-title">📖 授权流程说明</div>
                  <ol>
                    <li>点击「前往微信授权页」，在<b>新窗口</b>打开微信开放平台授权页（PC 页面）。</li>
                    <li>管理员选择要授权的账号（公众号 / 小程序），勾选权限集后确认授权。</li>
                    <li>微信回跳授权成功页后关闭窗口，回到本页点「刷新状态」确认授权完成。</li>
                    <li>授权对象为<b>公众号</b>时，公众号粉丝在<b>微信内</b>打开 H5 页面即可免密登录（网页授权由第三方平台代替实现）。</li>
                    <li>授权对象为<b>小程序</b>时仅记录授权（为后续小程序登录铺路），H5 登录需使用公众号授权。</li>
                  </ol>
                </div>
              </div>
              <div class="auth-perms">
                <div class="auth-label">授权后将获得平台权限（回调域名由平台代管，无需逐项配置）</div>
                <div class="perm-list">
                  <div v-for="p in authPermissions" :key="p.key" class="perm-line">
                    <el-icon class="perm-check"><Check /></el-icon>
                    <span>{{ p.label }}</span>
                  </div>
                  <span v-if="!authPermissions.length" class="form-tip">—</span>
                </div>
              </div>
            </div>
          </template>
        </el-tab-pane>

        <!-- ── 自建应用 ── -->
        <el-tab-pane label="自建应用" name="self">
          <p class="form-tip" style="margin: 4px 0 10px">自建应用支持两种登录形态（互斥）：<b>微信内 H5 登录</b>（认证服务号网页授权）与 <b>PC 扫码登录</b>（开放平台网站应用）。与平台公众号授权互斥，仅未授权（或已解除本地授权）时可配置。</p>
          <el-form label-width="110px" class="config-form">
            <el-form-item label="登录形态">
              <el-radio-group v-model="self.oauth_mode">
                <el-radio value="h5">微信内 H5 登录（认证服务号）</el-radio>
                <el-radio value="pc">PC 扫码登录（开放平台网站应用）</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item label="AppID"><el-input v-model="self.client_id" :placeholder="self.oauth_mode === 'pc' ? '开放平台网站应用 AppID（wx 开头）' : '认证服务号 AppID（wx 开头）'" /></el-form-item>
            <el-form-item label="AppSecret"><el-input v-model="self.client_secret" placeholder="掩码表示未修改" /></el-form-item>
            <el-form-item v-if="self.redirect" label="回调地址">
              <el-input :model-value="self.redirect" readonly />
            </el-form-item>
          </el-form>
          <el-button type="primary" size="small" :loading="selfSaving" @click="saveSelf">保存自建凭证</el-button>

          <div class="help-box">
            <template v-if="self.oauth_mode === 'pc'">
              <div class="help-title">📖 配置指引（PC 扫码登录 · 微信开放平台网站应用）</div>
              <ol>
                <li>管理员登录 <a href="https://open.weixin.qq.com/" target="_blank" rel="noopener">微信开放平台</a> →「管理中心」→「网站应用」→「创建网站应用」（需开发者资质认证）。</li>
                <li>进入应用详情页，复制 <b>AppID</b> 和 <b>AppSecret</b> 填入本页。</li>
                <li>「开发信息」→ 设置「授权回调域」为下方回调地址中的域名部分（不含 https:// 与路径）。</li>
                <li>网站应用须与自建服务号绑定<b>同一开放平台账号</b>（已完成认证），否则用户切换登录形态会因 openid 不同而重新注册。</li>
                <li>保存后 PC 端访问登录页即可扫码登录。</li>
              </ol>
              <div class="help-title">🛠 常见问题排查</div>
              <ul>
                <li><b>扫码后提示 redirect_uri 域名与后台配置不一致（10003）</b>：「授权回调域」未配置或与回调地址域名不一致。</li>
                <li><b>AppSecret 无效（40013/40125）</b>：填的不是该网站应用的 AppSecret；重置后需同步更新本页。</li>
              </ul>
            </template>
            <template v-else>
              <div class="help-title">📖 配置指引（微信内 H5 登录 · 认证服务号网页授权）</div>
              <ol>
                <li>准备一个<b>已认证的服务号</b>（订阅号无网页授权能力，无法登录）。</li>
                <li>公众号后台 →「设置与开发 → 基本配置」→ 复制 <b>AppID</b> 和 <b>AppSecret</b> 填入本页。</li>
                <li>公众号后台 →「设置与开发 → 公众号设置 → 功能设置」→ 配置「网页授权域名」为下方回调地址中的域名部分（不含 https:// 与路径），并按提示下载校验文件放到该域名根目录。</li>
                <li>域名备案主体须与公众号主体一致（客户自己的服务号必须配客户自己的域名）。</li>
                <li>保存后用户<b>在微信内</b>打开 H5 页面即可免密登录。</li>
              </ol>
              <div class="help-title">🛠 常见问题排查</div>
              <ul>
                <li><b>提示 redirect_uri 参数错误</b>：网页授权域名未配置，或回调地址域名与后台配置不一致。</li>
                <li><b>授权页提示无法验证账号</b>：AppID 填成了开放平台网站应用/小程序的，必须是<b>认证服务号</b>的 AppID。</li>
                <li><b>AppSecret 无效（40013/40125）</b>：填的不是该服务号的 AppSecret；重置后需同步更新本页。</li>
              </ul>
            </template>
          </div>
        </el-tab-pane>
      </el-tabs>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'
import { Check } from '@element-plus/icons-vue'

// ─── 平台公众号授权（component 模式） ───────────────────
const auth = reactive<any>({ status: 'pending', authorizer_appid: '', authorizer_type: '', nickname: '', authorized_at: '', callback: null })
const authStarting = ref(false)
const authRevoking = ref(false)
const authError = ref('')
const authHint = ref('')
const authPermissions = ref<{ key: string; label: string }[]>([])

const authAuthorized = computed(() => auth.status === 'authorized')
const authTypeLabel = computed(() => auth.authorizer_type === 'mini_program' ? '小程序' : '公众号')

// ─── 接入模式（Tab 互斥）：已授权时锁定 component；切自建需先解除授权 ───
const mode = ref<'component' | 'self'>('component')

const guardModeSwitch = async (newMode: string | number): Promise<boolean> => {
  if (newMode !== 'self' || auth.status !== 'authorized') return true
  try {
    await ElMessageBox.confirm(
      '两种接入方式互斥。切换到自建应用需先解除平台授权，解除后微信内 H5 登录立即回退（重新授权或配置自建凭证前不可用）。',
      '切换接入模式',
      { type: 'warning', confirmButtonText: '解除授权并切换', cancelButtonText: '取消' },
    )
  } catch { return false }
  await doRevokeAuth()
  // 解除失败（仍为已授权）则阻止切换
  return auth.status !== 'authorized'
}

const copyText = async (text: string) => {
  if (!text) return
  try {
    await navigator.clipboard.writeText(text)
    ElMessage.success('已复制')
  } catch {
    ElMessage.error('复制失败，请手动复制')
  }
}

const fetchStatus = async () => {
  try {
    const res = await axios.get('/api/v1/tenant/wechat/status')
    const data = res.data.data || {}
    Object.assign(auth, data)
    authPermissions.value = data.permissions || []
    // 首次加载：按实际授权状态锁定模式（已授权 → component）
    if (data.status === 'authorized') mode.value = 'component'
    authError.value = ''
  } catch (e: any) {
    authError.value = e.response?.data?.message || '查询授权状态失败'
  }
}

const startAuth = async () => {
  authStarting.value = true
  authError.value = ''
  authHint.value = ''
  try {
    const res = await axios.post('/api/v1/tenant/wechat/authorize')
    const data = res.data.data || {}
    // 两步式解除后的恢复：微信侧未取消授权时直接恢复本地授权，无需重新授权
    if (data.recovered) {
      authHint.value = data.message || '微信侧仍处于授权状态，已为您恢复授权'
      await fetchStatus()
      return
    }
    const url = data.url
    if (!url) throw new Error('未返回授权 URL')
    authPermissions.value = data.provider?.permissions || []
    // 微信授权为网页流程：新窗口打开授权页（非扫码），授权完成回跳平台域
    window.open(url, '_blank', 'noopener')
    authHint.value = '已在新窗口打开微信授权页，请管理员完成授权；回跳成功后回到本页点「刷新状态」确认。'
  } catch (e: any) {
    authError.value = e.response?.data?.message || '生成授权链接失败'
  } finally {
    authStarting.value = false
  }
}

const doRevokeAuth = async () => {
  try {
    authRevoking.value = true
    await axios.post('/api/v1/tenant/wechat/revoke')
    ElMessage.success('已解除本地授权（微信侧如需彻底取消请到公众平台操作）')
    await fetchStatus()
  } catch (e: any) {
    if (e?.response) ElMessage.error(e.response.data?.message || '解除授权失败')
  } finally {
    authRevoking.value = false
  }
}

const revokeAuth = async () => {
  try {
    await ElMessageBox.confirm('确认解除授权？将仅解除本地授权（登录立即回退自建模式，如有）；微信侧如需彻底取消，请公众号管理员在「公众平台-设置与开发-第三方平台-我的授权」中取消该平台授权。', '提示', { type: 'warning' })
  } catch { return }
  await doRevokeAuth()
}

// ─── 自建应用凭证 ───────────────────
const self = reactive({ client_id: '', client_secret: '', redirect: '', oauth_mode: 'h5' })
const selfSaving = ref(false)

const loadSelf = async () => {
  try {
    const res = await axios.get('/api/v1/tenant/auth/oauth/config')
    const data = res.data.data || res.data
    if (data.wechat) Object.assign(self, data.wechat)
  } catch {
    // 加载失败保持表单初始值，避免用户无感知地用默认值覆盖存量配置
    ElMessage.error('加载自建凭证失败，请刷新页面重试')
  }
}

const saveSelf = async () => {
  if (!self.client_id) {
    ElMessage.warning('请先填写 AppID')
    return
  }
  selfSaving.value = true
  try {
    const { client_id, client_secret, oauth_mode } = self
    await axios.put('/api/v1/tenant/auth/oauth/wechat', { client_id, client_secret, oauth_mode })
    ElMessage.success('自建凭证已保存')
    await loadSelf()
    await loadCapability()
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '保存失败')
  } finally {
    selfSaving.value = false
  }
}

// ─── 能力信息（双轨登录模式） ───────────────────
const capability = ref<any>(null)

const loginModeTip = computed(() => {
  if (!capability.value) return ''
  switch (capability.value.login_mode) {
    case 'component':
      return '公众号粉丝在微信内打开 H5 页面即可免密登录（网页授权由第三方平台代替实现）；PC 扫码登录当前不可用（未配置自建应用）。'
    case 'self':
      return capability.value.self_mode === 'pc'
        ? 'PC 端扫码登录（开放平台网站应用）；授权平台公众号后自动切换为微信内 H5 登录。'
        : '微信内 H5 免密登录（认证服务号网页授权）；授权平台公众号后自动切换为服务商模式。'
    default:
      return '配置任一接入方式后即可启用微信登录。'
  }
})

const loadCapability = async () => {
  try {
    const res = await axios.get('/api/v1/tenant/wechat/capability')
    capability.value = res.data.data
  } catch {}
}

onMounted(() => {
  fetchStatus()
  loadSelf()
  loadCapability()
})
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.section-title { font-weight: 600; font-size: 14px; margin: 16px 0 8px; color: var(--el-text-color-primary); }
.form-tip { font-size: 12px; color: var(--el-text-color-secondary); line-height: 1.5; margin-top: 4px; }
.config-form { max-width: 560px; margin-bottom: 8px; }
.help-box { margin-top: 12px; padding: 12px 16px; background: var(--el-fill-color-light); border-radius: 6px; font-size: 13px; line-height: 1.8; color: var(--el-text-color-regular); }
.help-title { font-weight: 600; margin: 4px 0; color: var(--el-text-color-primary); }
.help-box ol, .help-box ul { margin: 4px 0 12px; padding-left: 20px; }
.help-box code { background: var(--el-fill-color); padding: 1px 6px; border-radius: 3px; word-break: break-all; }
.help-box a { color: var(--el-color-primary); }
.mode-tabs :deep(.el-tabs__header) { margin-bottom: 14px; }
.tab-label { display: inline-flex; align-items: center; gap: 4px; }
.mode-overview { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--el-text-color-regular); flex-wrap: wrap; }
.mode-overview-label { font-size: 12px; color: var(--el-text-color-secondary); }
.state-line { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--el-text-color-regular); line-height: 1.6; margin: 4px 0 8px; }
.auth-grid { display: flex; gap: 24px; flex-wrap: wrap; margin: 4px 0 8px; }
.auth-info { flex: 1 1 260px; min-width: 0; }
.auth-perms { flex: 1 1 220px; min-width: 0; }
.auth-row { display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--el-text-color-regular); padding: 3px 0; }
.auth-label { flex: 0 0 76px; font-size: 12px; color: var(--el-text-color-secondary); }
.auth-row code { font-size: 12px; background: var(--el-fill-color); padding: 1px 6px; border-radius: 3px; word-break: break-all; }
.auth-actions { display: flex; align-items: center; gap: 8px; margin: 8px 0 4px; }
.perm-list { margin-top: 6px; }
.perm-line { display: flex; align-items: center; gap: 6px; font-size: 13px; color: var(--el-text-color-regular); padding: 3px 0; }
.perm-check { color: var(--el-color-success); font-size: 14px; }
.adv-collapse { margin-top: 4px; border-top: none; }
.adv-collapse :deep(.el-collapse-item__header) { font-size: 12px; color: var(--el-text-color-secondary); height: 36px; }
.callback-quote { background: var(--el-fill-color-light); border-left: 3px solid var(--el-color-primary-light-5); border-radius: 4px; padding: 10px 12px; display: flex; flex-direction: column; gap: 8px; }
.callback-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.callback-label { flex: 0 0 88px; font-size: 12px; color: var(--el-text-color-secondary); white-space: nowrap; }
.callback-code { font-size: 12px; background: #fff; border: 1px solid var(--el-border-color-lighter); padding: 2px 6px; border-radius: 3px; word-break: break-all; flex: 1; min-width: 0; }
.callback-tip { font-size: 12px; color: var(--el-text-color-secondary); line-height: 1.6; }
</style>
