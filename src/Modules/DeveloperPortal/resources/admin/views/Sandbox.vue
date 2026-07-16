<template>
  <div class="page">
    <div class="page-header"><h2>沙箱环境</h2><button class="primary-btn" @click="handleCreate">+ 创建沙箱</button></div>
    <div class="panel">
      <table class="data-table">
        <thead><tr><th>ID</th><th>开发者ID</th><th>沙箱租户</th><th>状态</th><th>过期时间</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="s in sandboxes" :key="s.id ?? s.sandbox_environment_id">
            <td>{{ s.id ?? s.sandbox_environment_id }}</td><td>{{ s.developer_id }}</td>
            <td>{{ s.sandbox_tenant_id }}</td>
            <td><span :class="['badge', s.status === 'active' ? 'badge-success' : 'badge-danger']">{{ s.status }}</span></td>
            <td>{{ s.expires_at }}</td>
            <td><button class="link-btn danger" @click="handleCleanup(s)">清理</button></td>
          </tr>
          <tr v-if="sandboxes.length === 0"><td colspan="6" class="empty-row">暂无沙箱环境</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const API = '/v1/admin/admin/developer-portal/sandbox'
const sandboxes = ref<any[]>([])

const fetch = async () => { try { const r = await axios.get(API); sandboxes.value = r.data.data || [] } catch {} }
const handleCreate = async () => { try { await axios.post(API); await fetch() } catch {} }
const handleCleanup = async (s: any) => { if (!confirm('确定清理此沙箱？')) return; /* cleanup endpoint not exposed yet */ }

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
.badge-success { background: var(--badge-success-bg); color: var(--badge-success-fg); }
.badge-danger { background: var(--badge-danger-bg); color: var(--badge-danger-fg); }
.link-btn { background: none; border: none; color: var(--link-color); cursor: pointer; font-size: 13px; padding: 0 4px; }
.link-btn.danger { color: var(--link-danger); }
</style>
