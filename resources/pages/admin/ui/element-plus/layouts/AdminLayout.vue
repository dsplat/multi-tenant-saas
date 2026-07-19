<template>
  <el-container class="admin-layout">
    <el-aside width="260px" class="sidebar">
      <div class="logo">
        <div class="logo-icon">
          <el-icon :size="20"><Grid /></el-icon>
        </div>
        <div class="logo-text">
          <span class="logo-title">SaaS Admin</span>
          <span class="logo-sub">管理后台</span>
        </div>
      </div>

      <div class="sidebar-nav">
        <el-menu :default-active="activePath" :router="true" class="sidebar-menu"
          :class="{ 'disabled-section': section.needsTenant && !tenantStore.hasTenant }"
          v-for="section in navSections" :key="section.label">
          <div class="nav-section-label">
            {{ section.label }}
            <span v-if="section.needsTenant && !tenantStore.hasTenant" class="section-hint">请先选择团队</span>
          </div>
          <template v-for="item in section.items" :key="item.path">
            <el-menu-item v-if="!item.perm || userStore.hasPermission(item.perm)"
              :index="`/${item.path}`"
              :disabled="section.needsTenant && !tenantStore.hasTenant">
              <el-icon><component :is="item.icon" /></el-icon>
              <span>{{ item.label }}</span>
            </el-menu-item>
          </template>
        </el-menu>
      </div>

      <div class="sidebar-footer">
        <div class="user-block">
          <el-avatar :size="32" class="user-avatar">{{ (userStore.user?.name || 'A')[0] }}</el-avatar>
          <div class="user-info">
            <div class="user-name">{{ userStore.user?.name || '管理员' }}</div>
            <div class="user-role">{{ userStore.user?.role === 'super_admin' ? '超级管理员' : '管理员' }}</div>
          </div>
        </div>
      </div>
    </el-aside>

    <el-container>
      <el-header class="topbar">
        <el-breadcrumb separator="/">
          <el-breadcrumb-item :to="{ path: '/dashboard' }">首页</el-breadcrumb-item>
          <el-breadcrumb-item v-if="route.meta.title">{{ route.meta.title }}</el-breadcrumb-item>
        </el-breadcrumb>

        <div class="actions">
          <el-select v-model="selectedTenantId" @change="onTenantChange" placeholder="选择团队..."
            size="default" style="width: 160px">
            <el-option v-for="t in tenantStore.tenants" :key="t.tenant_id" :label="t.name" :value="t.tenant_id" />
          </el-select>
          <el-button :icon="Monitor" circle @click="showFrameworkSelector = true" title="UI 框架切换" />
          <ThemeSwitcher />
          <ColorPicker />
          <el-button :icon="Setting" circle @click="showThemeSettings = true" title="主题设置" />
          <el-button type="danger" :icon="SwitchButton" @click="handleLogout">退出</el-button>
        </div>
      </el-header>

      <el-main>
        <router-view />
      </el-main>
    </el-container>
  </el-container>

  <ThemeSettings v-model:visible="showThemeSettings" />
  <UIFrameworkSelector v-model:visible="showFrameworkSelector" />
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  Grid, HomeFilled, OfficeBuilding, User, UserFilled, Lock, Calendar,
  Connection, Flag, Brush, Share, Setting, Timer, Monitor, Tools,
  Link, Key, Document, ChatDotRound, CreditCard, DataAnalysis, Coin,
  SwitchButton,
} from '@element-plus/icons-vue'
import { useUserStore } from '@/admin/stores/user'
import { useTenantStore } from '@/admin/stores/tenant'
import ThemeSwitcher from '@multi-tenant-saas/ui-core/components/ThemeSwitcher.vue'
import ColorPicker from '@multi-tenant-saas/ui-core/components/ColorPicker.vue'
import ThemeSettings from '@multi-tenant-saas/ui-core/components/ThemeSettings.vue'
import UIFrameworkSelector from '@multi-tenant-saas/ui-core/components/UIFrameworkSelector.vue'

const route = useRoute()
const router = useRouter()
const userStore = useUserStore()
const tenantStore = useTenantStore()
const showThemeSettings = ref(false)
const showFrameworkSelector = ref(false)
const selectedTenantId = ref('')

