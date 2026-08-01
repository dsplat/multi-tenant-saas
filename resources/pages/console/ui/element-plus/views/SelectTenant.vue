<template>
  <div class="select-tenant-page">
    <div class="page-inner">
      <header class="page-header">
        <p class="greeting">{{ greeting }}，{{ userName }}</p>
        <h1>选择要进入的团队</h1>
        <p class="hint">你拥有 {{ tenants.length }} 个团队的访问权限</p>
      </header>

      <div class="tenant-grid">
        <div
          v-for="(t, idx) in tenants"
          :key="t.tenant_id"
          class="tenant-card"
          :class="{ 'is-active': selected === t.tenant_id }"
          :style="{ animationDelay: `${idx * 70}ms` }"
          @click="select(t)"
        >
          <div class="card-avatar" :style="{ background: avatarColor(t.name) }">
            {{ (t.name || '?').charAt(0).toUpperCase() }}
          </div>
          <div class="card-body">
            <h3 class="card-name">{{ t.name }}</h3>
            <div class="card-meta">
              <span class="role-badge" :class="roleClass(t.role)">{{ roleLabel(t.role) }}</span>
              <span v-if="t.plan" class="plan-tag">{{ t.plan }}</span>
            </div>
          </div>
          <div class="card-arrow">
            <el-icon :size="18"><ArrowRight /></el-icon>
          </div>
          <div v-if="selected === t.tenant_id" class="card-check">
            <el-icon :size="14"><Check /></el-icon>
          </div>
        </div>
      </div>

      <footer class="page-footer">
        <el-button text @click="handleLogout">退出登录</el-button>
      </footer>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { ArrowRight, Check } from '@element-plus/icons-vue'
import { useUserStore } from '@stores/user'

const router = useRouter()
const userStore = useUserStore()
const selected = ref<number | null>(null)

const tenants = computed(() => userStore.user?.tenants || [])
const userName = computed(() => userStore.user?.name || '')

const greeting = computed(() => {
  const h = new Date().getHours()
  if (h < 6) return '夜深了'
  if (h < 12) return '早上好'
  if (h < 14) return '中午好'
  if (h < 18) return '下午好'
  return '晚上好'
})

const PALETTE = [
  '#2563eb', '#7c3aed', '#db2777', '#ea580c',
  '#059669', '#0891b2', '#4f46e5', '#b45309',
]

function avatarColor(name: string): string {
  let hash = 0
  for (let i = 0; i < name.length; i++) hash = name.charCodeAt(i) + ((hash << 5) - hash)
  return PALETTE[Math.abs(hash) % PALETTE.length]
}

function roleLabel(role?: string): string {
  const map: Record<string, string> = {
    tenant_admin: '管理员',
    member: '成员',
    platform_admin: '平台管理员',
  }
  return map[role || ''] || role || '成员'
}

function roleClass(role?: string): string {
  if (role === 'tenant_admin' || role === 'platform_admin') return 'role-admin'
  return 'role-member'
}

async function select(t: { tenant_id: number }) {
  if (selected.value === t.tenant_id) return
  selected.value = t.tenant_id
  try {
    await userStore.switchTenant(t.tenant_id)
  } catch {
    selected.value = null
  }
}

async function handleLogout() {
  await userStore.logout()
  router.push('/login')
}
</script>

<style scoped>
.select-tenant-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 48px 24px;
  background:
    radial-gradient(ellipse 80% 60% at 20% 10%, rgba(37, 99, 235, 0.06), transparent),
    radial-gradient(ellipse 60% 50% at 85% 80%, rgba(124, 58, 237, 0.05), transparent),
    var(--bg-color-page, #f5f7fa);
}

.page-inner {
  width: 100%;
  max-width: 640px;
}

.page-header {
  margin-bottom: 32px;
}

.greeting {
  margin: 0 0 4px;
  font-size: 14px;
  color: var(--text-color-secondary, #909399);
}

.page-header h1 {
  margin: 0;
  font-size: 26px;
  font-weight: 700;
  letter-spacing: -0.02em;
  color: var(--text-color-primary, #1d2129);
}

.hint {
  margin: 8px 0 0;
  font-size: 13px;
  color: var(--text-color-secondary, #909399);
}

.tenant-grid {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.tenant-card {
  position: relative;
  display: flex;
  align-items: center;
  gap: 16px;
  padding: 18px 20px;
  background: var(--el-bg-color, #fff);
  border: 1.5px solid var(--el-border-color-lighter, #ebeef5);
  border-radius: 12px;
  cursor: pointer;
  transition: transform 0.18s ease, box-shadow 0.18s ease, border-color 0.18s ease;
  animation: card-in 0.35s ease both;
}

.tenant-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 8px 24px rgba(0, 0, 0, 0.07);
  border-color: var(--el-color-primary-light-5, #a0cfff);
}

.tenant-card.is-active {
  border-color: var(--el-color-primary, #409eff);
  box-shadow: 0 4px 16px rgba(64, 158, 255, 0.15);
}

.card-avatar {
  flex-shrink: 0;
  width: 46px;
  height: 46px;
  border-radius: 10px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  font-weight: 700;
  color: #fff;
  letter-spacing: 0;
}

.card-body {
  flex: 1;
  min-width: 0;
}

.card-name {
  margin: 0 0 6px;
  font-size: 16px;
  font-weight: 600;
  color: var(--text-color-primary, #1d2129);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.card-meta {
  display: flex;
  align-items: center;
  gap: 8px;
}

.role-badge {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 12px;
  line-height: 1.5;
}

.role-admin {
  background: rgba(37, 99, 235, 0.08);
  color: #2563eb;
}

.role-member {
  background: rgba(107, 114, 128, 0.08);
  color: #6b7280;
}

.plan-tag {
  font-size: 12px;
  color: var(--text-color-secondary, #909399);
}

.card-arrow {
  flex-shrink: 0;
  color: var(--text-color-placeholder, #c0c4cc);
  transition: transform 0.18s ease, color 0.18s ease;
}

.tenant-card:hover .card-arrow {
  transform: translateX(3px);
  color: var(--el-color-primary, #409eff);
}

.card-check {
  position: absolute;
  top: -1px;
  right: -1px;
  width: 22px;
  height: 22px;
  border-radius: 0 11px 0 11px;
  background: var(--el-color-primary, #409eff);
  color: #fff;
  display: flex;
  align-items: center;
  justify-content: center;
}

.page-footer {
  margin-top: 28px;
  text-align: center;
}

@keyframes card-in {
  from {
    opacity: 0;
    transform: translateY(12px);
  }
  to {
    opacity: 1;
    transform: translateY(0);
  }
}
</style>
