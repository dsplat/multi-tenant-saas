<template>
  <div class="sms-page">
    <div class="page-header"><h2>短信配置</h2></div>

    <div class="panel">
      <form @submit.prevent="handleSave">
        <div class="form-group">
          <label>短信驱动</label>
          <select v-model="config.driver">
            <option value="log">仅日志（测试用）</option>
            <option value="sms">SMS</option>
          </select>
        </div>

        <template v-if="config.driver === 'sms'">
          <div class="form-group">
            <label>网关地址</label>
            <input v-model="config.sms_endpoint" placeholder="https://sms.example.com/api/send" />
          </div>
          <div class="form-row">
            <div class="form-group">
              <label>Access Key</label>
              <input v-model="config.sms_access_key" />
            </div>
            <div class="form-group">
              <label>Secret Key</label>
              <input v-model="config.sms_secret_key" type="password" placeholder="******" />
            </div>
          </div>
          <div class="form-group">
            <label>签名</label>
            <input v-model="config.sms_sign" placeholder="签名" />
          </div>
        </template>

        <div class="form-actions">
          <button type="submit" class="primary-btn" :disabled="saving">保存配置</button>
          <div class="test-area" v-if="config.driver !== 'log'">
            <input v-model="testPhone" placeholder="测试手机号" />
            <button type="button" class="secondary-btn" @click="handleTest" :disabled="testing">
              {{ testing ? '发送中...' : '测试发送' }}
            </button>
          </div>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'

const saving = ref(false)
const testing = ref(false)
const testPhone = ref('')

const config = reactive({
  driver: 'log',
  sms_endpoint: '',
  sms_access_key: '',
  sms_secret_key: '',
  sms_sign: '',
})

const getTenantId = () => {
  try { return JSON.parse(localStorage.getItem('console_user') || '{}').tenant_id } catch { return null }
}

const loadConfig = async () => {
  const tenantId = getTenantId()
  if (!tenantId) return
  try {
    const res = await axios.get(`/api/v1/tenants/${tenantId}/settings/sms`)
    if (res.data.data) Object.assign(config, res.data.data)
  } catch {}
}

const handleSave = async () => {
  const tenantId = getTenantId()
  if (!tenantId) return
  saving.value = true
  try {
    await axios.put(`/api/v1/tenants/${tenantId}/settings/sms`, config)
    alert('保存成功')
  } catch (e: any) {
    alert(e.response?.data?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

const handleTest = async () => {
  const tenantId = getTenantId()
  if (!tenantId || !testPhone.value) return
  testing.value = true
  try {
    await axios.post(`/api/v1/tenants/${tenantId}/settings/sms/test`, { phone: testPhone.value })
    alert('测试短信已发送')
  } catch (e: any) {
    alert(e.response?.data?.message || '发送失败')
  } finally {
    testing.value = false
  }
}

onMounted(loadConfig)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; max-width: 600px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 6px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input, .form-group select { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 14px; box-sizing: border-box; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-actions { display: flex; align-items: center; gap: 16px; margin-top: 24px; flex-wrap: wrap; }
.test-area { display: flex; gap: 8px; }
.test-area input { width: 160px; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 13px; }
.primary-btn { padding: 8px 20px; border: none; border-radius: 6px; background: var(--primary-color, #409eff); color: #fff; cursor: pointer; font-size: 13px; }
.secondary-btn { padding: 8px 16px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; background: var(--bg-color, #fff); cursor: pointer; font-size: 13px; }
.primary-btn:disabled, .secondary-btn:disabled { opacity: 0.6; cursor: not-allowed; }
</style>