const activePath = computed(() => route.path)

const navSections = [
  {
    label: '系统管理',
    items: [
      { path: 'dashboard', label: '仪表盘', icon: HomeFilled },
      { path: 'tenants', label: '团队管理', icon: OfficeBuilding, perm: 'tenant.view' },
      { path: 'operators', label: '运营人员', icon: UserFilled, perm: 'member.view' },
      { path: 'roles', label: '角色权限', icon: Lock, perm: 'rbac.manage' },
      { path: 'plans', label: '订阅计划', icon: Calendar, perm: 'subscription.manage' },
    ],
  },
  {
    label: '平台配置',
    items: [
      { path: 'modules', label: '模块管理', icon: Grid, perm: 'setting.view' },
      { path: 'plugins', label: '插件管理', icon: Connection, perm: 'setting.view' },
      { path: 'feature-flags', label: '功能开关', icon: Flag, perm: 'setting.view' },
      { path: 'branding', label: '品牌配置', icon: Brush, perm: 'branding.view' },
      { path: 'sso-providers', label: 'SSO 提供商', icon: Share, perm: 'setting.update' },
      { path: 'system-settings', label: '系统设置', icon: Setting, perm: 'setting.view' },
      { path: 'retention-policies', label: '数据保留', icon: Timer, perm: 'compliance.view' },
      { path: 'sandbox', label: '沙箱环境', icon: Monitor },
      { path: 'settings', label: '配置中心', icon: Tools, perm: 'setting.view' },
    ],
  },
  {
    label: '团队管理',
    needsTenant: true,
    items: [
      { path: 'users', label: '用户管理', icon: User, perm: 'user.view' },
      { path: 'domains', label: '域名管理', icon: Link, perm: 'tenant.view' },
      { path: 'oauth', label: '第三方登录', icon: Key, perm: 'setting.view' },
      { path: 'audit-logs', label: '审计日志', icon: Document, perm: 'tenant.view' },
      { path: 'sms', label: '短信配置', icon: ChatDotRound, perm: 'setting.view' },
      { path: 'payments', label: '支付订单', icon: CreditCard, perm: 'payment.view' },
      { path: 'api-tokens', label: 'API Token', icon: Key, perm: 'setting.view' },
      { path: 'quotas', label: '配额管理', icon: DataAnalysis, perm: 'setting.view' },
      { path: 'credits', label: '积分总览', icon: Coin, perm: 'credit.view' },
      { path: 'ssl', label: 'SSL 证书', icon: Lock, perm: 'ssl.manage' },
      { path: 'webhooks', label: 'Webhooks', icon: Link, perm: 'webhook.view' },
      { path: 'ip-whitelist', label: 'IP 白名单', icon: Lock, perm: 'security.view' },
      { path: 'tenant-keys', label: '团队密钥', icon: Key, perm: 'security.view' },
      { path: 'consents', label: '合规同意', icon: Document, perm: 'compliance.view' },
    ],
  },
]

const onTenantChange = () => {
  const tenant = tenantStore.tenants.find(t => t.tenant_id === selectedTenantId.value)
  tenantStore.selectTenant(tenant || null)
}

onMounted(async () => {
  await tenantStore.fetchTenants()
  await tenantStore.restoreSelection()
  selectedTenantId.value = tenantStore.tenantId
})

watch(() => tenantStore.tenantId, (id) => { selectedTenantId.value = id })

const handleLogout = async () => {
  await userStore.logout()
  router.push('/login')
}
</script>

