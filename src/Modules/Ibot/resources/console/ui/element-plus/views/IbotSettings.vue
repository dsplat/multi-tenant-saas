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

            <!-- 凭证表单（掩码显示，修改才提交） -->
            <el-form label-width="140px" class="config-form">
              <el-form-item label="机器人名称">
                <el-input v-model="forms[ch.key].name" :placeholder="`${ch.label}小助手`" />
              </el-form-item>
              <el-form-item v-for="f in ch.fields" :key="f.key" :label="f.label">
                <el-input v-model="forms[ch.key].credentials[f.key]" :placeholder="f.placeholder" />
                <div v-if="f.tip" class="form-tip">{{ f.tip }}</div>
              </el-form-item>
              <el-form-item v-if="ibotOf(ch.key)?.webhook_url" label="回调 URL">
                <el-input :model-value="ibotOf(ch.key).webhook_url" readonly>
                  <template #append>
                    <el-button @click="copyText(ibotOf(ch.key).webhook_url)">复制</el-button>
                  </template>
                </el-input>
                <div class="form-tip">填入企业微信后台「接收消息」的 URL 字段，与 Token / EncodingAESKey 保持一致</div>
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
                  <div class="form-tip">{{ ch.bindHint }}</div>
                </el-alert>

                <el-table v-if="bindingsOf(ch.key).length" :data="bindingsOf(ch.key)" size="small" style="margin-top: 8px">
                  <el-table-column prop="external_id" label="IM 账号" min-width="140" />
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
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'
import { useUserStore } from '@stores/user'

const userStore = useUserStore()

const ADMIN_API = '/api/v1/tenant/ibot/ibots'
const bindApi = (path: string) => `/api/v1/tenants/${userStore.tenantId}/ibot${path}`

// 可操作频道的字段与教程（数据驱动，避免 tab 模板重复）
const channels = [
  {
    key: 'wechat_work',
    label: '企业微信',
    bindHint: '在企业微信中打开该自建应用的聊天窗口，直接发送绑定码即可完成绑定。',
    fields: [
      { key: 'corp_id', label: 'Corp ID', placeholder: 'ww1234567890abcdef' },
      { key: 'corp_secret', label: 'Corp Secret', placeholder: '自建应用的 Secret' },
      { key: 'agent_id', label: 'Agent ID', placeholder: '1000001' },
      { key: 'token', label: 'Token', placeholder: '企微「接收消息」页生成', tip: '在企微后台「接收消息」设置页生成，与本页保持一致' },
      { key: 'encoding_aes_key', label: 'EncodingAESKey', placeholder: '企微「接收消息」页生成（43 位）' },
    ],
    steps: [
      '管理员登录 <a href="https://work.weixin.qq.com/wework_admin/" target="_blank" rel="noopener">企业微信管理后台</a> →「应用管理」→「自建」→「创建应用」（已有可复用）。',
      '应用详情页复制 <b>AgentId</b> 与 <b>Secret</b>；「我的企业」页复制 <b>企业 ID（CorpID）</b>，填入本页。',
      '应用详情页 →「开发者接口」→「企业可信 IP」，添加本系统服务器的<b>出口 IP</b>。',
      '应用详情页 →「接收消息」→「设置 API 接收」：随机生成 <b>Token</b> 与 <b>EncodingAESKey</b> 填入本页并<b>先保存配置</b>。',
      '把本页出现的「回调 URL」填入企微「接收消息」的 URL 字段，点击保存通过 URL 验证。',
      '点击「生成我的绑定码」，在企业微信中打开该应用并发送绑定码，即可开始与 AI 小助理对话。',
    ],
    faqs: [
      '<b>URL 验证失败</b>：先在本页保存 Token / EncodingAESKey 再到企微侧验证；两侧必须完全一致。',
      '<b>报错 60020 not allow to access from your ip</b>：服务器出口 IP 未加入「企业可信 IP」。',
      '<b>发消息机器人无响应</b>：确认应用「接收消息」已开启、发送人属于应用可见范围、机器人处于启用状态。',
      '<b>绑定码无效</b>：绑定码一次性且有限期（默认 10 分钟），过期请重新生成。',
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
    await Promise.all([loadIbots(), loadBindings()])
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

const copyText = async (text: string) => {
  try {
    await navigator.clipboard.writeText(text)
    ElMessage.success('已复制')
  } catch {
    ElMessage.error('复制失败，请手动选择复制')
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
.bind-header { display: flex; align-items: center; justify-content: space-between; }
.help-box { margin-top: 20px; padding: 12px 16px; background: var(--el-fill-color-light); border-radius: 6px; font-size: 13px; line-height: 1.8; color: var(--el-text-color-regular); }
.help-title { font-weight: 600; margin: 4px 0; color: var(--el-text-color-primary); }
.help-box ol, .help-box ul { margin: 4px 0 12px; padding-left: 20px; }
.help-box :deep(code) { background: var(--el-fill-color); padding: 1px 6px; border-radius: 3px; word-break: break-all; }
.help-box :deep(a) { color: var(--el-color-primary); }
</style>
