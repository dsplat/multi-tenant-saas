<template>
  <div class="page">
    <div class="page-header"><h2>企业微信配置</h2></div>

    <el-card shadow="never" style="max-width: 860px">
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 16px">
        <template #title>
          企业微信扫码登录「启用开关」在「第三方登录配置 → 企业微信」；本页配置企微接入方式（平台代开发授权 / 自建应用凭证）与可信域名验证。
        </template>
      </el-alert>

      <!-- ── 接入模式选择（互斥：平台代开发 / 自建应用） ── -->
      <div class="mode-cards">
        <div class="mode-card" :class="{ active: mode === 'suite' }" @click="chooseMode('suite')">
          <div class="mode-card-title">平台代开发应用 <el-tag size="small" type="success">推荐</el-tag><el-tag v-if="suiteAuth.status === 'authorized'" size="small" style="margin-left: 4px">使用中</el-tag></div>
          <p>扫码授权即完成接入，可信域名、回调域由服务商代管，无需填写凭证。</p>
        </div>
        <div class="mode-card" :class="{ active: mode === 'self' }" @click="chooseMode('self')">
          <div class="mode-card-title">自建应用</div>
          <p>在企微后台自建应用并填入凭证，需自行配置可信域名、回调域与可信 IP（含其他服务商代开发，对本平台等同自建）。</p>
        </div>
      </div>

      <!-- ── 平台代开发应用授权（suite 模式） ── -->
      <div v-if="mode === 'suite'" class="suite-box">
        <div class="help-title" style="margin-bottom: 6px">平台代开发应用授权</div>
        <p class="form-tip">
          企业微信自建应用的可信域名须与认证主体一致，租户自有域名无法作为平台回调域（auth.neihang.com）。
          平台已注册企微服务商（代开发模式），扫码授权后即完成接入、无需任何配置；未使用平台代开发时，可在下方填写「自建应用」凭证。
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
              <span class="callback-label">备用地址（手填时用）：</span>
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

      <!-- 已授权提示（suite 模式下展示）：与自建互斥，禁止双轨凭证并存 -->
      <el-alert
        v-if="mode === 'suite' && suiteAuth.status === 'authorized'"
        type="success"
        :closable="false"
        show-icon
        style="margin-bottom: 16px"
      >
        <template #title>
          当前使用平台代开发应用授权，企业微信扫码登录已自动启用，无需配置下方自建应用。
          如需改用自建应用（含其他服务商代开发），请先「解除授权」。
        </template>
      </el-alert>

      <!-- ── 自建应用凭证（选中「自建应用」模式时展示） ── -->
      <template v-if="mode === 'self'">
        <div class="section-title">自建应用凭证</div>
        <p class="form-tip">
          未使用平台代开发时（含企业自建应用、其他服务商代开发应用，对本平台等同自建），在此填入企微应用凭证。保存后回到「第三方登录配置 → 企业微信」打开启用开关即可。
        </p>
        <el-form label-width="90px" class="config-form">
          <el-form-item label="Corp ID"><el-input v-model="self.corp_id" placeholder="ww1234567890abcdef" /></el-form-item>
          <el-form-item label="Agent ID"><el-input v-model="self.agent_id" placeholder="1000001" /></el-form-item>
          <el-form-item label="Secret"><el-input v-model="self.secret" /></el-form-item>
          <el-form-item v-if="self.redirect" label="回调地址">
            <el-input :model-value="self.redirect" readonly />
          </el-form-item>
        </el-form>
        <el-button type="primary" size="small" :loading="selfSaving" @click="saveSelf">保存自建凭证</el-button>

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
      </template>

      <el-divider />

      <!-- ── 能力包与许可用量（阶段 C，11.5 console 自服务展示） ── -->
      <div v-if="capability" class="cap-box">
        <div class="section-title">能力包与许可用量</div>
        <p class="form-tip">能力按套餐分层开通（基础/互通/自建/存档），许可配额超量时请联系平台升级套餐。</p>
        <el-descriptions :column="2" size="small" border style="margin: 8px 0">
          <el-descriptions-item label="当前套餐">{{ planName }}</el-descriptions-item>
          <el-descriptions-item label="许可免费窗口">{{ trialText }}</el-descriptions-item>
          <el-descriptions-item label="接入模式">{{ modeText }}</el-descriptions-item>
          <el-descriptions-item label="平台出口 IP">{{ proxyExitIp || '未分配' }}</el-descriptions-item>
        </el-descriptions>
        <div style="margin: 8px 0">
          <el-tag
            v-for="f in capabilityTags"
            :key="f.key"
            :type="f.enabled ? 'success' : 'info'"
            size="small"
            style="margin-right: 6px"
          >{{ f.label }}</el-tag>
        </div>
        <el-table :data="licenseRows" size="small" style="max-width: 520px">
          <el-table-column prop="label" label="许可" width="110" />
          <el-table-column label="配额" width="100">
            <template #default="{ row }">{{ limitText(row.limit) }}</template>
          </el-table-column>
          <el-table-column label="已用" width="100">
            <template #default="{ row }">
              <span :style="{ color: row.over ? 'var(--el-color-danger)' : 'inherit' }">{{ row.used }}</span>
            </template>
          </el-table-column>
          <el-table-column label="状态">
            <template #default="{ row }">
              <el-tag v-if="row.over" type="danger" size="small">超量</el-tag>
              <el-tag v-else type="success" size="small">正常</el-tag>
            </template>
          </el-table-column>
        </el-table>
        <p v-if="proxyExitIp" class="form-tip" style="margin-top: 8px">
          自建应用需将平台出口 IP <b>{{ proxyExitIp }}</b> 加入企业后台「开发者接口 → 企业可信 IP」，否则企微 API 报 60020；代开发模式已由平台统一处理。
        </p>
      </div>

      <el-divider />
      <div class="section-title">可信域名验证文件（WW_verify）</div>
      <p class="form-tip">
        企微在以下场景要求域名归属认证，下发 <code>WW_verify_xxx.txt</code> 验证文件：
        ① <b>自建应用</b>——企微后台「应用详情 → 开发者接口 → 网页授权及JS-SDK」配置可信域名时；
        ② <b>代开发模式</b>——为企业配置「企业微信授权登录（Web 登录）」的可信域名时。
        两种接入模式下均可将文件名登记到下方列表，系统自动在回调域名
        （{{ verifyDomain || '未配置回调域' }}）根路径提供该文件，企微校验即可通过。
      </p>
      <p class="form-tip" style="color: var(--el-color-warning, #e6a23c)">
        注意：该验证与扫码登录开关无关——OAuth 登录只需配置「授权回调域」；验证文件用于可信域名归属认证。
      </p>

      <div v-if="verifyFiles.length" style="margin-bottom: 8px">
        <div
          v-for="f in verifyFiles"
          :key="f"
          style="display: flex; align-items: center; gap: 6px; margin-bottom: 4px"
        >
          <code>{{ f }}</code>
          <a v-if="verifyDomain" :href="`https://${verifyDomain}/${f}`" target="_blank" rel="noopener">验证</a>
          <el-button link type="danger" size="small" @click="handleRemoveVerifyFile(f)">删除</el-button>
        </div>
      </div>
      <div style="display: flex; gap: 6px; margin-top: 4px">
        <el-input
          v-model="verifyFileInput"
          size="small"
          style="max-width: 280px"
          placeholder="如：WW_verify_mLUxXhK2fEC6jPsB"
          @keyup.enter="handleAddVerifyFile"
        />
        <el-button size="small" type="primary" :loading="verifyFilesSaving" @click="handleAddVerifyFile">添加</el-button>
      </div>
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

