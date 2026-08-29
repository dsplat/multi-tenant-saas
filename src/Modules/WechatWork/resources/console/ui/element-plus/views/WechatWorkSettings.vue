<template>
  <div class="page">
    <div class="page-header"><h2>企业微信配置</h2></div>

    <!-- ① 接入模式 = 最外层 Tab（选项与内容一体，互斥切换） -->
    <el-card shadow="never" style="max-width: 860px">
      <el-tabs v-model="mode" class="mode-tabs" :before-leave="guardModeSwitch">
        <!-- ── 平台代开发应用 ── -->
        <el-tab-pane name="suite">
          <template #label>
            <span class="tab-label">平台代开发应用
              <el-tag size="small" type="success">推荐</el-tag>
              <el-tag v-if="suiteAuthorized" size="small" style="margin-left: 4px">使用中</el-tag>
            </span>
          </template>

          <template v-if="suiteAuthorized">
            <!-- 左：操作区（状态/凭证/操作/回调链路）；右：权限清单（一行一个，便于逐项阅读） -->
            <div class="auth-grid">
              <div class="auth-info">
                <div class="state-line">
                  <el-tag type="success" size="small">已授权</el-tag>
                  <span>企业微信扫码登录<b>已自动启用</b>，无需其他配置。</span>
                </div>
                <div class="auth-row"><span class="auth-label">Corp ID</span><code>{{ suiteAuth.corp_id }}</code></div>
                <div class="auth-row"><span class="auth-label">Agent ID</span><code>{{ suiteAuth.agent_id }}</code></div>
                <div class="auth-row"><span class="auth-label">授权时间</span><span>{{ suiteAuth.authorized_at || '—' }}</span></div>
                <div class="auth-actions">
                  <el-button type="danger" plain size="small" :loading="suiteRevoking" @click="revokeSuiteAuth">解除授权</el-button>
                  <el-button link size="small" @click="fetchSuiteStatus">刷新状态</el-button>
                </div>
                <!-- 应用回调链路：服务商代管的技术细节，默认折叠 -->
                <el-collapse v-if="suiteAuth.callback" class="adv-collapse">
                  <el-collapse-item name="callback">
                    <template #title>
                      应用回调链路（技术细节，服务商代管）
                      <el-tag v-if="!suiteAuth.callback.app_callback_configured" type="warning" size="small" style="margin-left: 8px">待平台配置</el-tag>
                      <el-tag v-else type="success" size="small" style="margin-left: 8px">就绪</el-tag>
                    </template>
                    <div class="callback-quote">
                      <div v-if="!suiteAuth.callback.app_callback_configured" class="callback-warn">
                        应用回调尚未配置：请平台在「管理后台 → 企微服务商」配置模板级应用回调 Token / EncodingAESKey，配置完成前应用无法接收事件推送。
                      </div>
                      <div class="callback-row">
                        <span class="callback-label">应用回调 URL</span>
                        <code class="callback-code">{{ suiteAuth.callback.app_callback_url }}</code>
                        <el-button link type="primary" size="small" @click="copyText(suiteAuth.callback.app_callback_url)">复制</el-button>
                      </div>
                      <div v-if="suiteAuth.callback.app_callback_url_legacy" class="callback-row">
                        <span class="callback-label">备用地址</span>
                        <code class="callback-code">{{ suiteAuth.callback.app_callback_url_legacy }}</code>
                        <el-button link type="primary" size="small" @click="copyText(suiteAuth.callback.app_callback_url_legacy)">复制</el-button>
                      </div>
                      <div class="callback-tip">
                        可信域名须填 <b>{{ suiteCallbackDomain }}</b>（不含 https:// 与路径）；应用主页可填 club.lanyantu.com 等终端站点（与认证无关）。
                      </div>
                    </div>
                  </el-collapse-item>
                </el-collapse>
              </div>
              <div class="auth-perms">
                <div class="auth-label">已获得模板权限（扫码授权即一次性获得）</div>
                <div class="perm-list">
                  <div v-for="p in suiteAuthPermissions" :key="p.key" class="perm-line">
                    <el-icon class="perm-check"><Check /></el-icon>
                    <span>{{ p.label }}</span>
                  </div>
                  <span v-if="!suiteAuthPermissions.length" class="form-tip">—</span>
                </div>
              </div>
            </div>
          </template>

          <template v-else>
            <!-- 未授权同样左操作区（引导/二维码） + 右权限清单，与已授权态布局一致 -->
            <div class="auth-grid">
              <div class="auth-info">
                <div class="state-line">
                  <span>尚未授权。扫码授权完成后企业微信扫码登录<b>自动启用</b>，无需去其他页面开启开关。</span>
                </div>
                <div v-if="suiteAuthUrl" class="suite-qr-box">
                  <div class="suite-qr">
                    <QrcodeVue :value="suiteAuthUrl" :size="176" level="M" render-as="canvas" />
                  </div>
                  <p class="form-tip" style="margin: 8px 0 0">请使用<b>企业微信</b>扫描二维码，由企业管理员确认授权</p>
                  <div class="auth-actions">
                    <el-button size="small" :loading="suiteAuthorizing" @click="startSuiteAuth">重新生成二维码</el-button>
                    <el-button size="small" @click="fetchSuiteStatus">刷新状态</el-button>
                  </div>
                </div>
                <div v-else class="auth-actions">
                  <el-button type="primary" size="small" :loading="suiteAuthorizing" @click="startSuiteAuth">使用平台代开发应用扫码授权</el-button>
                  <el-button link size="small" @click="fetchSuiteStatus">刷新状态</el-button>
                </div>
                <p v-if="suiteAuth.status === 'revoked'" class="form-tip" style="margin-top: 6px">当前状态：已解除，可重新扫码授权。重新授权即可恢复：平台配置（可信域名/回调）自动复用，企微将下发新的授权凭证，租户其余配置不受影响。</p>
                <p v-if="suiteAuthHint" class="form-tip" style="margin-top: 6px">{{ suiteAuthHint }}</p>
                <p v-if="suiteAuthError" class="form-tip" style="margin-top: 6px; color: var(--el-color-danger)">{{ suiteAuthError }}</p>
              </div>
              <div class="auth-perms">
                <div class="auth-label">授权后将获得模板权限（可信域名/回调域由服务商代管，无需逐项配置）</div>
                <div class="perm-list">
                  <div v-for="p in suiteAuthPermissions" :key="p.key" class="perm-line">
                    <el-icon class="perm-check"><Check /></el-icon>
                    <span>{{ p.label }}</span>
                  </div>
                  <span v-if="!suiteAuthPermissions.length" class="form-tip">—</span>
                </div>
              </div>
            </div>
          </template>
        </el-tab-pane>

        <!-- ── 自建应用 ── -->
        <el-tab-pane label="自建应用" name="self">
          <p class="form-tip" style="margin: 4px 0 10px">在此填入企微自建应用凭证（含其他服务商代开发应用，对本平台等同自建）。</p>
          <el-form label-width="90px" class="config-form">
            <el-form-item label="Corp ID"><el-input v-model="self.corp_id" placeholder="ww1234567890abcdef" /></el-form-item>
            <el-form-item label="Agent ID"><el-input v-model="self.agent_id" placeholder="1000001" /></el-form-item>
            <el-form-item label="Secret"><el-input v-model="self.secret" /></el-form-item>
            <el-form-item v-if="self.redirect" label="回调地址">
              <el-input :model-value="self.redirect" readonly />
            </el-form-item>
          </el-form>
          <el-button type="primary" size="small" :loading="selfSaving" @click="saveSelf">保存自建凭证</el-button>
          <el-alert type="info" :closable="false" show-icon style="margin-top: 12px">
            <template #title>
              凭证保存后，请到「第三方登录配置 → 企业微信」打开「启用企业微信扫码登录」开关。
            </template>
          </el-alert>

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
        </el-tab-pane>
      </el-tabs>
    </el-card>

    <!-- ② 辅助配置卡：可信域名验证文件（仅自建模式；代开发模式由服务商代管，租户侧不可操作） -->
    <el-card v-if="mode === 'self'" shadow="never" style="max-width: 860px; margin-top: 16px">
      <div class="section-title" style="margin-top: 0">可信域名验证文件（WW_verify）</div>
      <div class="verify-host">
        <span class="verify-host-label">验证文件挂载域名（租户域）：</span>
        <code>https://{{ verifyDomain || '未配置租户域名' }}/WW_verify_xxx.txt</code>
      </div>
      <div class="verify-actions">
        <el-input
          v-model="verifyFileInput"
          size="small"
          style="max-width: 280px"
          placeholder="如：WW_verify_mLUxXhK2fEC6jPsB"
          @keyup.enter="handleAddVerifyFile"
        />
        <el-button size="small" type="primary" :loading="verifyFilesSaving" @click="handleAddVerifyFile">添加</el-button>
        <el-tooltip placement="top" effect="light">
          <template #content>
            什么时候需要此文件？<br />
            自建应用：企微后台「应用详情 → 开发者接口 → 网页授权及JS-SDK」配置可信域名时需完成归属认证。<br />
            注意：扫码登录本身只需「授权回调域」，此文件仅用于可信域名归属认证；平台代开发模式下可信域名由服务商代管，无需此操作。
          </template>
          <el-button link type="info" size="small">
            <el-icon style="margin-right: 2px"><QuestionFilled /></el-icon>什么时候需要此文件？
          </el-button>
        </el-tooltip>
      </div>
      <div v-if="verifyFiles.length" style="margin-top: 8px">
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
      <p v-else class="form-tip" style="margin-top: 8px">暂无验证文件。企微下发验证文件后，将文件名登记到上方列表即可。</p>
    </el-card>

    <!-- ③ 独立查看卡：随接入模式联动（suite=套餐与许可用量 / self=出口代理，均只读） -->
    <el-card v-if="capability" shadow="never" style="max-width: 860px; margin-top: 16px">
      <div class="section-title" style="margin-top: 0">{{ mode === 'suite' ? '套餐与许可用量（只读）' : '出口代理（只读）' }}</div>

      <template v-if="mode === 'suite'">
        <div class="plan-line">
          <span class="plan-label">当前套餐</span>
          <b>{{ planName }}</b>
          <span v-if="trialText" class="form-tip" style="margin-left: 8px">许可免费窗口{{ trialText }}</span>
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
      </template>

      <template v-else>
        <div class="verify-host">
          <span class="verify-host-label">平台分配出口 IP：</span>
          <code>{{ proxyExitIp || '暂未分配，请联系平台方' }}</code>
        </div>
        <p class="form-tip" style="margin-top: 8px">
          自建应用走平台出口代理调用企微 API，需将上述出口 IP 加入企业后台「开发者接口 → 企业可信 IP」，否则企微 API 报 60020。
        </p>
      </template>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'
