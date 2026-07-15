<template>
  <div class="page">
    <div class="page-header"><h2>合规同意</h2></div>
    <div class="panel">
      <div class="filter-bar">
        <input v-model="filters.user_id" placeholder="用户ID" @keyup.enter="fetch" />
        <select v-model="filters.type" @change="fetch"><option value="">全部类型</option><option value="cookie">Cookie</option><option value="data_processing">数据处理</option><option value="marketing">营销</option><option value="terms">条款</option></select>
      </div>
      <table class="data-table">
        <thead><tr><th>ID</th><th>用户ID</th><th>类型</th><th>版本</th><th>状态</th><th>时间</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="c in consents" :key="c.id ?? c.consent_id">
            <td>{{ c.id ?? c.consent_id }}</td><td>{{ c.user_id }}</td>
            <td><span class="badge badge-info">{{ c.type }}</span></td><td>{{ c.version || '-' }}</td>
            <td><span :class="['badge', c.revoked_at ? 'badge-danger' : 'badge-success']">{{ c.revoked_at ? '已撤回' : '有效' }}</span></td>
            <td>{{ c.created_at }}</td>
            <td><button v-if="!c.revoked_at" class="link-btn danger" @click="handleRevoke(c)">撤回</button></td>
          </tr>
          <tr v-if="consents.length === 0"><td colspan="7" class="empty-row">暂无记录</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const API = '/api/v1/admin/consents'
const consents = ref<any[]>([])
const filters = ref({ user_id: '', type: '' })

const fetch = async () => { try { const r = await axios.get(API, { params: { user_id: filters.value.user_id || undefined, type: filters.value.type || undefined } }); consents.value = r.data.data || [] } catch {} }
const handleRevoke = async (c: any) => { if (!confirm('确定撤回此同意？')) return; try { await axios.post(`${API}/${c.id ?? c.consent_id}/revoke`); await fetch() } catch {} }

onMounted(fetch)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.filter-bar { display: flex; gap: 12px; margin-bottom: 16px; }
.filter-bar input, .filter-bar select { padding: 6px 10px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.empty-row { text-align: center; color: var(--text-color-secondary, #999); padding: 24px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-info { background: #eee; color: #666; }
.badge-success { background: #e6ffed; color: #52c41a; }
.badge-danger { background: #fff1f0; color: #f5222d; }
.link-btn { background: none; border: none; color: var(--primary-color, #409eff); cursor: pointer; font-size: 13px; padding: 0 4px; }
.link-btn.danger { color: #f5222d; }
</style>
