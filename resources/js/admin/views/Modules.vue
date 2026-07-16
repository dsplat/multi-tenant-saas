<template>
  <div class="page">
    <div class="page-header"><h2>模块管理</h2></div>
    <div class="panel">
      <table class="data-table">
        <thead><tr><th>模块名</th><th>版本</th><th>描述</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="m in modules" :key="m.name">
            <td><strong>{{ m.name }}</strong></td><td>{{ m.version || '-' }}</td>
            <td>{{ m.description || '-' }}</td>
            <td><span :class="['badge', m.enabled ? 'badge-success' : 'badge-danger']">{{ m.enabled ? '已启用' : '已禁用' }}</span></td>
            <td>
              <button class="link-btn" @click="toggleModule(m)">{{ m.enabled ? '禁用' : '启用' }}</button>
            </td>
          </tr>
          <tr v-if="modules.length === 0"><td colspan="5" class="empty-row">暂无模块</td></tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const API = '/api/v1/admin/modules'
const modules = ref<any[]>([])

const fetchModules = async () => { try { const r = await axios.get(API); modules.value = r.data.data || [] } catch {} }

const toggleModule = async (m: any) => {
  const action = m.enabled ? 'disable' : 'enable'
  try { await axios.post(`${API}/${m.name}/${action}`); await fetchModules() } catch {}
}

onMounted(fetchModules)
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.empty-row { text-align: center; color: var(--text-color-secondary, #999); padding: 24px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-success { background: var(--badge-success-bg); color: var(--badge-success-fg); }
.badge-danger { background: var(--badge-danger-bg); color: var(--badge-danger-fg); }
.link-btn { background: none; border: none; color: var(--link-color); cursor: pointer; font-size: 13px; padding: 0 4px; }
</style>
