<template>
  <div class="page">
    <div class="page-header"><h2>系统设置</h2></div>
    <div class="tabs">
      <button v-for="g in groups" :key="g" :class="['tab-btn', { active: activeGroup === g }]" @click="activeGroup = g; fetchSettings()">{{ g }}</button>
    </div>
    <div class="panel">
      <form @submit.prevent="handleSave">
        <div v-for="s in settings" :key="s.key ?? s.setting_key" class="setting-row">
          <label>{{ s.key ?? s.setting_key }}</label>
          <p v-if="s.description" class="hint">{{ s.description }}</p>
          <input v-if="!s.is_encrypted" v-model="s.value" />
          <input v-else v-model="s.value" type="password" placeholder="••••••" />
        </div>
        <div v-if="settings.length === 0" class="empty-row">暂无设置项</div>
        <button v-if="settings.length > 0" type="submit" class="primary-btn" :disabled="saving">保存</button>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const API = '/api/v1/admin/system-settings'
const groups = ref<string[]>(['app', 'mail', 'cache', 'queue', 'session'])
const activeGroup = ref('app')
const settings = ref<any[]>([])
const saving = ref(false)

const fetchSettings = async () => { try { const r = await axios.get(API, { params: { group: activeGroup.value } }); settings.value = r.data.data || [] } catch { settings.value = [] } }

const handleSave = async () => {
  saving.value = true
  try { await axios.put(`${API}/${activeGroup.value}`, { settings: settings.value.map(s => ({ key: s.key ?? s.setting_key, value: s.value, is_encrypted: s.is_encrypted })) }); alert('保存成功') } catch (e: any) { alert(e.response?.data?.message || '保存失败') } finally { saving.value = false }
}

onMounted(fetchSettings)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.tabs { display: flex; gap: 4px; margin-bottom: 16px; }
.tab-btn { padding: 8px 16px; border: 1px solid var(--border-color, #ddd); border-radius: 6px 6px 0 0; background: var(--fill-color, #f5f5f5); cursor: pointer; font-size: 13px; color: var(--text-color-secondary, #666); }
.tab-btn.active { background: var(--bg-color, #fff); border-bottom-color: var(--bg-color, #fff); color: var(--link-color); font-weight: 500; }
.panel { background: var(--bg-color, #fff); border-radius: 0 8px 8px 8px; padding: 24px; max-width: 600px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.setting-row { margin-bottom: 16px; }
.setting-row label { display: block; font-size: 13px; font-weight: 500; color: var(--text-color-primary, #333); margin-bottom: 4px; }
.setting-row .hint { margin: 0 0 4px; font-size: 12px; color: var(--text-color-secondary, #999); }
.setting-row input { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; box-sizing: border-box; }
.empty-row { text-align: center; color: var(--text-color-secondary, #999); padding: 24px; }
.primary-btn { padding: 10px 24px; background: var(--primary-color, #409eff); color: #fff; border: none; border-radius: 6px; cursor: pointer; margin-top: 16px; }
.primary-btn:disabled { opacity: 0.6; }
</style>
