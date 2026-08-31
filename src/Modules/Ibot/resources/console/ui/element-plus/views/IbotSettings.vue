<template>
  <div class="page">
    <div class="page-header">
      <h2>随身助理渠道</h2>
      <div class="form-tip">配置 IM 机器人渠道（企业微信 / Telegram）。成员的个人绑定在「我的随身助理」中自助完成。</div>
    </div>

    <el-card shadow="never" style="max-width: 860px" v-loading="loading">
      <el-tabs v-model="activeTab">
        <!-- 可操作频道：企业微信 / Telegram -->
        <el-tab-pane v-for="ch in channels" :key="ch.key" :name="ch.key">
          <template #label>
            <span>{{ ch.label }}<el-tag v-if="ibotOf(ch.key)" :type="statusTagType(ch.key)" size="small" style="margin-left: 6px">{{ statusText(ch.key) }}</el-tag></span>
          </template>

          <div class="tab-body">
            <!-- 状态行：机器人名称 + 绑定规模 + 启用开关 -->
            <div class="status-row">
              <span>
                {{ ibotOf(ch.key) ? `机器人「${ibotOf(ch.key).name}」` : '尚未配置机器人' }}
                <template v-if="ibotOf(ch.key)">
                  · {{ ibotOf(ch.key).active_bindings_count || 0 }} 个成员已绑定
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

            <!-- 成员绑定引导：个人操作已独立到「我的随身助理」页 -->
            <el-alert type="info" :closable="false" show-icon style="margin-bottom: 16px; max-width: 620px">
              <template #title>成员绑定在「我的随身助理」页完成</template>
              <div style="display: flex; align-items: center; gap: 12px">
                <span>成员扫码绑定个人 IM 账号、设置默认通道、解除绑定，均由成员在「我的随身助理」中自助完成，无需管理员介入。</span>
                <el-button size="small" type="primary" @click="goMyBindings">去我的随身助理</el-button>
              </div>
            </el-alert>

            <!-- 配置表单（凭证掩码显示，修改才提交） -->
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
import { ElMessage } from 'element-plus'
import { useRouter } from 'vue-router'
import { useUserStore } from '@stores/user'

const userStore = useUserStore()
const router = useRouter()

const ADMIN_API = '/api/v1/tenant/ibot/ibots'

// 可操作频道的字段与教程（数据驱动，避免 tab 模板重复）
const channels = [
  {
    key: 'wechat_work',
    label: '企业微信',
    // 凭证统一在「企业微信配置」页维护（代开发授权 / 自建凭证），创建机器人时自动带出，本页不重复填写
    fields: [],
    steps: [
      '在「企业微信配置」页完成接入：<b>代开发</b>扫码授权（推荐），或<b>自建应用</b>填写 Corp ID / Secret / Agent ID。',
      '回到本页点击「保存配置」创建机器人（凭证自动带出，无需重复填写）。',
      '创建并启用后，成员登录 console 前往「<b>我的随身助理</b>」扫码绑定个人账号，即可在企微中与机器人对话。',
    ],
    faqs: [
      '<b>提示企业微信尚未配置</b>：点击「去企业微信配置」完成代开发授权或自建凭证后返回本页。',
      '<b>成员扫码提示非企业成员</b>：确认扫码人是本企业成员，且在应用可见范围内。',
      '<b>成员反映绑定码无效</b>：绑定码一次性且有限期（默认 10 分钟），请在「我的随身助理」页重新生成。',
      '<b>发消息机器人无响应</b>：确认机器人为启用状态，发送人属于应用可见范围。',
    ],
  },
  {
    key: 'telegram',
    label: 'Telegram',
    fields: [
      { key: 'bot_token', label: 'Bot Token', placeholder: '123456789:AAxxxxxxxx' },
      { key: 'bot_username', label: 'Bot Username', placeholder: 'my_assistant_bot（不含 @）', tip: '用于生成 t.me 一键绑定链接，可选' },
    ],
    steps: [
      '在 Telegram 中与 <a href="https://t.me/BotFather" target="_blank" rel="noopener">@BotFather</a> 对话，发送 <code>/newbot</code> 创建机器人。',
      '按提示设置名称与用户名，获得 <b>Bot Token</b> 填入本页并保存。',
      '创建并启用后，成员登录 console 前往「<b>我的随身助理</b>」扫码或发送绑定码完成绑定。',
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

const goMyBindings = () => router.push('/my-ibot-bindings')

const loadWechatWorkStatus = async () => {
  try {
    const res = await axios.get('/api/v1/tenant/wechat-work/status')
    wechatWorkStatus.value = res.data?.data || null
  } catch {
    wechatWorkStatus.value = null
  }
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

const load = async () => {
  loading.value = true
  try {
    await Promise.all([loadIbots(), loadWechatWorkStatus()])
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

onMounted(load)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0 0 6px; }
.tab-body { padding-top: 4px; }
.status-row { display: flex; align-items: center; justify-content: space-between; max-width: 620px; margin-bottom: 16px; font-size: 14px; }
.config-form { max-width: 620px; margin-bottom: 8px; }
.form-tip { font-size: 12px; color: var(--el-text-color-secondary); line-height: 1.5; margin-top: 4px; }
.help-box { margin-top: 20px; padding: 12px 16px; background: var(--el-fill-color-light); border-radius: 6px; font-size: 13px; line-height: 1.8; color: var(--el-text-color-regular); }
.help-title { font-weight: 600; margin: 4px 0; color: var(--el-text-color-primary); }
.help-box ol, .help-box ul { margin: 4px 0 12px; padding-left: 20px; }
.help-box :deep(code) { background: var(--el-fill-color); padding: 1px 6px; border-radius: 3px; word-break: break-all; }
.help-box :deep(a) { color: var(--el-color-primary); }
</style>
