<template>
  <el-container class="console-layout">
    <el-aside width="260px" class="sidebar">
      <div class="logo">
        <div class="logo-icon">
          <el-icon :size="20"><Monitor /></el-icon>
        </div>
        <div class="logo-text">
          <span class="logo-title">Console</span>
          <span class="logo-sub">团队后台</span>
        </div>
      </div>

      <div class="sidebar-nav">
        <el-menu :default-active="activePath" :router="true" class="sidebar-menu"
          v-for="section in sections" :key="section.label">
          <div class="nav-section-label">{{ section.label }}</div>
          <template v-for="item in section.items" :key="item.path">
            <el-menu-item :index="`/${item.path}`">
              <svg class="nav-svg-icon" viewBox="0 0 20 20" fill="currentColor"><path :d="item.icon"/></svg>
              <span>{{ item.label }}</span>
            </el-menu-item>
          </template>
        </el-menu>
      </div>

      <div class="sidebar-footer">
        <div class="user-block">
          <el-avatar :size="32" class="user-avatar">{{ (userStore.user?.name || 'C')[0] }}</el-avatar>
          <div class="user-info">
            <div class="user-name">{{ userStore.user?.name || '团队管理员' }}</div>
            <div class="user-role">{{ userStore.user?.role === 'tenant_admin' ? '团队管理员' : userStore.user?.role === 'platform_admin' ? '超级管理员' : userStore.user?.role || '用户' }}</div>
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
          <el-dropdown v-if="userStore.user?.tenants && userStore.user.tenants.length > 1" @command="handleSwitchTenant" trigger="click">
            <el-button :icon="OfficeBuilding" circle title="切换团队" />
            <template #dropdown>
              <el-dropdown-menu>
                <el-dropdown-item
                  v-for="t in userStore.user.tenants"
                  :key="t.tenant_id"
                  :command="t.tenant_id"
                  :disabled="String(t.tenant_id) === String(userStore.tenantId)"
                >
                  <el-icon><OfficeBuilding /></el-icon>
                  {{ t.name }}
                  <el-tag v-if="String(t.tenant_id) === String(userStore.tenantId)" size="small" type="success" style="margin-left: 8px">当前</el-tag>
                </el-dropdown-item>
              </el-dropdown-menu>
            </template>
          </el-dropdown>
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
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import {
  Monitor, Setting, SwitchButton, OfficeBuilding,
} from '@element-plus/icons-vue'
import { useUserStore } from '@/stores/user'
import { getConsoleNavSections, type NavSection } from '@/module-loader'
import ThemeSwitcher from '@multi-tenant-saas/ui-core/components/ThemeSwitcher.vue'
import ColorPicker from '@multi-tenant-saas/ui-core/components/ColorPicker.vue'
import ThemeSettings from '@multi-tenant-saas/ui-core/components/ThemeSettings.vue'
import UIFrameworkSelector from '@multi-tenant-saas/ui-core/components/UIFrameworkSelector.vue'

const route = useRoute()
const router = useRouter()
const userStore = useUserStore()
const showThemeSettings = ref(false)
const showFrameworkSelector = ref(false)

const activePath = computed(() => route.path)

// Auto-discovered navigation sections — populated from module routes at runtime
const sections = ref<NavSection[]>([])

onMounted(async () => {
  await userStore.init()
  sections.value = await getConsoleNavSections()
})

const handleLogout = async () => {
  await userStore.logout()
  router.push('/login')
}

const handleSwitchTenant = async (tenantId: number) => {
  await userStore.switchTenant(tenantId)
}
</script>

<style>
/* ===== THEME: light (default) — console green accent ===== */
:root { --ac: var(--primary-color, #10b981); --ac-r: 16,185,129; --sb: #ffffff; --sb-h: #f1f5f9; --sb-t: #64748b; --sb-ta: #0f172a; --sb-l: #94a3b8; --sb-b: #e2e8f0; --tb: #ffffff; --tb-b: #e2e8f0; --pg: #f1f5f9; --tx: #0f172a; --tx2: #64748b; --bg-color: #ffffff; --bg-color-page: #f1f5f9; --border-color: #e2e8f0; --fill-color: #f8fafc; --text-color-primary: #0f172a; --text-color-secondary: #64748b; --el-color-primary: var(--primary-color, #10b981); }

:root { --badge-success-bg: #e6ffed; --badge-success-fg: #52c41a; --badge-danger-bg: #fff1f0; --badge-danger-fg: #f5222d; --badge-warning-bg: #fff7e6; --badge-warning-fg: #fa8c16; --badge-info-bg: #f0f0f0; --badge-info-fg: #666666; --link-color: var(--primary-color, #10b981); --link-danger: #f5222d; --table-header-fg: #999999; }
html.dark { --badge-success-bg: #0d3320; --badge-success-fg: #73e2a3; --badge-danger-bg: #3b1219; --badge-danger-fg: #fca5a5; --badge-warning-bg: #3b2e08; --badge-warning-fg: #fcd34d; --badge-info-bg: #1e293b; --badge-info-fg: #94a3b8; --link-color: #6ee7b7; --link-danger: #fca5a5; --table-header-fg: #94a3b8; }

html.dark { --sb: #1e293b; --sb-h: #334155; --sb-t: #94a3b8; --sb-ta: #f1f5f9; --sb-l: #64748b; --sb-b: #334155; --tb: #1e293b; --tb-b: #334155; --pg: #0f172a; --tx: #f1f5f9; --tx2: #94a3b8; --bg-color: #1e293b; --bg-color-page: #0f172a; --border-color: #334155; --fill-color: #334155; --text-color-primary: #f1f5f9; --text-color-secondary: #94a3b8; --el-color-primary: var(--primary-color, #34d399); }
</style>

<style scoped>
.console-layout { height: 100vh; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: var(--pg); }

.sidebar { background: var(--sb); border-right: 1px solid var(--sb-b); display: flex; flex-direction: column; overflow: hidden; }
.logo { height: 64px; display: flex; align-items: center; gap: 12px; padding: 0 20px; border-bottom: 1px solid var(--sb-b); flex-shrink: 0; }
.logo-icon { width: 36px; height: 36px; border-radius: 10px; background: linear-gradient(135deg, var(--ac), color-mix(in srgb, var(--ac) 70%, #fff)); display: flex; align-items: center; justify-content: center; color: #fff; }
.logo-text { display: flex; flex-direction: column; }
.logo-title { font-size: 15px; font-weight: 700; color: var(--sb-ta); }
.logo-sub { font-size: 11px; color: var(--sb-l); margin-top: 1px; }

.sidebar-nav { flex: 1 1 0; overflow-y: auto; }
.sidebar-menu { border-right: none; background: transparent; }
.nav-section-label { padding: 16px 20px 6px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--sb-l); }

.sidebar-menu :deep(.el-menu-item) { color: var(--sb-t); border-radius: 6px; margin: 1px 8px; }
.nav-svg-icon { width: 18px; height: 18px; flex-shrink: 0; opacity: 0.7; }
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
.console-layout :deep(.el-main) { overflow-y: auto; background: var(--pg); }
</style>
