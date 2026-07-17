<template>
  <div class="oauth-page">
    <div class="page-header"><h2>第三方登录配置</h2></div>

    <div class="panel">
      <div class="tenant-select">
        <label>选择租户：</label>
        <select v-model="selectedTenantId" @change="loadConfig">
          <option value="">请选择</option>
          <option v-for="t in tenants" :key="t.tenant_id" :value="t.tenant_id">{{ t.name }}</option>
        </select>
      </div>

      <div v-if="selectedTenantId" class="config-section">
        <!-- 微信 -->
        <div class="config-card">
          <div class="config-header">
            <h4>微信 / 企业微信</h4>
            <label class="switch">
              <input type="checkbox" v-model="config.wechat.enabled" />
              <span class="slider"></span>
            </label>
          </div>
          <div class="config-body" v-if="config.wechat.enabled">
            <div class="form-group">
              <label>Corp ID</label>
              <input v-model="config.wechat.corp_id" placeholder="wx1234567890" />
            </div>
            <div class="form-group">
              <label>Agent ID</label>
              <input v-model="config.wechat.agent_id" placeholder="1000001" />
            </div>
            <div class="form-group">
              <label>Secret</label>
              <input v-model="config.wechat.secret" type="password" placeholder="******" />
            </div>
          </div>
        </div>

        <!-- 钉钉 -->
        <div class="config-card">
          <div class="config-header">
            <h4>钉钉</h4>
            <label class="switch">
              <input type="checkbox" v-model="config.dingtalk.enabled" />
              <span class="slider"></span>
            </label>
          </div>
          <div class="config-body" v-if="config.dingtalk.enabled">
            <div class="form-group">
              <label>App Key</label>
              <input v-model="config.dingtalk.app_key" />
            </div>
            <div class="form-group">
              <label>App Secret</label>
              <input v-model="config.dingtalk.app_secret" type="password" placeholder="******" />
            </div>
          </div>
        </div>

        <!-- 飞书 -->
        <div class="config-card">
          <div class="config-header">
            <h4>飞书</h4>
            <label class="switch">
              <input type="checkbox" v-model="config.feishu.enabled" />
              <span class="slider"></span>
            </label>
          </div>
          <div class="config-body" v-if="config.feishu.enabled">
            <div class="form-group">
              <label>App ID</label>
              <input v-model="config.feishu.app_id" />
            </div>
            <div class="form-group">
              <label>App Secret</label>
              <input v-model="config.feishu.app_secret" type="password" placeholder="******" />
            </div>
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
const config = reactive({
  wechat: { enabled: false, corp_id: '', agent_id: '', secret: '' },
  dingtalk: { enabled: false, app_key: '', app_secret: '' },
  feishu: { enabled: false, app_id: '', app_secret: '' },
})

const fetchTenants = async () => {
  try {
    const res = await axios.get('/api/v1/tenants')
    tenants.value = res.data.data || []
  } catch {}
}

const loadConfig = async () => {
  if (!selectedTenantId.value) return
  try {
    const res = await axios.get(`/api/v1/tenants/${selectedTenantId.value}/settings/oauth`)
    const data = res.data.data || {}
    if (data.wechat) Object.assign(config.wechat, data.wechat)
    if (data.dingtalk) Object.assign(config.dingtalk, data.dingtalk)
    if (data.feishu) Object.assign(config.feishu, data.feishu)
  } catch {}
}

const handleSave = async () => {
  try {
    await axios.put(`/api/v1/tenants/${selectedTenantId.value}/settings/oauth`, config)
    alert('保存成功')
  } catch {
    alert('保存失败')
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
.form-group input { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 13px; box-sizing: border-box; }
.primary-btn { padding: 10px 24px; border: none; border-radius: 6px; background: var(--primary-color, #409eff); color: #fff; cursor: pointer; font-size: 14px; margin-top: 8px; }

/* Toggle switch */
.switch { position: relative; display: inline-block; width: 44px; height: 24px; }
.switch input { opacity: 0; width: 0; height: 0; }
.slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background: #ccc; border-radius: 24px; transition: 0.3s; }
.slider:before { position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px; background: #fff; border-radius: 50%; transition: 0.3s; }
input:checked + .slider { background: var(--primary-color, #409eff); }
input:checked + .slider:before { transform: translateX(20px); }
</style>
