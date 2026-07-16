<template>
  <div class="page">
    <div class="page-header"><h2>插件管理</h2><button class="primary-btn" @click="showInstall = true">+ 安装插件</button></div>
    <div class="panel">
      <table class="data-table">
        <thead><tr><th>插件名</th><th>版本</th><th>状态</th><th>安装时间</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="p in plugins" :key="p.name ?? p.id">
            <td><strong>{{ p.name }}</strong></td><td>{{ p.version || '-' }}</td>
            <td><span :class="['badge', statusClass(p.status)]">{{ statusLabel(p.status) }}</span></td>
            <td>{{ p.installed_at || '-' }}</td>
            <td>
              <button v-if="p.status === 'installed' || p.status === 'disabled'" class="link-btn" @click="enablePlugin(p)">启用</button>
              <button v-if="p.status === 'enabled'" class="link-btn" @click="disablePlugin(p)">禁用</button>
              <button class="link-btn danger" @click="uninstallPlugin(p)">卸载</button>
            </td>
          </tr>
          <tr v-if="plugins.length === 0"><td colspan="5" class="empty-row">暂无已安装插件</td></tr>
        </tbody>
      </table>
    </div>

    <div class="modal-backdrop" v-if="showInstall" @click="showInstall = false">
      <div class="modal-content" @click.stop>
        <h3>安装插件</h3>
        <form @submit.prevent="handleInstall">
          <div class="form-group"><label>插件名称</label><input v-model="installName" required placeholder="plugin-name" /></div>
          <div class="form-actions"><button type="button" @click="showInstall = false">取消</button><button type="submit" class="primary-btn">安装</button></div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const API = '/v1/admin/admin/plugins'
const plugins = ref<any[]>([])
const showInstall = ref(false)
const installName = ref('')

const statusClass = (s: string) => ({ enabled: 'badge-success', disabled: 'badge-danger', installed: 'badge-info', error: 'badge-warning' }[s] || 'badge-info')
const statusLabel = (s: string) => ({ enabled: '已启用', disabled: '已禁用', installed: '已安装', error: '错误' }[s] || s)

const fetchPlugins = async () => { try { const r = await axios.get(API); plugins.value = r.data.data || [] } catch {} }

const handleInstall = async () => {
  try { await axios.post(`${API}/${installName.value}/install`); showInstall.value = false; installName.value = ''; await fetchPlugins() } catch {}
}
const enablePlugin = async (p: any) => { try { await axios.post(`${API}/${p.name}/enable`); await fetchPlugins() } catch {} }
const disablePlugin = async (p: any) => { try { await axios.post(`${API}/${p.name}/disable`); await fetchPlugins() } catch {} }
const uninstallPlugin = async (p: any) => {
  if (!confirm(`确定卸载插件 ${p.name}？`)) return
  try { await axios.post(`${API}/${p.name}/uninstall`); await fetchPlugins() } catch {}
}

onMounted(fetchPlugins)
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.primary-btn { padding: 8px 16px; background: var(--primary-color, #409eff); color: #fff; border: none; border-radius: 6px; cursor: pointer; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.empty-row { text-align: center; color: var(--text-color-secondary, #999); padding: 24px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-info { background: var(--badge-info-bg); color: var(--badge-info-fg); }
.badge-success { background: var(--badge-success-bg); color: var(--badge-success-fg); }
.badge-warning { background: var(--badge-warning-bg); color: var(--badge-warning-fg); }
.badge-danger { background: var(--badge-danger-bg); color: var(--badge-danger-fg); }
.link-btn { background: none; border: none; color: var(--link-color); cursor: pointer; font-size: 13px; padding: 0 4px; }
.link-btn.danger { color: var(--link-danger); }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-content { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; min-width: 380px; }
.modal-content h3 { margin: 0 0 20px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; box-sizing: border-box; }
.form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
.form-actions button { padding: 8px 16px; border-radius: 6px; border: 1px solid var(--border-color, #ddd); background: #fff; cursor: pointer; }
</style>
