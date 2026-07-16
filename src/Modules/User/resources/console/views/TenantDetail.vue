<template>
  <div class="detail-page">
    <div class="page-header">
      <h2>租户详情</h2>
      <button @click="router.push('/tenants')">返回列表</button>
    </div>

    <div class="panel" v-if="tenant">
      <div class="info-grid">
        <div class="info-item"><span>租户ID</span><span>{{ tenant.tenant_id }}</span></div>
        <div class="info-item"><span>名称</span><span>{{ tenant.name }}</span></div>
        <div class="info-item"><span>标识</span><span>{{ tenant.slug }}</span></div>
        <div class="info-item"><span>自定义域名</span><span>{{ tenant.custom_domain || '-' }}</span></div>
        <div class="info-item"><span>状态</span><span :class="['badge', tenant.status === 'active' ? 'badge-success' : 'badge-info']">{{ tenant.status === 'active' ? '活跃' : '未激活' }}</span></div>
        <div class="info-item"><span>套餐</span><span>{{ tenant.subscription_plan }}</span></div>
        <div class="info-item"><span>总积分</span><span>{{ tenant.total_credits }}</span></div>
        <div class="info-item"><span>可用积分</span><span>{{ tenant.available_credits }}</span></div>
      </div>
    </div>

    <div class="panel" style="margin-top: 16px;">
      <h3>成员列表</h3>
      <table class="data-table">
        <thead>
          <tr><th>用户ID</th><th>姓名</th><th>邮箱</th><th>角色</th><th>状态</th></tr>
        </thead>
        <tbody>
          <tr v-for="m in members" :key="m.user_id">
            <td>{{ m.user_id }}</td>
            <td>{{ m.name }}</td>
            <td>{{ m.email }}</td>
            <td><span :class="['badge', m.pivot?.role === 'tenant_admin' ? 'badge-warning' : 'badge-info']">{{ m.pivot?.role === 'tenant_admin' ? '管理员' : '普通用户' }}</span></td>
            <td><span :class="['badge', m.pivot?.is_active ? 'badge-success' : 'badge-danger']">{{ m.pivot?.is_active ? '激活' : '未激活' }}</span></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'

const route = useRoute()
const router = useRouter()
const tenant = ref<any>(null)
const members = ref<any[]>([])

onMounted(async () => {
  try {
    const res = await axios.get(`/api/v1/tenants/${route.params.id}`)
    tenant.value = res.data.data
  } catch {}
  try {
    const res = await axios.get(`/api/v1/tenants/${route.params.id}/members`)
    members.value = res.data.data || []
  } catch {}
})
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.page-header button { padding: 6px 14px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; background: var(--bg-color, #fff); cursor: pointer; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.panel h3 { margin: 0 0 16px; }
.info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
.info-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.info-item span:first-child { color: var(--text-color-secondary, #999); }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-success { background: var(--badge-success-bg); color: var(--badge-success-fg); }
.badge-info { background: var(--badge-info-bg); color: var(--badge-info-fg); }
.badge-warning { background: var(--badge-warning-bg); color: var(--badge-warning-fg); }
.badge-danger { background: var(--badge-danger-bg); color: var(--badge-danger-fg); }
</style>
