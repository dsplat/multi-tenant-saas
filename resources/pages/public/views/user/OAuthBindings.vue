<template>
  <div class="page">
    <h1 class="page-title">第三方账号</h1>
    <div class="panel">
      <div v-if="msg" :class="['alert', success ? 'alert-success' : 'alert-error']">{{ msg }}</div>

      <div v-if="loading" class="empty">加载中...</div>
      <div v-else-if="bindings.length === 0" class="empty">尚未绑定任何第三方账号</div>
      <div v-else class="binding-list">
        <div v-for="item in bindings" :key="item.id" class="binding-item">
          <div class="binding-info">
            <span class="binding-provider">{{ providerLabel(item.provider) }}</span>
            <span class="binding-nick">{{ item.nickname || item.provider_user_id }}</span>
          </div>
          <span class="binding-time">{{ formatDate(item.created_at) }}</span>
          <button class="btn-sm btn-outline-danger" @click="unbind(item.provider)">解绑</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'

const loading = ref(true)
const bindings = ref<any[]>([])
const msg = ref('')
const success = ref(false)

onMounted(async () => {
  const token = localStorage.getItem('user_token')
  try {
    const res = await fetch('/api/v1/auth/oauth-bindings', {
      headers: { Authorization: `Bearer ${token}` },
    })
    const data = await res.json()
    if (data.success) bindings.value = data.data || []
  } catch {}
  loading.value = false
})

function providerLabel(provider: string): string {
  const labels: Record<string, string> = {
    wechat: '微信',
    wechat_work: '企业微信',
    alipay: '支付宝',
    github: 'GitHub',
    google: 'Google',
    dingtalk: '钉钉',
    feishu: '飞书',
  }
  return labels[provider] || provider
}

function formatDate(dateStr: string): string {
  if (!dateStr) return ''
  return new Date(dateStr).toLocaleDateString('zh-CN')
}

async function unbind(provider: string) {
  if (!confirm(`确定要解绑 ${providerLabel(provider)} 账号吗？`)) return

  const token = localStorage.getItem('user_token')
  try {
    const res = await fetch(`/api/v1/auth/oauth-bindings/${provider}`, {
      method: 'DELETE',
      headers: { Authorization: `Bearer ${token}` },
    })
    const data = await res.json()
    success.value = data.success
    msg.value = data.message || (data.success ? '已解绑' : '解绑失败')
    if (data.success) {
      bindings.value = bindings.value.filter(b => b.provider !== provider)
    }
  } catch {
    success.value = false
    msg.value = '网络错误'
  }
}
</script>

<style scoped>
.page-title { font-size: 24px; margin-bottom: 24px; }
.panel { background: #fff; border-radius: 8px; padding: 24px; max-width: 560px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
.empty { color: #999; font-size: 14px; padding: 16px 0; }
.binding-item { display: flex; align-items: center; gap: 12px; padding: 14px 0; border-bottom: 1px solid #f0f0f0; }
.binding-info { flex: 1; display: flex; flex-direction: column; }
.binding-provider { font-size: 14px; font-weight: 500; }
.binding-nick { font-size: 12px; color: #999; }
.binding-time { font-size: 12px; color: #999; }
.btn-sm { padding: 5px 12px; font-size: 12px; border-radius: 4px; cursor: pointer; }
.btn-outline-danger { background: #fff; border: 1px solid #e53935; color: #e53935; }
.btn-outline-danger:hover { background: #fbe9e7; }
.alert { padding: 10px 14px; border-radius: 6px; margin-bottom: 14px; font-size: 14px; }
.alert-success { background: #e8f5e9; color: #2e7d32; }
.alert-error { background: #fbe9e7; color: #c62828; }
</style>