// ─── 平台代开发应用授权（suite 模式） ───────────────────
const suiteAuth = reactive({ status: 'pending', corp_id: '', agent_id: '', authorized_at: '', callback: null as any })
const suiteAuthorizing = ref(false)
const suiteRevoking = ref(false)
const suiteAuthError = ref('')
const suiteAuthHint = ref('')
const suiteAuthUrl = ref('')
const suiteAuthPermissions = ref<{ key: string; label: string }[]>([])

// ─── 接入模式（互斥选择）：已授权时锁定 suite；切自建需先解除授权 ───
const mode = ref<'suite' | 'self'>('suite')

const chooseMode = async (m: 'suite' | 'self') => {
  if (m === mode.value) return
  if (m === 'self' && suiteAuth.status === 'authorized') {
    try {
      await ElMessageBox.confirm(
        '两种接入方式互斥。切换到自建应用需先解除平台代开发授权，解除后企微扫码登录立即回退（重新授权或配置自建凭证前不可用）。',
        '切换接入模式',
        { type: 'warning', confirmButtonText: '解除授权并切换', cancelButtonText: '取消' },
      )
    } catch { return }
    await doRevokeSuiteAuth()
    if (suiteAuth.status === 'authorized') return // 解除失败则不切换
  }
  mode.value = m
}

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
    // 首次加载：按实际授权状态锁定模式（已授权 → suite）
    if (data.status === 'authorized') mode.value = 'suite'
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
    suiteAuthUrl.value = url
    suiteAuthPermissions.value = data.provider?.permissions || []
    suiteAuthHint.value = '已生成授权二维码，请用企业微信扫码；授权完成后点击「刷新状态」确认。'
  } catch (e: any) {
    suiteAuthError.value = e.response?.data?.message || '生成授权二维码失败'
  } finally {
    suiteAuthorizing.value = false
  }
}

