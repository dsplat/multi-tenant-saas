<template>
  <div class="sms-page">
    <div class="page-header"><h2>短信配置</h2></div>

    <div class="panel">
      <div class="tenant-select">
        <label>选择租户：</label>
        <select v-model="selectedTenantId" @change="loadConfig">
          <option value="">请选择</option>
          <option v-for="t in tenants" :key="t.tenant_id" :value="t.tenant_id">{{ t.name }}</option>
        </select>
      </div>

      <div v-if="selectedTenantId" class="config-section">
        <div class="config-card">
          <div class="config-header">
            <h4>短信驱动</h4>
          </div>
          <div class="config-body">
            <div class="form-group">
              <label>驱动类型</label>
              <select v-model="config.driver">
                <option value="log">Log（日志）</option>
                <option value="ww">旺旺</option>
                <option value="mtedu">MTEDU</option>
              </select>
            </div>

            <template v-if="config.driver === 'ww'">
              <div class="form-group">
                <label>API URL</label>
                <input v-model="config.ww.api_url" placeholder="https://api.example.com/sms" />
              </div>
              <div class="form-group">
                <label>App Key</label>
                <input v-model="config.ww.app_key" />
              </div>
              <div class="form-group">
                <label>App Secret</label>
                <input v-model="config.ww.app_secret" type="password" placeholder="******" />
              </div>
              <div class="form-group">
                <label>签名</label>
                <input v-model="config.ww.sign_name" />
              </div>
            </template>

            <template v-if="config.driver === 'mtedu'">
              <div class="form-group">
                <label>API URL</label>
                <input v-model="config.mtedu.api_url" placeholder="https://api.example.com/sms" />
              </div>
              <div class="form-group">
                <label>Access Key</label>
                <input v-model="config.mtedu.access_key" />
              </div>
              <div class="form-group">
                <label>Secret Key</label>
                <input v-model="config.mtedu.secret_key" type="password" placeholder="******" />
              </div>
              <div class="form-group">
                <label>模板 ID</label>
                <input v-model="config.mtedu.template_id" />
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
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'

const tenants = ref<any[]>([])
const selectedTenantId = ref('')
const testPhone = ref('')
const testResult = ref<{ ok: boolean; msg: string } | null>(null)

const config = reactive({
  driver: 'log',
  ww: { api_url: '', app_key: '', app_secret: '', sign_name: '' },
  mtedu: { api_url: '', access_key: '', secret_key: '', template_id: '' },
})

const fetchTenants = async () => {
  try {
    const res = await axios.get('/api/v1/tenants')
    tenants.value = res.data.data || []
  } catch {}
}

const loadConfig = async () => {
  if (!selectedTenantId.value) return
  testResult.value = null
  try {
    const res = await axios.get(`/api/v1/tenants/${selectedTenantId.value}/settings/sms`)
    const data = res.data.data || {}
    if (data.driver) config.driver = data.driver
    if (data.ww) Object.assign(config.ww, data.ww)
    if (data.mtedu) Object.assign(config.mtedu, data.mtedu)
  } catch {}
}

const handleSave = async () => {
  try {
    await axios.put(`/api/v1/tenants/${selectedTenantId.value}/settings/sms`, config)
    alert('保存成功')
  } catch {
    alert('保存失败')
  }
}

const handleTest = async () => {
  testResult.value = null
  try {
    const res = await axios.post(`/api/v1/tenants/${selectedTenantId.value}/settings/sms/test`, { phone: testPhone.value })
    testResult.value = { ok: true, msg: res.data.message || '发送成功' }
  } catch (e: any) {
    testResult.value = { ok: false, msg: e.response?.data?.message || '发送失败' }
  }
}

onMounted(fetchTenants)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.tenant-select { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
.tenant-select label { font-size: 14px; color: var(--text-color-secondary, #666); }
.tenant-select select { padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; min-width: 200px; }
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
