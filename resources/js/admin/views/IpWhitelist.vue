<template>
  <div class="page">
    <div class="page-header"><h2>IP 白名单</h2><button class="primary-btn" @click="showAdd = true">+ 添加 IP</button></div>
    <div class="panel">
      <div class="filter-bar">
        <select v-model="filterScope" @change="fetch"><option value="">全部范围</option><option value="all">全局</option><option value="api">API</option><option value="admin">管理后台</option></select>
      </div>
      <table class="data-table">
        <thead><tr><th>IP</th><th>描述</th><th>范围</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="ip in items" :key="ip.id">
            <td><code>{{ ip.ip_address }}</code></td><td>{{ ip.description || '-' }}</td>
            <td><span class="badge badge-info">{{ ip.scope }}</span></td>
            <td><button class="link-btn danger" @click="handleDelete(ip)">删除</button></td>
          </tr>
          <tr v-if="items.length === 0"><td colspan="4" class="empty-row">暂无白名单</td></tr>
        </tbody>
      </table>
    </div>

    <div class="modal-backdrop" v-if="showAdd" @click="showAdd = false">
      <div class="modal-content" @click.stop>
        <h3>添加 IP 白名单</h3>
        <form @submit.prevent="handleAdd">
          <div class="form-group"><label>IP 地址</label><input v-model="form.ip_address" required placeholder="192.168.1.1" /></div>
          <div class="form-group"><label>描述</label><input v-model="form.description" /></div>
          <div class="form-group"><label>范围</label><select v-model="form.scope"><option value="all">全局</option><option value="api">API</option><option value="admin">管理后台</option></select></div>
          <div class="form-actions"><button type="button" @click="showAdd = false">取消</button><button type="submit" class="primary-btn">添加</button></div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const API = '/api/v1/admin/ip-whitelist'
const items = ref<any[]>([])
const filterScope = ref('')
const showAdd = ref(false)
const form = ref({ ip_address: '', description: '', scope: 'all' })

const fetch = async () => { try { const r = await axios.get(API, { params: { scope: filterScope.value || undefined } }); items.value = r.data.data || [] } catch {} }
const handleAdd = async () => { try { await axios.post(API, form.value); showAdd.value = false; form.value = { ip_address: '', description: '', scope: 'all' }; await fetch() } catch {} }
const handleDelete = async (ip: any) => { if (!confirm(`确定删除 ${ip.ip_address}？`)) return; try { await axios.delete(`${API}/${ip.id}`); await fetch() } catch {} }

onMounted(fetch)
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.primary-btn { padding: 8px 16px; background: var(--primary-color, #409eff); color: #fff; border: none; border-radius: 6px; cursor: pointer; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.filter-bar { display: flex; gap: 12px; margin-bottom: 16px; }
.filter-bar select { padding: 6px 10px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.empty-row { text-align: center; color: var(--text-color-secondary, #999); padding: 24px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-info { background: #eee; color: #666; }
.link-btn { background: none; border: none; color: var(--primary-color, #409eff); cursor: pointer; font-size: 13px; padding: 0 4px; }
.link-btn.danger { color: #f5222d; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-content { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; min-width: 400px; }
.modal-content h3 { margin: 0 0 20px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input, .form-group select { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; box-sizing: border-box; }
.form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
.form-actions button { padding: 8px 16px; border-radius: 6px; border: 1px solid var(--border-color, #ddd); background: #fff; cursor: pointer; }
</style>
