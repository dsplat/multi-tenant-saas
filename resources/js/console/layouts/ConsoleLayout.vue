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
        <div class="nav-section">
          <div class="nav-section-title">概览</div>
          <a href="/console/dashboard" :class="['nav-item', { active: isActive('/dashboard') }]">
            <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z"/></svg>
            <span>工作台</span>
          </a>
        </div>

        <div class="nav-section">
          <div class="nav-section-title">团队与财务</div>
          <a href="/console/members" :class="['nav-item', { active: isActive('/members') }]">
            <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z"/></svg>
            <span>成员管理</span>
          </a>
          <a href="/console/credits" :class="['nav-item', { active: isActive('/credits') }]">
            <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M8.433 7.418c.155-.103.346-.196.567-.267v1.698a2.305 2.305 0 01-.567-.267C8.07 8.34 8 8.114 8 8c0-.114.07-.34.433-.582zM11 12.849v-1.698c.22.071.412.164.567.267.364.243.433.468.433.582 0 .114-.07.34-.433.582a2.305 2.305 0 01-.567.267z"/><path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-13a1 1 0 10-2 0v.092a4.535 4.535 0 00-1.676.662C6.602 6.234 6 7.009 6 8c0 .99.602 1.765 1.324 2.246.48.32 1.054.545 1.676.662v1.941c-.391-.127-.68-.317-.843-.504a1 1 0 10-1.51 1.31c.562.649 1.413 1.076 2.353 1.253V15a1 1 0 102 0v-.092a4.535 4.535 0 001.676-.662C13.398 13.766 14 12.991 14 12c0-.99-.602-1.765-1.324-2.246A4.535 4.535 0 0011 9.092V7.151c.391.127.68.317.843.504a1 1 0 101.511-1.31c-.563-.649-1.413-1.076-2.354-1.253V5z" clip-rule="evenodd"/></svg>
            <span>积分管理</span>
          </a>
        </div>

        <div class="nav-section">
          <div class="nav-section-title">集成与配置</div>
          <a href="/console/oauth" :class="['nav-item', { active: isActive('/oauth') }]">
            <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M5 9V7a5 5 0 0110 0v2a2 2 0 012 2v5a2 2 0 01-2 2H5a2 2 0 01-2-2v-5a2 2 0 012-2zm8-2v2H7V7a3 3 0 016 0z" clip-rule="evenodd"/></svg>
            <span>第三方登录</span>
          </a>
          <a href="/console/payment" :class="['nav-item', { active: isActive('/payment') }]">
            <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M4 4a2 2 0 00-2 2v1h16V6a2 2 0 00-2-2H4z"/><path fill-rule="evenodd" d="M18 9H2v5a2 2 0 002 2h12a2 2 0 002-2V9zM4 13a1 1 0 011-1h1a1 1 0 110 2H5a1 1 0 01-1-1zm5-1a1 1 0 100 2h1a1 1 0 100-2H9z" clip-rule="evenodd"/></svg>
            <span>支付配置</span>
          </a>
          <a href="/console/sms" :class="['nav-item', { active: isActive('/sms') }]">
            <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M2 5a2 2 0 012-2h7a2 2 0 012 2v4a2 2 0 01-2 2H9l-3 3v-3H4a2 2 0 01-2-2V5z"/><path d="M15 7v2a4 4 0 01-4 4H9.828l-1.766 1.767c.28.149.599.233.938.233h2l3 3v-3h2a2 2 0 002-2V9a2 2 0 00-2-2h-1z"/></svg>
            <span>短信配置</span>
          </a>
          <a href="/console/api-tokens" :class="['nav-item', { active: isActive('/api-tokens') }]">
            <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M18 8a6 6 0 01-7.743 5.743L10 14l-1 1-1 1H6v2H2v-4l4.257-4.257A6 6 0 1118 8zm-6-4a1 1 0 100 2 2 2 0 012 2 1 1 0 102 0 4 4 0 00-4-4z" clip-rule="evenodd"/></svg>
            <span>API Token</span>
          </a>
        </div>

        <div class="nav-section">
          <div class="nav-section-title">自动化与安全</div>
          <a href="/console/workflows" :class="['nav-item', { active: isActive('/workflows') }]">
            <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path d="M5 4a1 1 0 00-2 0v7.268a2 2 0 000 3.464V16a1 1 0 102 0v-1.268a2 2 0 000-3.464V4zM11 4a1 1 0 10-2 0v1.268a2 2 0 000 3.464V16a1 1 0 102 0V8.732a2 2 0 000-3.464V4zM16 3a1 1 0 011 1v7.268a2 2 0 010 3.464V16a1 1 0 11-2 0v-1.268a2 2 0 010-3.464V4a1 1 0 011-1z"/></svg>
            <span>工作流</span>
          </a>
          <a href="/console/ssl" :class="['nav-item', { active: isActive('/ssl') }]">
            <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/></svg>
            <span>SSL 证书</span>
          </a>
          <a href="/console/webhooks" :class="['nav-item', { active: isActive('/webhooks') }]">
            <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M12.395 2.553a1 1 0 00-1.45-.385c-.345.23-.614.558-.822.88-.214.33-.403.713-.57 1.116-.334.804-.614 1.768-.84 2.734a31.365 31.365 0 00-.613 3.58 2.64 2.64 0 01-.945-1.067c-.328-.68-.398-1.534-.398-2.654A1 1 0 005.05 6.05 6.981 6.981 0 003 11a7 7 0 1011.95-4.95c-.592-.591-.98-.985-1.348-1.467-.363-.476-.724-1.063-1.207-2.03zM12.12 15.12A3 3 0 017 13s.879.5 2.5.5c0-1 .5-4 1.25-4.5.5 1 .786 1.293 1.371 1.879A2.99 2.99 0 0113 13a2.99 2.99 0 01-.879 2.121z" clip-rule="evenodd"/></svg>
            <span>Webhooks</span>
          </a>
        </div>

        <div class="nav-section">
          <div class="nav-section-title">设置</div>
          <a href="/console/tenant-settings" :class="['nav-item', { active: isActive('/tenant-settings') }]">
            <svg class="nav-icon" viewBox="0 0 20 20" fill="currentColor"><path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/></svg>
            <span>邮件/认证/注册</span>
          </a>
        </div>
      </nav>

      <div class="sidebar-footer">
        <div class="sidebar-user">
          <div class="avatar">{{ userName[0] }}</div>
          <div class="user-info">
            <div class="user-name">{{ userName }}</div>
            <div class="user-role">租户管理员</div>
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
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import ThemeSwitcher from '@multi-tenant-saas/ui-core/components/ThemeSwitcher.vue'
import ColorPicker from '@multi-tenant-saas/ui-core/components/ColorPicker.vue'
import ThemeSettings from '@multi-tenant-saas/ui-core/components/ThemeSettings.vue'

