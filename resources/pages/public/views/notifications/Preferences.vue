<template>
  <div class="page">
    <div class="page-header">
      <h1 class="page-title">通知偏好</h1>
      <router-link to="/notifications" class="btn-back">← 返回通知列表</router-link>
    </div>

    <div class="panel">
      <div v-if="msg" :class="['alert', success ? 'alert-success' : 'alert-error']">{{ msg }}</div>
      <div v-if="loading" class="empty">加载中...</div>
      <div v-else-if="preferences.length === 0" class="empty">暂无偏好设置</div>
      <div v-else class="pref-list">
        <div v-for="(pref, idx) in preferences" :key="idx" class="pref-item">
          <div class="pref-info">
            <span class="pref-channel">{{ channelLabel(pref.channel) }}</span>
            <span v-if="pref.type" class="pref-type">{{ pref.type }}</span>
          </div>
          <label class="switch">
            <input type="checkbox" v-model="pref.enabled" @change="savePref(pref)" />
            <span class="slider"></span>
          </label>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'

const loading = ref(true)
const preferences = ref<any[]>([])
const msg = ref('')
const success = ref(false)

onMounted(async () => {
  const token = localStorage.getItem('user_token')
  try {
    const res = await fetch('/api/v1/in-app-notifications/preferences', {
      headers: { Authorization: `Bearer ${token}` },
    })
    const data = await res.json()
    if (data.success) preferences.value = data.data || []
  } catch {}
  loading.value = false
})

function channelLabel(channel: string): string {
  const labels: Record<string, string> = {
    email: '邮件通知',
    sms: '短信通知',
    in_app: '站内通知',
    wechat: '微信通知',
  }
  return labels[channel] || channel
}

async function savePref(pref: any) {
  msg.value = ''
  const token = localStorage.getItem('user_token')
  try {
    const res = await fetch('/api/v1/in-app-notifications/preferences', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify({ channel: pref.channel, type: pref.type, enabled: pref.enabled }),
    })
    const data = await res.json()
    success.value = data.success
    msg.value = data.success ? '已保存' : (data.message || '保存失败')
  } catch {
    success.value = false
    msg.value = '网络错误'
  }
}
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.page-title { font-size: 24px; }
.btn-back { font-size: 13px; color: #1976d2; text-decoration: none; }
.panel { background: #fff; border-radius: 8px; padding: 24px; max-width: 560px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
.pref-item { display: flex; justify-content: space-between; align-items: center; padding: 14px 0; border-bottom: 1px solid #f0f0f0; }
.pref-info { display: flex; flex-direction: column; }
.pref-channel { font-size: 14px; font-weight: 500; }
.pref-type { font-size: 12px; color: #999; }
.switch { position: relative; width: 44px; height: 24px; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider { position: absolute; inset: 0; background: #ccc; border-radius: 24px; cursor: pointer; transition: 0.3s; }
.slider::before { content: ''; position: absolute; width: 18px; height: 18px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: 0.3s; }
.switch input:checked + .slider { background: #1976d2; }
.switch input:checked + .slider::before { transform: translateX(20px); }
.empty { color: #999; font-size: 14px; padding: 16px 0; }
.alert { padding: 10px 14px; border-radius: 6px; margin-bottom: 14px; font-size: 14px; }
.alert-success { background: #e8f5e9; color: #2e7d32; }
.alert-error { background: #fbe9e7; color: #c62828; }
</style>
