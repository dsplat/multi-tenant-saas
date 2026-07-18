<template>
  <div class="layout console-layout">
    <aside class="sidebar">
      <div class="logo">
        <div class="logo-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 2L2 7l10 5 10-5-10-5z"/><path d="M2 17l10 5 10-5"/><path d="M2 12l10 5 10-5"/></svg>
        </div>
        <div class="logo-text"><span class="logo-title">Console</span><span class="logo-sub">租户后台</span></div>
      </div>

      <nav class="nav-menu">
        <div v-for="section in sections" :key="section.label" class="nav-section">
          <div class="nav-section-title">{{ section.label }}</div>
          <template v-for="item in section.items" :key="item.path">
            <router-link
               :to="`/${item.path}`"
               :class="['nav-item', { active: isActive(`/${item.path}`) }]">
              <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path :d="item.icon"/></svg>
              <span>{{ item.label }}</span>
            </router-link>
          </template>
        </div>
      </nav>

      <div class="sidebar-footer">
        <div class="sidebar-user">
          <div class="avatar">{{ (userStore.user?.name || 'C')[0] }}</div>
          <div class="user-info">
            <div class="user-name">{{ userStore.user?.name || '租户管理员' }}</div>
            <div class="user-role">{{ userStore.user?.role === 'tenant_admin' ? '租户管理员' : userStore.user?.role === 'platform_admin' ? '超级管理员' : userStore.user?.role || '用户' }}</div>
          </div>
        </div>
      </div>
    </aside>

    <main class="main-area">
      <header class="top-bar">
        <div class="breadcrumb">
          <svg class="breadcrumb-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"/></svg>
          <span>首页</span>
          <span v-if="route.meta.title" class="breadcrumb-sep">/</span>
          <span v-if="route.meta.title" class="breadcrumb-current">{{ route.meta.title }}</span>
        </div>
        <div class="actions">
          <button class="icon-btn" @click="showFrameworkSelector = true" title="UI 框架切换">
            <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path d="M3 4a1 1 0 011-1h12a1 1 0 011 1v2a1 1 0 01-1 1H4a1 1 0 01-1-1V4zM3 10a1 1 0 011-1h6a1 1 0 011 1v6a1 1 0 01-1 1H4a1 1 0 01-1-1v-6zM14 9a1 1 0 00-1 1v6a1 1 0 001 1h2a1 1 0 001-1v-6a1 1 0 00-1-1h-2z"/></svg>
          </button>
          <ThemeSwitcher />
          <ColorPicker />
          <button class="icon-btn" @click="showThemeSettings = true" title="主题设置">
            <svg viewBox="0 0 20 20" fill="currentColor" width="18" height="18"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>
          </button>
          <button class="logout-btn" @click="handleLogout">
            <svg viewBox="0 0 20 20" fill="currentColor" width="16" height="16"><path fill-rule="evenodd" d="M3 3a1 1 0 00-1 1v12a1 1 0 102 0V4a1 1 0 00-1-1zm10.293 9.293a1 1 0 001.414 1.414l3-3a1 1 0 000-1.414l-3-3a1 1 0 10-1.414 1.414L14.586 9H7a1 1 0 100 2h7.586l-1.293 1.293z" clip-rule="evenodd"/></svg>
            <span>退出</span>
          </button>
        </div>
      </header>

      <section class="content">
        <router-view />
      </section>
    </main>
  </div>

  <ThemeSettings v-model:visible="showThemeSettings" />
  <UIFrameworkSelector v-model:visible="showFrameworkSelector" />
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
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

const isActive = (path: string) => route.path.startsWith(path)

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
</script>

