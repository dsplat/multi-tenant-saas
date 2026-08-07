<template>
  <div class="sms-page">
    <div class="page-header"><h2>短信配置</h2></div>

    <div class="panel">
      <div v-if="!tenantStore.hasTenant" class="empty-state">
        <h3>请先选择团队</h3>
        <p>在顶部导航栏选择一个团队后，即可配置该团队的短信。</p>
      </div>

      <div v-else class="config-section">
        <div class="config-card">
          <div class="config-header">
            <h4>短信驱动</h4>
          </div>
          <div class="config-body">
            <div class="form-group">
              <label>驱动类型</label>
              <select v-model="config.driver">
                <option value="log">Log（日志）</option>
                <option value="sms">SMS</option>
              </select>
            </div>

            <template v-if="config.driver === 'sms'">
              <div class="form-group">
                <label>API URL</label>
                <input v-model="config.sms.api_url" placeholder="https://api.example.com/sms" />
              </div>
              <div class="form-group">
                <label>Access Key</label>
                <input v-model="config.sms.access_key" />
              </div>
              <div class="form-group">
                <label>Secret Key</label>
                <input v-model="config.sms.secret_key" type="password" placeholder="******" />
              </div>
              <div class="form-group">
                <label>签名</label>
                <input v-model="config.sms.sign_name" />
              </div>
            </template>
          </div>
        </div>

        <div class="config-card">
          <div class="config-header">
            <h4>测试发送</h4>
          </div>
          <div class="config-body">
            <div class="form-group">
              <label>手机号</label>
              <input v-model="testPhone" placeholder="13800138000" />
            </div>
            <button class="primary-btn" @click="handleTest" :disabled="!testPhone">发送测试短信</button>
            <span v-if="testResult" :class="['test-msg', testResult.ok ? 'test-ok' : 'test-fail']">{{ testResult.msg }}</span>
          </div>
        </div>

        <button class="primary-btn" @click="handleSave">保存配置</button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted, watch } from 'vue'
import axios from 'axios'
// 租户上下文统一走头部团队选择器（tenantStore），页面内不再自建选择器
import { useTenantStore } from '@stores/tenant'

const tenantStore = useTenantStore()
const testPhone = ref('')
const testResult = ref<{ ok: boolean; msg: string } | null>(null)

const config = reactive({
  driver: 'log',
  sms: { api_url: '', access_key: '', secret_key: '', sign_name: '' },
})

const loadConfig = async () => {
  if (!tenantStore.hasTenant) return
  testResult.value = null
  try {
    const res = await axios.get(`/api/v1/tenants/${tenantStore.tenantId}/settings/sms`)
    const data = res.data.data || {}
    if (data.driver) config.driver = data.driver
    if (data.sms) Object.assign(config.sms, data.sms)
  } catch {}
}

const handleSave = async () => {
  try {
    await axios.put(`/api/v1/tenants/${tenantStore.tenantId}/settings/sms`, config)
    alert('保存成功')
  } catch {
    alert('保存失败')
  }
}

const handleTest = async () => {
  testResult.value = null
  try {
    const res = await axios.post(`/api/v1/tenants/${tenantStore.tenantId}/settings/sms/test`, { phone: testPhone.value })
    testResult.value = { ok: true, msg: res.data.message || '发送成功' }
  } catch (e: any) {
    testResult.value = { ok: false, msg: e.response?.data?.message || '发送失败' }
  }
}

onMounted(() => { if (tenantStore.hasTenant) loadConfig() })
watch(() => tenantStore.tenantId, () => { if (tenantStore.hasTenant) loadConfig() })
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.empty-state { text-align: center; padding: 48px 24px; color: var(--text-color-secondary, #666); }
.empty-state h3 { margin: 0 0 8px; color: var(--text-color-primary, #333); }
.empty-state p { margin: 0; font-size: 13px; }
.config-section { display: flex; flex-direction: column; gap: 16px; }
.config-card { border: 1px solid var(--border-color, #eee); border-radius: 8px; overflow: hidden; }
.config-header { display: flex; justify-content: space-between; align-items: center; padding: 16px; background: var(--fill-color, #f9f9f9); }
.config-header h4 { margin: 0; font-size: 14px; }
.config-body { padding: 16px; }
.form-group { margin-bottom: 12px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 12px; color: var(--text-color-secondary, #999); }
.form-group input, .form-group select { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 13px; box-sizing: border-box; }
.primary-btn { padding: 10px 24px; border: none; border-radius: 6px; background: var(--primary-color, #409eff); color: #fff; cursor: pointer; font-size: 14px; margin-top: 8px; }
.primary-btn:disabled { opacity: 0.5; cursor: not-allowed; }
.test-msg { margin-left: 12px; font-size: 13px; }
.test-ok { color: #2e7d32; }
.test-fail { color: #c62828; }
</style>
