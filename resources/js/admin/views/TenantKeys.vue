<template>
  <div class="page">
    <div class="page-header"><h2>租户密钥</h2><button class="primary-btn" @click="handleGenerate">+ 生成密钥</button></div>
    <div class="panel">
      <table class="data-table">
        <thead><tr><th>ID</th><th>名称</th><th>状态</th><th>创建时间</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="k in keys" :key="k.id ?? k.tenant_key_id">
            <td>{{ k.id ?? k.tenant_key_id }}</td><td>{{ k.name || '-' }}</td>
            <td><span :class="['badge', k.status === 'active' ? 'badge-success' : 'badge-danger']">{{ k.status }}</span></td>
            <td>{{ k.created_at }}</td>
            <td><button v-if="k.status === 'active'" class="link-btn danger" @click="handleRevoke(k)">吊销</button></td>
          </tr>
          <tr v-if="keys.length === 0"><td colspan="5" class="empty-row">暂无密钥</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const API = '/api/v1/admin/tenant-keys'
const keys = ref<any[]>([])

const fetch = async () => { try { const r = await axios.get(API); keys.value = r.data.data || [] } catch {} }
const handleGenerate = async () => { try { await axios.post(API); await fetch() } catch {} }
const handleRevoke = async (k: any) => { if (!confirm('确定吊销此密钥？')) return; try { await axios.delete(`${API}/${k.id ?? k.tenant_key_id}`); await fetch() } catch {} }

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
