<template>
  <div class="users-page">
    <div class="page-header"><h2>用户管理</h2></div>
    <div class="panel">
      <table class="data-table">
        <thead><tr><th>ID</th><th>姓名</th><th>邮箱</th><th>角色</th><th>状态</th><th>创建时间</th></tr></thead>
        <tbody>
          <tr v-for="u in users" :key="u.user_id">
            <td>{{ u.user_id }}</td>
            <td>{{ u.name }}</td>
            <td>{{ u.email }}</td>
            <td><span :class="['badge', u.role === 'super_admin' ? 'badge-warning' : 'badge-info']">{{ u.role === 'super_admin' ? '超级管理员' : '普通用户' }}</span></td>
            <td><span :class="['badge', u.is_active ? 'badge-success' : 'badge-danger']">{{ u.is_active ? '激活' : '未激活' }}</span></td>
            <td>{{ u.created_at }}</td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'

const users = ref([
  { user_id: '1', name: '系统管理员', email: 'admin@example.com', role: 'super_admin', is_active: true, created_at: '2026-06-18' },
  { user_id: '2', name: '张三', email: 'zhangsan@example.com', role: 'platform_user', is_active: true, created_at: '2026-06-18' },
])
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-success { background: #e8f5e9; color: #2e7d32; }
.badge-info { background: #eee; color: #666; }
.badge-warning { background: #fff3e0; color: #e65100; }
.badge-danger { background: #fce4ec; color: #c62828; }
</style>