import { QuestionFilled, Check } from '@element-plus/icons-vue'
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

const suiteAuthorized = computed(() => suiteAuth.status === 'authorized')

// ─── 接入模式（Tab 互斥）：已授权时锁定 suite；切自建需先解除授权 ───
const mode = ref<'suite' | 'self'>('suite')

const guardModeSwitch = async (newMode: string | number): Promise<boolean> => {
  if (newMode !== 'self' || suiteAuth.status !== 'authorized') return true
  try {
    await ElMessageBox.confirm(
      '两种接入方式互斥。切换到自建应用需先解除平台代开发授权，解除后企微扫码登录立即回退（重新授权或配置自建凭证前不可用）。',
      '切换接入模式',
      { type: 'warning', confirmButtonText: '解除授权并切换', cancelButtonText: '取消' },
    )
  } catch { return false }
  await doRevokeSuiteAuth()
  // 解除失败（仍为已授权）则阻止切换
  return suiteAuth.status !== 'authorized'
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
    // 两步式解除后的恢复：企微侧应用未删除时直接恢复本地授权，无需重新扫码
    if (data.recovered) {
      suiteAuthHint.value = data.message || '企微侧仍处于授权安装状态，已为您恢复授权'
      await fetchSuiteStatus()
      return
    }
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
    await ElMessageBox.confirm('确认解除平台代开发授权？解除后登录将回退自建应用配置（如有）。重新授权即可恢复，平台配置（可信域名/回调）自动复用，租户其余配置不受影响。', '提示', { type: 'warning' })
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

// ─── 套餐与许可用量（阶段 C，11.5） ───────────────────
const capability = ref<any>(null)

// 无套餐记录（未订阅）→ 显示「免费版」，避免 '-' 造成「未加载」错觉
const planName = computed(() => {
  if (capability.value?.plan) return capability.value.plan.display_name || capability.value.plan.name
  return '免费版'
})
const trialText = computed(() => capability.value?.free_trial_ends_at ? `至 ${capability.value.free_trial_ends_at.slice(0, 10)}` : '')
const proxyExitIp = computed(() => capability.value?.proxy?.enabled ? (capability.value.proxy.exit_ip || '') : '')

const limitText = (v: any) => (v === null || v === undefined ? '不限' : v)

const licenseRows = computed(() => {
  if (!capability.value) return []
  const l = capability.value.limits || {}
  const u = capability.value.usage || {}
  const rows = [
    { label: '基础许可', limit: l.wechat_work_license_basic, used: u.license_basic_used ?? 0 },
    { label: '互通许可', limit: l.wechat_work_license_intercom, used: u.license_intercom_used ?? 0 },
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
.mode-tabs :deep(.el-tabs__header) { margin-bottom: 14px; }
.tab-label { display: inline-flex; align-items: center; gap: 4px; }
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
.callback-warn { font-size: 12px; color: var(--el-color-warning); line-height: 1.6; }
.callback-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.callback-label { flex: 0 0 88px; font-size: 12px; color: var(--el-text-color-secondary); white-space: nowrap; }
.callback-code { font-size: 12px; background: #fff; border: 1px solid var(--el-border-color-lighter); padding: 2px 6px; border-radius: 3px; word-break: break-all; flex: 1; min-width: 0; }
.callback-tip { font-size: 12px; color: var(--el-text-color-secondary); line-height: 1.6; }
.suite-qr-box { margin-top: 10px; }
.suite-qr { display: inline-block; padding: 10px; background: #fff; border: 1px solid var(--el-border-color-lighter); border-radius: 6px; }
.plan-line { display: flex; align-items: baseline; gap: 6px; font-size: 13px; color: var(--el-text-color-regular); margin: 8px 0 12px; }
.plan-line b { font-size: 14px; color: var(--el-text-color-primary); }
.plan-label { font-size: 12px; color: var(--el-text-color-secondary); }
.verify-host { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; margin: 4px 0 10px; padding: 8px 12px; background: var(--el-color-primary-light-9); border: 1px solid var(--el-color-primary-light-7); border-radius: 6px; }
.verify-host-label { font-size: 12px; color: var(--el-text-color-regular); white-space: nowrap; }
.verify-host code { font-size: 12px; word-break: break-all; }
.verify-actions { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
</style>
