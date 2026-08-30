<template>
  <div class="page">
    <div class="page-header">
      <h2>我的随身助理</h2>
      <div class="form-tip">绑定后可在企业微信 / Telegram 等 IM 中随时与 AI 小助理对话。每个渠道由租户管理员配置，您只需扫码绑定个人账号（每个渠道一人一个）。</div>
    </div>

    <div v-loading="loading">
      <el-empty v-if="!loading && !ibots.length" description="租户管理员尚未配置任何渠道，请联系管理员在「随身助理渠道」中配置" />
      <div v-for="ch in channels" :key="ch.key" class="channel-card">
        <el-card shadow="never">
          <div class="channel-head">
            <div class="channel-title">
              <span class="channel-icon">{{ ch.icon }}</span>
              <div>
                <div class="channel-name">{{ ch.label }}<el-tag :type="statusTagType(ch)" size="small" style="margin-left: 8px">{{ statusText(ch) }}</el-tag></div>
                <div v-if="ibotOf(ch.key)" class="form-tip">{{ ibotOf(ch.key).name }}</div>
              </div>
            </div>
          </div>

          <div class="channel-body">
            <!-- 已绑定状态 -->
            <div v-if="bindingOf(ch.key)" class="bound-row">
              <div>
                <span class="bound-label">已绑定 IM 账号：</span><b>{{ bindingOf(ch.key).external_id }}</b>
                <el-tag v-if="bindingOf(ch.key).is_default_channel" type="warning" size="small" style="margin-left: 8px">默认通道</el-tag>
              </div>
              <div class="bound-actions">
                <el-button v-if="!bindingOf(ch.key).is_default_channel" size="small" @click="handleSetDefault(bindingOf(ch.key))">设为默认</el-button>
                <el-button size="small" type="danger" plain @click="handleRevoke(bindingOf(ch.key))">解除绑定</el-button>
              </div>
            </div>

            <!-- 未绑定：生成绑定码 -->
            <div v-else-if="ibotOf(ch.key)" class="unbound-row">
              <el-button type="primary" size="small" :loading="generating === ch.key" @click="handleBindCode(ch.key)">生成绑定码</el-button>
              <span class="form-tip" style="margin-left: 8px">{{ ch.bindHint }}</span>
            </div>
            <div v-else class="form-tip">该渠道未配置机器人，请联系租户管理员。</div>

            <!-- 绑定码 + 二维码 -->
            <el-alert v-if="bindCodes[ch.key]" type="success" :closable="false" style="margin-top: 12px">
              <template #title>
                绑定码：<b>{{ bindCodes[ch.key].code }}</b>（{{ Math.round((bindCodes[ch.key].expires_in || 600) / 60) }} 分钟内有效，一次性使用）
              </template>
              <div v-if="bindCodes[ch.key].bind_qr" class="bind-qr">
                <qrcode-vue :value="bindCodes[ch.key].bind_qr" :size="128" level="M" />
                <div class="form-tip">{{ ch.qrTip }}</div>
              </div>
              <div class="form-tip">{{ ch.bindSteps }}</div>
            </el-alert>
          </div>
        </el-card>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'
import QrcodeVue from 'qrcode.vue'
import { useUserStore } from '@stores/user'

const userStore = useUserStore()
const bindApi = (path: string) => `/api/v1/tenants/${userStore.tenantId}/ibot${path}`

const channels = [
  {
    key: 'wechat_work',
    label: '企业微信',
    icon: '💬',
    bindHint: '企业微信「扫一扫」识别二维码获取绑定码，打开机器人应用发送即可绑定。',
    qrTip: '用企业微信「扫一扫」识别二维码，会识别出绑定码文本。',
    bindSteps: '打开企业微信 → 消息列表找到机器人应用（如「蓝眼兔会员Club」）→ 发送绑定码完成绑定。',
  },
  {
    key: 'telegram',
    label: 'Telegram',
    icon: '✈️',
    bindHint: '在 Telegram 中向机器人发送绑定码，或点击一键绑定链接。',
    qrTip: '用 Telegram 扫一扫可直达机器人会话（或复制绑定码手动发送）。',
    bindSteps: '打开 Telegram → 向机器人发送绑定码（或扫码直达后直接发送）完成绑定。',
  },
  {
    key: 'wechat_kf',
    label: '微信客服',
    icon: '🎧',
  },
  {
    key: 'dingtalk',
    label: '钉钉',
    icon: '📌',
  },
  {
    key: 'feishu',
    label: '飞书',
    icon: '🦤',
  },
]

const loading = ref(false)
const generating = ref('')
const ibots = ref<any[]>([])
const bindings = ref<any[]>([])
const bindCodes = reactive<Record<string, any>>({})

const ibotOf = (channelType: string) => ibots.value.find(b => b.channel_type === channelType)

const bindingOf = (channelType: string) => {
  const ibot = ibotOf(channelType)
  return ibot ? bindings.value.find(b => String(b.ibot_id) === String(ibot.ibot_id) && b.status === 'active') : undefined
}

const statusText = (ch: any) => {
  const ibot = ibotOf(ch.key)
  return ibot ? (bindingOf(ch.key) ? '已绑定' : '未绑定') : '未配置'
}

const statusTagType = (ch: any) => {
  const ibot = ibotOf(ch.key)
  if (!ibot) return 'info'
  return bindingOf(ch.key) ? 'success' : 'warning'
}

const load = async () => {
  loading.value = true
  try {
    const [ibotRes, bindRes] = await Promise.all([
      axios.get(bindApi('/ibots')),
      axios.get(bindApi('/bindings')),
    ])
    ibots.value = ibotRes.data.data || []
    bindings.value = bindRes.data.data || []
  } catch {
    ElMessage.error('加载绑定信息失败')
  } finally {
    loading.value = false
  }
}

const handleBindCode = async (channelType: string) => {
  const ibot = ibotOf(channelType)
  if (!ibot) return
  generating.value = channelType
  try {
    const res = await axios.post(bindApi(`/ibots/${ibot.ibot_id}/bind-code`))
    bindCodes[channelType] = res.data.data
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '生成绑定码失败')
  } finally {
    generating.value = ''
  }
}

const handleSetDefault = async (binding: any) => {
  try {
    await axios.put(bindApi(`/bindings/${binding.binding_id}/default`))
    ElMessage.success('已设为默认通道')
    await load()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '设置失败')
  }
}

const handleRevoke = async (binding: any) => {
  try {
    await ElMessageBox.confirm(`解除后需重新扫码绑定才能继续使用该渠道，确认解除「${binding.external_id}」？`, '解除绑定', {
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
    await load()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '解除失败')
  }
}

onMounted(load)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0 0 6px; }
.form-tip { font-size: 12px; color: var(--el-text-color-secondary); line-height: 1.5; margin-top: 4px; }
.channel-card { max-width: 720px; margin-bottom: 14px; }
.channel-head { margin-bottom: 4px; }
.channel-title { display: flex; align-items: center; gap: 10px; }
.channel-icon { font-size: 26px; }
.channel-name { font-size: 15px; font-weight: 600; }
.channel-body { padding: 4px 0 0 36px; }
.bound-row { display: flex; align-items: center; justify-content: space-between; flex-wrap: wrap; gap: 8px; }
.bound-label { color: var(--el-text-color-secondary); font-size: 13px; }
.bound-actions { display: flex; gap: 8px; }
.unbound-row { display: flex; align-items: center; flex-wrap: wrap; }
.bind-qr { margin-top: 10px; display: flex; align-items: center; gap: 12px; }
</style>
