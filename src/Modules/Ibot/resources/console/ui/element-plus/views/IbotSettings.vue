<template>
  <div class="page">
    <div class="page-header"><h2>随身助理（IM 机器人）</h2></div>

    <el-card shadow="never" style="max-width: 860px" v-loading="loading">
      <el-tabs v-model="activeTab">
        <!-- 可操作频道：企业微信 / Telegram -->
        <el-tab-pane v-for="ch in channels" :key="ch.key" :name="ch.key">
          <template #label>
            <span>{{ ch.label }}<el-tag v-if="ibotOf(ch.key)" :type="statusTagType(ch.key)" size="small" style="margin-left: 6px">{{ statusText(ch.key) }}</el-tag></span>
          </template>

          <div class="tab-body">
            <!-- 状态卡 -->
            <div class="status-row">
              <span>
                {{ ibotOf(ch.key) ? `机器人「${ibotOf(ch.key).name}」` : '尚未配置机器人' }}
                <template v-if="ibotOf(ch.key)">
                  · {{ ibotOf(ch.key).active_bindings_count || 0 }} 个生效绑定
                </template>
              </span>
              <el-switch
                v-if="ibotOf(ch.key)"
                :model-value="ibotOf(ch.key).status === 'active'"
                active-text="启用"
                @change="(v: boolean) => handleToggleStatus(ch.key, v)"
              />
            </div>

            <!-- 企微接入状态（凭证/回调统一在「企业微信配置」页维护，本页只读展示） -->
            <el-alert
              v-if="ch.key === 'wechat_work'"
              :type="wechatReady ? 'success' : 'warning'"
              :closable="false"
              show-icon
              style="margin-bottom: 16px; max-width: 620px"
            >
              <template #title>
                <template v-if="wechatSuiteAuthorized">企业微信代开发接入已就绪</template>
                <template v-else-if="wechatSelfConfigured">企业微信自建应用已配置</template>
                <template v-else>企业微信尚未配置</template>
              </template>
              <div v-if="!wechatReady" style="display: flex; align-items: center; gap: 12px">
                <span>请先在「企业微信配置」页完成代开发授权或自建凭证，再回来创建机器人。</span>
                <el-button size="small" type="primary" @click="goWechatWorkSettings">去企业微信配置</el-button>
              </div>
              <div v-else class="form-tip" style="margin-top: 2px">
                凭证与回调由「企业微信配置」页统一维护，本页无需重复填写。
              </div>
            </el-alert>

            <!-- 凭证表单（掩码显示，修改才提交） -->
            <el-form label-width="140px" class="config-form">
              <el-form-item label="机器人名称">
                <el-input v-model="forms[ch.key].name" :placeholder="`${ch.label}小助手`" />
              </el-form-item>
              <el-form-item v-for="f in ch.fields" :key="f.key" :label="f.label">
                <el-input v-model="forms[ch.key].credentials[f.key]" :placeholder="f.placeholder" />
                <div v-if="f.tip" class="form-tip">{{ f.tip }}</div>
              </el-form-item>
            </el-form>

            <el-button type="primary" :loading="saving" @click="handleSave(ch.key)">保存配置</el-button>

            <!-- 绑定区：仅激活后展示 -->
            <template v-if="ibotOf(ch.key)?.status === 'active'">
              <el-divider />
              <div class="bind-section">
                <div class="bind-header">
                  <span class="help-title">🔗 我的绑定</span>
                  <el-button size="small" @click="handleBindCode(ch.key)">生成我的绑定码</el-button>
                </div>

                <el-alert v-if="bindCodes[ch.key]" type="success" :closable="false" style="margin: 8px 0">
                  <template #title>
                    绑定码：<b>{{ bindCodes[ch.key].code }}</b>（{{ Math.round((bindCodes[ch.key].expires_in || 600) / 60) }} 分钟内有效，一次性使用）
                    <template v-if="bindCodes[ch.key].bind_link">
                      · <a :href="bindCodes[ch.key].bind_link" target="_blank" rel="noopener">一键绑定链接</a>
                    </template>
                  </template>
                  <div v-if="bindCodes[ch.key].bind_qr" class="bind-qr">
                    <qrcode-vue :value="bindCodes[ch.key].bind_qr" :size="128" level="M" />
                    <div class="form-tip">{{ ch.key === 'wechat_work' ? '用企业微信「扫一扫」识别二维码 → 确认身份 → 自动绑定并收到「绑定成功」消息，点开即可对话。' : '用对应 IM 扫一扫即可直达机器人完成绑定。' }}</div>
                  </div>
                  <div class="form-tip">{{ ch.bindHint }}</div>
                </el-alert>

                <el-table v-if="bindingsOf(ch.key).length" :data="bindingsOf(ch.key)" size="small" style="margin-top: 8px">
                  <el-table-column label="IM 账号" min-width="140">
                    <template #default="{ row }">{{ row.external_name || row.external_id }}</template>
                  </el-table-column>
                  <el-table-column prop="status" label="状态" width="90">
                    <template #default="{ row }">
                      <el-tag :type="row.status === 'active' ? 'success' : 'info'" size="small">{{ row.status === 'active' ? '生效' : row.status }}</el-tag>
                    </template>
                  </el-table-column>
                  <el-table-column label="默认通道" width="120">
                    <template #default="{ row }">
                      <el-tag v-if="row.is_default_channel" type="warning" size="small">默认</el-tag>
                      <el-button v-else-if="row.status === 'active'" link type="primary" size="small" @click="handleSetDefault(row)">设为默认</el-button>
                    </template>
                  </el-table-column>
                  <el-table-column label="操作" width="100">
                    <template #default="{ row }">
                      <el-button v-if="row.status === 'active'" link type="danger" size="small" @click="handleRevoke(row)">解除绑定</el-button>
                    </template>
                  </el-table-column>
                </el-table>
                <div v-else class="form-tip" style="margin-top: 8px">暂无绑定，生成绑定码后在 {{ ch.label }} 中发送给机器人即可绑定。</div>
              </div>
            </template>

            <!-- 教程区 -->
            <div class="help-box">
              <div class="help-title">📖 配置指引</div>
              <ol>
                <li v-for="(step, i) in ch.steps" :key="i" v-html="step"></li>
              </ol>
              <div class="help-title">🛠 常见问题排查</div>
              <ul>
                <li v-for="(faq, i) in ch.faqs" :key="i" v-html="faq"></li>
              </ul>
            </div>
          </div>
        </el-tab-pane>

        <!-- 占位频道：即将支持 -->
        <el-tab-pane v-for="p in placeholders" :key="p.key" :name="p.key">
          <template #label>
            <span>{{ p.label }}<el-tag type="info" size="small" style="margin-left: 6px">即将支持</el-tag></span>
          </template>
          <el-empty :description="`${p.label}频道正在开发中，敬请期待`" />
        </el-tab-pane>
      </el-tabs>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'
