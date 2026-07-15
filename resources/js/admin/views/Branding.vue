<template>
  <div class="page">
    <div class="page-header"><h2>品牌配置</h2></div>
    <div class="panel">
      <form @submit.prevent="handleSave">
        <div class="form-row">
          <div class="form-group"><label>Logo URL</label><input v-model="form.logo_url" placeholder="https://..." /></div>
          <div class="form-group"><label>Favicon URL</label><input v-model="form.favicon_url" placeholder="https://..." /></div>
        </div>
        <div class="form-row">
          <div class="form-group"><label>主色调</label><div class="color-input"><input type="color" v-model="form.primary_color" /><input v-model="form.primary_color" /></div></div>
          <div class="form-group"><label>辅助色</label><div class="color-input"><input type="color" v-model="form.secondary_color" /><input v-model="form.secondary_color" /></div></div>
        </div>
        <div class="form-group"><label>登录页样式</label><select v-model="form.login_page_style"><option value="default">默认</option><option value="compact">紧凑</option><option value="illustration">插画</option></select></div>
        <div class="form-group"><label>邮件模板</label><select v-model="form.email_template"><option value="default">默认</option><option value="minimal">极简</option></select></div>
        <div class="form-group"><label>自定义 CSS</label><textarea v-model="form.custom_css" rows="4" placeholder="/* custom styles */"></textarea></div>
        <div class="form-group"><label><input type="checkbox" v-model="form.custom_domain_enabled" /> 允许自定义域名</label></div>
        <button type="submit" class="primary-btn" :disabled="saving">保存</button>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const API = '/api/v1/admin/branding'
const saving = ref(false)
const form = ref({ logo_url: '', favicon_url: '', primary_color: '#1890ff', secondary_color: '#666666', login_page_style: 'default', email_template: 'default', custom_css: '', custom_domain_enabled: true })

const fetch = async () => { try { const r = await axios.get(API); if (r.data.data) Object.assign(form.value, r.data.data) } catch {} }
const handleSave = async () => { saving.value = true; try { await axios.put(API, form.value); alert('保存成功') } catch (e: any) { alert(e.response?.data?.message || '保存失败') } finally { saving.value = false } }

onMounted(fetch)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; max-width: 640px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; box-sizing: border-box; }
.form-group textarea { font-family: monospace; font-size: 12px; resize: vertical; }
.form-group input[type="checkbox"] { width: auto; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }
.color-input { display: flex; gap: 8px; align-items: center; }
.color-input input[type="color"] { width: 40px; height: 36px; padding: 2px; border: 1px solid var(--border-color, #ddd); border-radius: 4px; cursor: pointer; }
.color-input input[type="text"] { flex: 1; }
.primary-btn { padding: 10px 24px; background: var(--primary-color, #409eff); color: #fff; border: none; border-radius: 6px; cursor: pointer; }
.primary-btn:disabled { opacity: 0.6; }
</style>
