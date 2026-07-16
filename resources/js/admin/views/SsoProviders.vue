<template>
  <div class="page">
    <div class="page-header"><h2>SSO 提供商</h2><button class="primary-btn" @click="openCreate">+ 添加提供商</button></div>
    <div class="panel">
      <table class="data-table">
        <thead><tr><th>名称</th><th>类型</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="p in providers" :key="p.name">
            <td><strong>{{ p.name }}</strong></td>
            <td><span class="badge badge-info">{{ p.type || p.provider_type || '-' }}</span></td>
            <td><span :class="['badge', p.is_active !== false ? 'badge-success' : 'badge-danger']">{{ p.is_active !== false ? '启用' : '禁用' }}</span></td>
            <td><button class="link-btn danger" @click="handleDelete(p)">删除</button></td>
          </tr>
          <tr v-if="providers.length === 0"><td colspan="4" class="empty-row">暂无 SSO 提供商</td></tr>
        </tbody>
      </table>
    </div>

    <div class="modal-backdrop" v-if="dialog" @click="dialog = false">
      <div class="modal-content" @click.stop>
        <h3>添加 SSO 提供商</h3>
        <form @submit.prevent="handleSubmit">
          <div class="form-group"><label>名称</label><input v-model="form.name" required placeholder="google / github / saml" /></div>
          <div class="form-group"><label>类型</label><select v-model="form.type"><option value="oidc">OIDC</option><option value="saml">SAML</option><option value="oauth2">OAuth2</option></select></div>
          <div class="form-group"><label>Client ID</label><input v-model="form.client_id" /></div>
          <div class="form-group"><label>Client Secret</label><input v-model="form.client_secret" type="password" /></div>
          <div class="form-group"><label>配置（JSON）</label><textarea v-model="configInput" rows="4" placeholder='{"redirect_uri":"..."}'></textarea></div>
          <div class="form-actions"><button type="button" @click="dialog = false">取消</button><button type="submit" class="primary-btn">添加</button></div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const API = '/v1/admin/auth/sso/providers'
const providers = ref<any[]>([])
const dialog = ref(false)
const form = ref({ name: '', type: 'oidc', client_id: '', client_secret: '', config: {} as any })
const configInput = ref('{}')

const fetch = async () => { try { const r = await axios.get(API); providers.value = r.data.data || [] } catch {} }
const openCreate = () => { form.value = { name: '', type: 'oidc', client_id: '', client_secret: '', config: {} }; configInput.value = '{}'; dialog.value = true }

const handleSubmit = async () => {
  try {
    let config: any = {}
    try { config = JSON.parse(configInput.value) } catch { alert('JSON 格式错误'); return }
    await axios.post(API, { ...form.value, config })
    dialog.value = false; await fetch()
  } catch {}
}

const handleDelete = async (p: any) => { if (!confirm(`确定删除 ${p.name}？`)) return; try { await axios.delete(`${API}/${p.name}`); await fetch() } catch {} }

onMounted(fetch)
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
.badge-danger { background: var(--badge-danger-bg); color: var(--badge-danger-fg); }
.link-btn { background: none; border: none; color: var(--link-color); cursor: pointer; font-size: 13px; padding: 0 4px; }
.link-btn.danger { color: var(--link-danger); }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-content { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; min-width: 420px; }
.modal-content h3 { margin: 0 0 20px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input, .form-group select, .form-group textarea { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; box-sizing: border-box; }
.form-group textarea { font-family: monospace; font-size: 12px; resize: vertical; }
.form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
.form-actions button { padding: 8px 16px; border-radius: 6px; border: 1px solid var(--border-color, #ddd); background: #fff; cursor: pointer; }
</style>