import QrcodeVue from 'qrcode.vue'
import { useRouter } from 'vue-router'
import { useUserStore } from '@stores/user'

const userStore = useUserStore()
const router = useRouter()

const ADMIN_API = '/api/v1/tenant/ibot/ibots'
const bindApi = (path: string) => `/api/v1/tenants/${userStore.tenantId}/ibot${path}`

// 可操作频道的字段与教程（数据驱动，避免 tab 模板重复）
const channels = [
  {
    key: 'wechat_work',
    label: '企业微信',
    bindHint: '扫码 → 确认身份 → 自动绑定，并推送「绑定成功」消息，点开消息即可对话。',
    // 凭证统一在「企业微信配置」页维护（代开发授权 / 自建凭证），创建机器人时自动带出，本页不重复填写
    fields: [],
    steps: [
      '在「企业微信配置」页完成接入：<b>代开发</b>扫码授权（推荐），或<b>自建应用</b>填写 Corp ID / Secret / Agent ID。',
      '回到本页点击「保存配置」创建机器人（凭证自动带出，无需重复填写）。',
      '点击「生成我的绑定码」，用<b>企业微信「扫一扫」</b>识别二维码。',
      '扫码后确认身份并点「确认绑定」，自动完成绑定并收到「绑定成功」推送消息，点开即可对话。',
    ],
    faqs: [
      '<b>提示企业微信尚未配置</b>：点击「去企业微信配置」完成代开发授权或自建凭证后返回本页。',
      '<b>扫码提示非企业成员</b>：确认扫码人是本企业成员，且在应用可见范围内。',
      '<b>绑定码无效</b>：绑定码一次性且有限期（默认 10 分钟），过期请重新生成。',
      '<b>发消息机器人无响应</b>：确认机器人为启用状态，发送人属于应用可见范围。',
    ],
  },
  {
    key: 'telegram',
    label: 'Telegram',
    bindHint: '在 Telegram 中向机器人发送绑定码，或点击一键绑定链接。',
    fields: [
      { key: 'bot_token', label: 'Bot Token', placeholder: '123456789:AAxxxxxxxx' },
      { key: 'bot_username', label: 'Bot Username', placeholder: 'my_assistant_bot（不含 @）', tip: '用于生成 t.me 一键绑定链接，可选' },
    ],
    steps: [
      '在 Telegram 中与 <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a> 对话，发送 <code>/newbot</code> 创建机器人。',
      '按提示设置名称与用户名，获得 <b>Bot Token</b> 填入本页并保存。',
      '点击「生成我的绑定码」，在 Telegram 中向机器人发送绑定码（或点击一键绑定链接）。',
      '绑定成功后即可在 Telegram 中随时与 AI 小助理对话。',
    ],
    faqs: [
      '<b>机器人无响应</b>：确认 Bot Token 正确、机器人处于启用状态；国内服务器需配置出站代理。',
      '<b>一键绑定链接无效</b>：Bot Username 未填写或与实际不符（不要带 @）。',
    ],
  },
]

