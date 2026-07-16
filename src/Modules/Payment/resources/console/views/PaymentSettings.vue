<template>
  <div class="payment-page">
    <div class="page-header"><h2>支付配置</h2></div>

    <div class="panel">
      <div class="config-section">
        <!-- 微信支付 -->
        <div class="config-card">
          <div class="config-header">
            <h4>微信支付</h4>
            <span :class="['badge', config.wechat.configured ? 'badge-success' : 'badge-info']">
              {{ config.wechat.configured ? '已配置' : '未配置' }}
            </span>
          </div>
          <div class="config-body">
            <div class="form-group">
              <label>App ID</label>
              <input v-model="wechatForm.app_id" placeholder="wx1234567890" />
            </div>
            <div class="form-group">
              <label>商户号 (Mch ID)</label>
              <input v-model="wechatForm.mch_id" placeholder="1234567890" />
            </div>
            <div class="form-group">
              <label>API 证书序列号</label>
              <input v-model="wechatForm.serial_no" />
            </div>
            <div class="form-group">
              <label>API 私钥</label>
              <textarea v-model="wechatForm.private_key" rows="3" placeholder="-----BEGIN PRIVATE KEY-----"></textarea>
            </div>
            <div class="form-group">
              <label>回调地址</label>
              <input v-model="wechatForm.notify_url" placeholder="https://your-domain.com/api/v1/pay/wechat/notify" />
            </div>
            <button class="primary-btn" @click="handleSaveWechat" :disabled="saving">保存微信配置</button>
          </div>
        </div>

        <!-- 支付宝 -->
        <div class="config-card">
          <div class="config-header">
            <h4>支付宝</h4>
            <span :class="['badge', config.alipay.configured ? 'badge-success' : 'badge-info']">
              {{ config.alipay.configured ? '已配置' : '未配置' }}
            </span>
          </div>
          <div class="config-body">
            <div class="form-group">
              <label>App ID</label>
              <input v-model="alipayForm.app_id" placeholder="2021001234567890" />
            </div>
            <div class="form-group">
              <label>支付宝公钥</label>
              <textarea v-model="alipayForm.ali_public_key" rows="3" placeholder="-----BEGIN PUBLIC KEY-----"></textarea>
            </div>
            <div class="form-group">
              <label>应用私钥</label>
              <textarea v-model="alipayForm.private_key" rows="3" placeholder="-----BEGIN PRIVATE KEY-----"></textarea>
            </div>
            <div class="form-group">
              <label>回调地址</label>
              <input v-model="alipayForm.notify_url" placeholder="https://your-domain.com/api/v1/pay/alipay/notify" />
            </div>
            <div class="form-group">
              <label>模式</label>
              <select v-model="alipayForm.mode">
                <option value="normal">正式环境</option>
                <option value="sandbox">沙箱环境</option>
              </select>
            </div>
            <button class="primary-btn" @click="handleSaveAlipay" :disabled="saving">保存支付宝配置</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'

const saving = ref(false)
const config = reactive({
  wechat: { configured: false },
  alipay: { configured: false },
})

const wechatForm = reactive({
  app_id: '',
  mch_id: '',
  serial_no: '',
  private_key: '',
  notify_url: '',
})

const alipayForm = reactive({
  app_id: '',
  ali_public_key: '',
  private_key: '',
  notify_url: '',
  mode: 'normal',
})

const getTenantId = () => {
  try { return JSON.parse(localStorage.getItem('console_user') || '{}').tenant_id } catch { return null }
}

const loadConfig = async () => {
  const tenantId = getTenantId()
  if (!tenantId) return
  try {
    const res = await axios.get(`/api/v1/tenants/${tenantId}/payment/config`)
    const data = res.data.data || {}
    if (data.wechat) {
      config.wechat.configured = data.wechat.configured
      wechatForm.app_id = data.wechat.app_id || ''
      wechatForm.mch_id = data.wechat.mch_id || ''
    }
    if (data.alipay) {
      config.alipay.configured = data.alipay.configured
      alipayForm.app_id = data.alipay.app_id || ''
    }
  } catch {}
}

const handleSaveWechat = async () => {
  const tenantId = getTenantId()
  if (!tenantId) return
  saving.value = true
  try {
    await axios.put(`/api/v1/tenants/${tenantId}/payment/wechat`, wechatForm)
    alert('微信支付配置已保存')
    loadConfig()
  } catch (e: any) {
    alert(e.response?.data?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

const handleSaveAlipay = async () => {
  const tenantId = getTenantId()
  if (!tenantId) return
  saving.value = true
  try {
    await axios.put(`/api/v1/tenants/${tenantId}/payment/alipay`, alipayForm)
    alert('支付宝配置已保存')
    loadConfig()
  } catch (e: any) {
    alert(e.response?.data?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

onMounted(loadConfig)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.config-section { display: flex; flex-direction: column; gap: 16px; }
.config-card { border: 1px solid var(--border-color, #eee); border-radius: 8px; overflow: hidden; }
.config-header { display: flex; justify-content: space-between; align-items: center; padding: 16px; background: var(--fill-color, #f9f9f9); }
.config-header h4 { margin: 0; font-size: 14px; }
.config-body { padding: 16px; }
.form-group { margin-bottom: 12px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 12px; color: var(--text-color-secondary, #999); }
.form-group input, .form-group textarea, .form-group select { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 13px; box-sizing: border-box; }
.form-group textarea { font-family: monospace; resize: vertical; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-success { background: var(--badge-success-bg); color: var(--badge-success-fg); }
.badge-info { background: var(--badge-info-bg); color: var(--badge-info-fg); }
.primary-btn { padding: 10px 24px; border: none; border-radius: 6px; background: var(--primary-color, #409eff); color: #fff; cursor: pointer; font-size: 14px; margin-top: 8px; }
.primary-btn:disabled { opacity: 0.6; cursor: not-allowed; }
</style>