<style>
/* ===== THEME: light (default) ===== */
:root { --ac: var(--primary-color, #6366f1); --ac-r: 99,102,241; --sb: #ffffff; --sb-h: #f1f5f9; --sb-t: #64748b; --sb-ta: #0f172a; --sb-l: #94a3b8; --sb-b: #e2e8f0; --tb: #ffffff; --tb-b: #e2e8f0; --pg: #f1f5f9; --tx: #0f172a; --tx2: #64748b; --bg-color: #ffffff; --bg-color-page: #f1f5f9; --border-color: #e2e8f0; --fill-color: #f8fafc; --text-color-primary: #0f172a; --text-color-secondary: #64748b; --el-color-primary: var(--primary-color, #6366f1); }

/* Badge variables */
:root { --badge-success-bg: #e6ffed; --badge-success-fg: #52c41a; --badge-danger-bg: #fff1f0; --badge-danger-fg: #f5222d; --badge-warning-bg: #fff7e6; --badge-warning-fg: #fa8c16; --badge-info-bg: #f0f0f0; --badge-info-fg: #666666; --link-color: var(--primary-color, #6366f1); --link-danger: #f5222d; --table-header-fg: #999999; }
html.dark { --badge-success-bg: #0d3320; --badge-success-fg: #73e2a3; --badge-danger-bg: #3b1219; --badge-danger-fg: #fca5a5; --badge-warning-bg: #3b2e08; --badge-warning-fg: #fcd34d; --badge-info-bg: #1e293b; --badge-info-fg: #94a3b8; --link-color: #818cf8; --link-danger: #fca5a5; --table-header-fg: #94a3b8; }

html.dark { --sb: #1e293b; --sb-h: #334155; --sb-t: #94a3b8; --sb-ta: #f1f5f9; --sb-l: #64748b; --sb-b: #334155; --tb: #1e293b; --tb-b: #334155; --pg: #0f172a; --tx: #f1f5f9; --tx2: #94a3b8; --bg-color: #1e293b; --bg-color-page: #0f172a; --border-color: #334155; --fill-color: #334155; --text-color-primary: #f1f5f9; --text-color-secondary: #94a3b8; --el-color-primary: var(--primary-color, #818cf8); }
</style>

<style scoped>
.admin-layout { height: 100vh; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--pg); }

.sidebar { background: var(--sb); border-right: 1px solid var(--sb-b); display: flex; flex-direction: column; overflow: hidden; }
.logo { height: 64px; display: flex; align-items: center; gap: 12px; padding: 0 20px; border-bottom: 1px solid var(--sb-b); flex-shrink: 0; }
.logo-icon { width: 36px; height: 36px; border-radius: 10px; background: linear-gradient(135deg, var(--ac), color-mix(in srgb, var(--ac) 70%, #fff)); display: flex; align-items: center; justify-content: center; color: #fff; }
.logo-text { display: flex; flex-direction: column; }
.logo-title { font-size: 15px; font-weight: 700; color: var(--sb-ta); }
.logo-sub { font-size: 11px; color: var(--sb-l); margin-top: 1px; }

.sidebar-nav { flex: 1 1 0; overflow-y: auto; }
.sidebar-menu { border-right: none; background: transparent; }
.sidebar-menu.disabled-section { opacity: 0.4; pointer-events: none; }
.nav-section-label { padding: 16px 20px 6px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--sb-l); }
.section-hint { font-size: 10px; font-weight: 400; text-transform: none; color: #f59e0b; margin-left: 8px; }

.sidebar-menu :deep(.el-menu-item) { color: var(--sb-t); border-radius: 6px; margin: 1px 8px; }
.sidebar-menu :deep(.el-menu-item:hover) { background: var(--sb-h); color: var(--sb-ta); }
.sidebar-menu :deep(.el-menu-item.is-active) { background: rgba(var(--ac-r),0.15); color: var(--sb-ta); font-weight: 500; }

.sidebar-footer { padding: 12px 16px; border-top: 1px solid var(--sb-b); flex-shrink: 0; }
.user-block { display: flex; align-items: center; gap: 10px; padding: 8px; border-radius: 8px; }
.user-block:hover { background: var(--sb-h); }
.user-avatar { background: linear-gradient(135deg, var(--ac), color-mix(in srgb, var(--ac) 70%, #fff)); color: #fff; font-size: 13px; font-weight: 600; }
.user-info { overflow: hidden; }
.user-name { font-size: 13px; font-weight: 500; color: var(--sb-ta); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-role { font-size: 11px; color: var(--sb-l); margin-top: 1px; }

.topbar { background: var(--tb); border-bottom: 1px solid var(--tb-b); display: flex; align-items: center; justify-content: space-between; box-shadow: 0 1px 2px rgba(0,0,0,0.05); }
.actions { display: flex; align-items: center; gap: 8px; }
.admin-layout :deep(.el-main) { overflow-y: auto; background: var(--pg); }
</style>
