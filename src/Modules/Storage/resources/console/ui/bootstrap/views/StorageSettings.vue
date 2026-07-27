<template>
  <div class="storage-page">
    <div class="page-header"><h2>存储配置</h2></div>

    <div class="panel">
      <div class="status-bar">
        <span class="status-badge" :class="status.source">{{ statusLabel }}</span>
      </div>

      <p v-if="status.source !== 'tenant'" class="hint-box">
        未配置自有对象存储时，文件将自动存储到平台默认服务。配置并启用后优先使用您自己的存储。
      </p>

      <form @submit.prevent="handleSave">
        <div class="form-group">
          <label><input type="checkbox" v-model="form.enabled" /> 启用自有存储</label>
        </div>
        <div class="form-group">
          <label>Endpoint</label>
          <input v-model="form.endpoint" placeholder="https://oss-cn-hangzhou.aliyuncs.com" />
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Bucket</label>
            <input v-model="form.bucket" />
          </div>
          <div class="form-group">
            <label>Region</label>
            <input v-model="form.region" placeholder="cn-hangzhou" />
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>AccessKey ID</label>
            <input v-model="form.access_key_id" />
          </div>
          <div class="form-group">
            <label>AccessKey Secret</label>
            <input v-model="form.access_key_secret" type="password" placeholder="********" />
          </div>
        </div>
        <div class="form-group">
          <label>自定义域名（可选）</label>
          <input v-model="form.url" placeholder="https://cdn.example.com" />
        </div>
        <div class="form-group">
          <label><input type="checkbox" v-model="form.use_path_style" /> Path-Style 访问（MinIO 等自建存储需开启）</label>
        </div>
        <div class="form-actions">
          <button type="submit" class="primary-btn" :disabled="saving">保存配置</button>
        </div>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import axios from 'axios'

const saving = ref(false)

const form = reactive({
  enabled: false,
  driver: 's3',
  endpoint: '',
  bucket: '',
  region: '',
  access_key_id: '',
  access_key_secret: '',
  url: '',
  use_path_style: false,
})

const status = reactive({ source: 'system', disk: 'local' })

const statusLabel = computed(() =>
  status.source === 'tenant' ? '使用自有存储'
    : status.source === 'platform' ? '使用平台默认存储'
    : '使用系统本地存储')

const asBool = (v: any) => v === true || v === 'true' || v === '1' || v === 1

const loadConfig = async () => {
  try {
    const res = await axios.get('/api/v1/tenant/storage/config')
    const data = res.data.data || {}
    if (data.config) {
      Object.assign(form, data.config)
      form.enabled = asBool(form.enabled)
      form.use_path_style = asBool(form.use_path_style)
    }
    if (data.status) Object.assign(status, data.status)
  } catch {}
}

const handleSave = async () => {
  saving.value = true
  try {
    const res = await axios.put('/api/v1/tenant/storage/config', form)
    if (res.data.data?.status) Object.assign(status, res.data.data.status)
    alert('保存成功')
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
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; max-width: 600px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.status-bar { margin-bottom: 12px; }
.status-badge { display: inline-block; padding: 2px 10px; border-radius: 10px; font-size: 12px; background: #f0f0f0; color: #666; }
.status-badge.tenant { background: #e7f6e7; color: #2f9e44; }
.status-badge.platform { background: #fff4e0; color: #e8930c; }
.hint-box { font-size: 13px; color: var(--text-color-secondary, #666); background: #f7f9fc; border-radius: 6px; padding: 10px 12px; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 6px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input[type="text"], .form-group input[type="password"], .form-group input:not([type]) { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 14px; box-sizing: border-box; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.form-actions { margin-top: 24px; }
.primary-btn { padding: 8px 20px; border: none; border-radius: 6px; background: var(--primary-color, #409eff); color: #fff; cursor: pointer; font-size: 13px; }
.primary-btn:disabled { opacity: 0.6; cursor: not-allowed; }
</style>