<style>
/* Global theme variables — must be defined here too since Console loads independently */
:root { --ac: var(--primary-color, #10b981); --ac-r: 16,185,129; --c-accent: var(--primary-color, #10b981); --c-accent-rgb: 16,185,129; --sb: #ffffff; --sb-h: #f1f5f9; --sb-t: #64748b; --sb-ta: #0f172a; --sb-l: #94a3b8; --sb-b: #e2e8f0; --tb: #ffffff; --tb-b: #e2e8f0; --pg: #f1f5f9; --tx: #0f172a; --tx2: #64748b; --bg-color: #ffffff; --bg-color-page: #f1f5f9; --border-color: #e2e8f0; --fill-color: #f8fafc; --text-color-primary: #0f172a; --text-color-secondary: #64748b; }
/* Global element overrides for dark mode */
html.dark h1, html.dark h2, html.dark h3, html.dark h4 { color: var(--text-color-primary, #f1f5f9); }
html.dark input, html.dark select, html.dark textarea { background: var(--bg-color, #1e293b); color: var(--text-color-primary, #f1f5f9); border-color: var(--border-color, #334155); }
html.dark select option { background: #1e293b; color: #f1f5f9; }
html.dark .page-header { background: var(--bg-color, #1e293b); padding: 16px 20px; border-radius: 8px; margin-bottom: 20px; }

/* Badge variables */
:root { --badge-success-bg: #e6ffed; --badge-success-fg: #52c41a; --badge-danger-bg: #fff1f0; --badge-danger-fg: #f5222d; --badge-warning-bg: #fff7e6; --badge-warning-fg: #fa8c16; --badge-info-bg: #f0f0f0; --badge-info-fg: #666666; --link-color: var(--primary-color, #10b981); --link-danger: #f5222d; --table-header-fg: #999999; }
html.dark { --badge-success-bg: #0d3320; --badge-success-fg: #73e2a3; --badge-danger-bg: #3b1219; --badge-danger-fg: #fca5a5; --badge-warning-bg: #3b2e08; --badge-warning-fg: #fcd34d; --badge-info-bg: #1e293b; --badge-info-fg: #94a3b8; --link-color: #6ee7b7; --link-danger: #fca5a5; --table-header-fg: #94a3b8; }

html.dark { --sb: #1e293b; --sb-h: #334155; --sb-t: #94a3b8; --sb-ta: #f1f5f9; --sb-l: #64748b; --sb-b: #334155; --tb: #1e293b; --tb-b: #334155; --pg: #0f172a; --tx: #f1f5f9; --tx2: #94a3b8; --bg-color: #1e293b; --bg-color-page: #0f172a; --border-color: #334155; --fill-color: #334155; --text-color-primary: #f1f5f9; --text-color-secondary: #94a3b8; --c-accent: var(--primary-color, #34d399); --c-accent-rgb: 52,211,153; }
</style>

<style scoped>
.layout { display: flex; height: 100vh; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; }

.sidebar { width: 260px; background: var(--sb); display: flex; flex-direction: column; flex-shrink: 0; border-right: 1px solid var(--sb-b); overflow: hidden; }
.logo { height: 64px; display: flex; align-items: center; gap: 12px; padding: 0 20px; border-bottom: 1px solid var(--sb-b); }
.logo-icon { width: 36px; height: 36px; border-radius: 10px; background: linear-gradient(135deg, var(--c-accent), color-mix(in srgb, var(--c-accent) 70%, #fff)); display: flex; align-items: center; justify-content: center; color: #fff; flex-shrink: 0; }
.logo-text { display: flex; flex-direction: column; }
.logo-title { font-size: 15px; font-weight: 700; color: var(--sb-ta); letter-spacing: -0.02em; }
.logo-sub { font-size: 11px; color: var(--sb-l); margin-top: 1px; }

.nav-menu { flex: 1; overflow-y: auto; padding: 8px 0; scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.1) transparent; min-height: 0; }
.nav-section { margin-bottom: 4px; }
.nav-section-title { padding: 16px 20px 6px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.06em; color: var(--sb-l); }

.nav-item { display: flex; align-items: center; gap: 10px; padding: 9px 20px; margin: 1px 8px; border-radius: 6px; color: var(--sb-t); text-decoration: none; font-size: 13.5px; font-weight: 450; transition: all 0.15s ease; position: relative; }
.nav-item:hover { background: var(--sb-h); color: var(--sb-ta); }
.nav-item.active { background: rgba(var(--c-accent-rgb),0.15); color: var(--sb-ta); font-weight: 500; }
.nav-item.active::before { content: ''; position: absolute; left: 0; top: 6px; bottom: 6px; width: 3px; border-radius: 0 3px 3px 0; background: var(--c-accent); }
.nav-icon { width: 18px; height: 18px; flex-shrink: 0; opacity: 0.7; }
.nav-item.active .nav-icon { opacity: 1; }

.sidebar-footer { padding: 12px 16px; border-top: 1px solid var(--sb-b); }
.sidebar-user { display: flex; align-items: center; gap: 10px; padding: 8px; border-radius: 8px; transition: background 0.15s; }
.sidebar-user:hover { background: var(--sb-h); }
.avatar { width: 32px; height: 32px; border-radius: 8px; background: linear-gradient(135deg, var(--c-accent), color-mix(in srgb, var(--c-accent) 70%, #fff)); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 13px; font-weight: 600; flex-shrink: 0; }
.user-info { overflow: hidden; }
.user-name { font-size: 13px; font-weight: 500; color: var(--sb-ta); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.user-role { font-size: 11px; color: var(--sb-l); margin-top: 1px; }

.main-area { flex: 1; display: flex; flex-direction: column; background: var(--pg); min-width: 0; min-height: 0; }
.top-bar { height: 56px; background: var(--tb); border-bottom: 1px solid var(--tb-b); display: flex; align-items: center; justify-content: space-between; padding: 0 24px; box-shadow: 0 1px 2px rgba(0,0,0,0.05); z-index: 10; }
.breadcrumb { display: flex; align-items: center; gap: 6px; font-size: 14px; color: var(--tx2); }
.breadcrumb-icon { width: 16px; height: 16px; opacity: 0.5; }
.breadcrumb-sep { color: #cbd5e1; margin: 0 2px; }
.breadcrumb-current { color: var(--tx); font-weight: 500; }
.actions { display: flex; align-items: center; gap: 8px; }

.icon-btn { width: 36px; height: 36px; border-radius: 8px; border: 1px solid var(--tb-b); background: transparent; cursor: pointer; display: flex; align-items: center; justify-content: center; color: var(--tx2); transition: all 0.15s; }
.icon-btn:hover { background: rgba(var(--c-accent-rgb),0.1); border-color: var(--c-accent); color: var(--c-accent); }

.logout-btn { display: flex; align-items: center; gap: 6px; padding: 7px 14px; border: 1px solid var(--tb-b); border-radius: 8px; background: transparent; cursor: pointer; font-size: 13px; color: var(--tx2); transition: all 0.15s; }
.logout-btn:hover { color: #ef4444; border-color: #fca5a5; background: #fef2f2; }

.content { flex: 1; padding: 24px; overflow-y: auto; min-height: 0; }
</style>