const placeholders = [
  { key: 'wechat_kf', label: '微信客服' },
  { key: 'dingtalk', label: '钉钉' },
  { key: 'feishu', label: '飞书' },
]

const loading = ref(false)
const saving = ref(false)
const activeTab = ref('wechat_work')
const ibots = ref<any[]>([])
const bindings = ref<any[]>([])
const bindCodes = reactive<Record<string, any>>({})

// 表单初始化：每个频道 name + credentials（加载后填入掩码，用户改了才生效）
const forms = reactive<Record<string, { name: string; credentials: Record<string, string> }>>(
  Object.fromEntries(channels.map(ch => [ch.key, { name: '', credentials: Object.fromEntries(ch.fields.map(f => [f.key, ''])) }]))
)

const ibotOf = (channelType: string) => ibots.value.find(b => b.channel_type === channelType)

// ===== 企微接入状态（读「企业微信配置」页同源接口；仅展示，不在此配置） =====
const wechatWorkStatus = ref<any>(null)

const wechatSuiteAuthorized = computed(() => wechatWorkStatus.value?.status === 'authorized')

const wechatSelfConfigured = computed(() => {
  if (wechatSuiteAuthorized.value) return false
  // 自建模式：ibot 已带出企微凭证（corp_id 掩码非空）即视为已配置
  return !!ibotOf('wechat_work')?.credentials_masked?.corp_id
})

const wechatReady = computed(() => wechatSuiteAuthorized.value || wechatSelfConfigured.value)

const goWechatWorkSettings = () => router.push('/wechat-work')

const loadWechatWorkStatus = async () => {
  try {
    const res = await axios.get('/api/v1/tenant/wechat-work/status')
    wechatWorkStatus.value = res.data?.data || null
  } catch {
    wechatWorkStatus.value = null
  }
}

const bindingsOf = (channelType: string) => {
  const ibot = ibotOf(channelType)
  return ibot ? bindings.value.filter(b => String(b.ibot_id) === String(ibot.ibot_id)) : []
}

const statusText = (channelType: string) => {
  const ibot = ibotOf(channelType)
  return ibot ? (ibot.status === 'active' ? '已激活' : '已停用') : '未配置'
}

const statusTagType = (channelType: string) => {
  const ibot = ibotOf(channelType)
  return ibot?.status === 'active' ? 'success' : 'info'
}