const route = useRoute()
const router = useRouter()
const showThemeSettings = ref(false)
const userName = ref('租户管理员')

const isActive = (path: string) => route.path.startsWith('/console' + path)

onMounted(() => {
  const user = localStorage.getItem('console_user')
  if (user) {
    try { userName.value = JSON.parse(user).name || '租户管理员' } catch {}
  }
})

const handleLogout = () => {
  localStorage.removeItem('console_token')
  localStorage.removeItem('console_user')
  router.push('/console/login')
}
</script>

<style>
/* Console uses same global variables as Admin — no need to redefine.
   The --ac/accent override is specific to console's green accent. */
:root { --c-accent: var(--primary-color, #10b981); --c-accent-rgb: 16,185,129; }
html.dark { --c-accent: var(--primary-color, #34d399); --c-accent-rgb: 52,211,153; }
</style>

<style scoped>
.layout { display: flex; height: 100vh; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif; }

.sidebar { width: 260px; background: var(--sb); display: flex; flex-direction: column; flex-shrink: 0; border-right: 1px solid var(--sb-b); }
.logo { height: 64px; display: flex; align-items: center; gap: 12px; padding: 0 20px; border-bottom: 1px solid var(--sb-b); }
.logo-icon { width: 36px; height: 36px; border-radius: 10px; background: linear-gradient(135deg, var(--c-accent), color-mix(in srgb, var(--c-accent) 70%, #fff)); display: flex; align-items: center; justify-content: center; color: #fff; flex-shrink: 0; }
.logo-text { display: flex; flex-direction: column; }
.logo-title { font-size: 15px; font-weight: 700; color: var(--sb-ta); letter-spacing: -0.02em; }
.logo-sub { font-size: 11px; color: var(--sb-l); margin-top: 1px; }

.nav-menu { flex: 1; overflow-y: auto; padding: 8px 0; scrollbar-width: thin; scrollbar-color: rgba(255,255,255,0.1) transparent; }
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

.main-area { flex: 1; display: flex; flex-direction: column; background: var(--pg); min-width: 0; }
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

.content { flex: 1; padding: 24px; overflow-y: auto; }
</style>