const doRevokeSuiteAuth = async () => {
  try {
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

const revokeSuiteAuth = async () => {
  try {
    await ElMessageBox.confirm('确认解除平台代开发授权？解除后登录将回退自建应用配置（如有）。', '提示', { type: 'warning' })
  } catch { return }
  await doRevokeSuiteAuth()
}

// ─── 自建应用凭证 ───────────────────
const self = reactive({ corp_id: '', agent_id: '', secret: '', redirect: '' })
const selfSaving = ref(false)

const loadSelf = async () => {
  try {
    const res = await axios.get('/api/v1/tenant/auth/oauth/config')
    const data = res.data.data || res.data
    if (data.wechat_work) Object.assign(self, data.wechat_work)
  } catch {}
}

const saveSelf = async () => {
  selfSaving.value = true
  try {
    const { corp_id, agent_id, secret } = self
    await axios.put('/api/v1/tenant/auth/oauth/wechat_work', { corp_id, agent_id, secret })
    ElMessage.success('自建凭证已保存')
    await loadSelf()
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '保存失败')
  } finally {
    selfSaving.value = false
  }
}

// ─── 可信域名验证文件（WW_verify） ───────────────────
const tenantDomain = ref('')
const verifyFiles = ref<string[]>([])
const verifyFileInput = ref('')
const verifyFilesSaving = ref(false)

const verifyDomain = computed(() => tenantDomain.value || window.location.host)

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
  } catch (e: any) {
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
    ElMessage.success('验证文件已添加，企微可立即校验')
  }
}

const handleRemoveVerifyFile = async (file: string) => {
  const ok = await saveVerifyFiles(verifyFiles.value.filter(f => f !== file))
  if (ok) ElMessage.success('验证文件已删除')
}

// ─── 能力包与许可用量（阶段 C，11.5） ───────────────────
const capability = ref<any>(null)

const planName = computed(() => capability.value?.plan ? (capability.value.plan.display_name || capability.value.plan.name) : '-')
const trialText = computed(() => capability.value?.free_trial_ends_at ? `至 ${capability.value.free_trial_ends_at.slice(0, 10)}` : '-')
const modeText = computed(() => ({ suite: '服务商代开发', self: '自建应用', none: '未接入' } as any)[capability.value?.mode] || '-')
const proxyExitIp = computed(() => capability.value?.proxy?.enabled ? (capability.value.proxy.exit_ip || '') : '')

const capabilityTags = computed(() => {
  if (!capability.value) return []
  const defs = [
    { key: 'base', label: '基础能力' },
    { key: 'intercom', label: '互通能力' },
    { key: 'self', label: '自建应用' },
    { key: 'archive', label: '会话存档' },
  ]
  return defs.map(d => ({ ...d, enabled: !!capability.value.features?.[d.key] }))
})

const limitText = (v: any) => (v === null || v === undefined ? '不限' : v)

const licenseRows = computed(() => {
  if (!capability.value) return []
  const l = capability.value.limits || {}
  const u = capability.value.usage || {}
  const rows = [
    { label: '基础许可', limit: l.wechat_work_license_basic, used: u.license_basic_used ?? 0 },
    { label: '互通许可', limit: l.wechat_work_license_intercom, used: u.license_intercom_used ?? 0 },
    { label: '出口 IP', limit: l.wechat_work_proxy_ips, used: u.proxy_ip ? 1 : 0 },
  ]
  return rows.map(r => ({ ...r, over: r.limit !== null && r.limit !== undefined && r.used > r.limit }))
})

const loadCapability = async () => {
  try {
    const res = await axios.get('/api/v1/tenant/wechat-work/capability')
    capability.value = res.data.data
  } catch {}
}

onMounted(() => {
  fetchSuiteStatus()
  loadSelf()
  loadVerifyFiles()
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
.suite-box { margin-bottom: 16px; padding: 12px 16px; background: var(--el-fill-color-light); border: 1px solid var(--el-border-color-lighter); border-radius: 6px; }
.mode-cards { display: flex; gap: 12px; margin-bottom: 16px; }
.mode-card { flex: 1; padding: 12px 14px; border: 1px solid var(--el-border-color-lighter); border-radius: 6px; cursor: pointer; transition: border-color .2s, box-shadow .2s; }
.mode-card:hover { border-color: var(--el-color-primary-light-5); }
.mode-card.active { border-color: var(--el-color-primary); box-shadow: 0 0 0 1px var(--el-color-primary-light-7) inset; background: var(--el-color-primary-light-9); }
.mode-card-title { font-weight: 600; font-size: 14px; color: var(--el-text-color-primary); display: flex; align-items: center; gap: 6px; }
.mode-card p { font-size: 12px; color: var(--el-text-color-secondary); line-height: 1.5; margin: 6px 0 0; }
.cap-box { margin-bottom: 8px; }
.suite-box .form-tip { margin-top: 6px; }
.suite-qr-box { margin-top: 10px; }
.suite-qr { display: inline-block; padding: 10px; background: #fff; border: 1px solid var(--el-border-color-lighter); border-radius: 6px; }
.suite-perms { margin-top: 10px; padding: 8px 10px; background: var(--el-fill-color-light); border-radius: 4px; }
.suite-callback { margin-top: 10px; }
.callback-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.callback-label { font-size: 12px; color: var(--el-text-color-secondary); white-space: nowrap; }
.callback-code { font-size: 12px; background: var(--el-fill-color); padding: 2px 6px; border-radius: 3px; word-break: break-all; flex: 1; min-width: 0; }
</style>