const loadIbots = async () => {
  const res = await axios.get(ADMIN_API)
  ibots.value = res.data.data || []

  // 掩码回填表单：展示已配置状态，未修改字段提交时后端自动忽略
  for (const ch of channels) {
    const ibot = ibotOf(ch.key)
    if (!ibot) continue
    forms[ch.key].name = ibot.name || ''
    for (const f of ch.fields) {
      forms[ch.key].credentials[f.key] = ibot.credentials_masked?.[f.key] || ''
    }
  }

  // 默认定位到首个已配置频道
  const first = channels.find(ch => ibotOf(ch.key))
  if (first) activeTab.value = first.key
}

const loadBindings = async () => {
  try {
    const res = await axios.get(bindApi('/bindings'))
    bindings.value = res.data.data || []
  } catch {}
}

const load = async () => {
  loading.value = true
  try {
    await Promise.all([loadIbots(), loadBindings(), loadWechatWorkStatus()])
  } catch {
    ElMessage.error('加载配置失败')
  } finally {
    loading.value = false
  }
}

const handleSave = async (channelType: string) => {
  const ch = channels.find(c => c.key === channelType)!
  const form = forms[channelType]
  const ibot = ibotOf(channelType)

  // 掩码值原样提交，后端凭证局部合并会忽略（不覆盖既有明文）
  const payload = {
    name: form.name.trim() || `${ch.label}小助手`,
    credentials: form.credentials,
  }

  saving.value = true
  try {
    if (ibot) {
      await axios.put(`${ADMIN_API}/${ibot.ibot_id}`, payload)
    } else {
      await axios.post(ADMIN_API, { ...payload, channel_type: channelType })
    }
    ElMessage.success('保存成功')
    await loadIbots()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

const handleToggleStatus = async (channelType: string, active: boolean) => {
  const ibot = ibotOf(channelType)
  if (!ibot) return
  try {
    await axios.put(`${ADMIN_API}/${ibot.ibot_id}/status`, { status: active ? 'active' : 'disabled' })
    ElMessage.success(active ? '已启用' : '已停用')
    await loadIbots()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '操作失败')
  }
}

const handleBindCode = async (channelType: string) => {
  const ibot = ibotOf(channelType)
  if (!ibot) return
  try {
    const res = await axios.post(bindApi(`/ibots/${ibot.ibot_id}/bind-code`))
    bindCodes[channelType] = res.data.data
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '生成绑定码失败')
  }
}

const handleSetDefault = async (binding: any) => {
  try {
    await axios.put(bindApi(`/bindings/${binding.binding_id}/default`))
    ElMessage.success('已设为默认通道')
    await loadBindings()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '设置失败')
  }
}

const handleRevoke = async (binding: any) => {
  try {
    await ElMessageBox.confirm(`解除后需重新扫码绑定才能继续使用该渠道，确认解除「${binding.external_name || binding.external_id}」？`, '解除绑定', {
      type: 'warning',
    })
  } catch {
    return
  }
  try {
    await axios.delete(bindApi(`/bindings/${binding.binding_id}`))
    ElMessage.success('已解除绑定')
    for (const k of Object.keys(bindCodes)) {
      if (String(ibotOf(k)?.ibot_id) === String(binding.ibot_id)) delete bindCodes[k]
    }
    await loadBindings()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '解除失败')
  }
}

onMounted(load)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.tab-body { padding-top: 4px; }
.status-row { display: flex; align-items: center; justify-content: space-between; max-width: 620px; margin-bottom: 16px; font-size: 14px; }
.config-form { max-width: 620px; margin-bottom: 8px; }
.form-tip { font-size: 12px; color: var(--el-text-color-secondary); line-height: 1.5; margin-top: 4px; }
.bind-section { max-width: 620px; }
.bind-qr { margin-top: 10px; display: flex; align-items: center; gap: 12px; }
.bind-header { display: flex; align-items: center; justify-content: space-between; }
.help-box { margin-top: 20px; padding: 12px 16px; background: var(--el-fill-color-light); border-radius: 6px; font-size: 13px; line-height: 1.8; color: var(--el-text-color-regular); }
.help-title { font-weight: 600; margin: 4px 0; color: var(--el-text-color-primary); }
.help-box ol, .help-box ul { margin: 4px 0 12px; padding-left: 20px; }
.help-box :deep(code) { background: var(--el-fill-color); padding: 1px 6px; border-radius: 3px; word-break: break-all; }
.help-box :deep(a) { color: var(--el-color-primary); }
</style>
