<template>
  <div class="layout">
    <aside class="sidebar">
      <div class="logo">
        <h2>租户后台</h2>
      </div>
      <nav class="nav-menu">
        <a href="/console/dashboard" :class="['nav-item', { active: isActive('/dashboard') }]">
          <span>工作台</span>
        </a>
        <a href="/console/members" :class="['nav-item', { active: isActive('/members') }]">
          <span>成员管理</span>
        </a>
        <a href="/console/credits" :class="['nav-item', { active: isActive('/credits') }]">
          <span>积分管理</span>
        </a>
        <div class="nav-divider"></div>
        <a href="/console/oauth" :class="['nav-item', { active: isActive('/oauth') }]">
          <span>第三方登录</span>
        </a>
        <a href="/console/payment" :class="['nav-item', { active: isActive('/payment') }]">
          <span>支付配置</span>
        </a>
        <a href="/console/sms" :class="['nav-item', { active: isActive('/sms') }]">
          <span>短信配置</span>
        </a>
        <a href="/console/api-tokens" :class="['nav-item', { active: isActive('/api-tokens') }]">
          <span>API Token</span>
        </a>
        <div class="nav-divider"></div>
        <a href="/console/tenant-settings" :class="['nav-item', { active: isActive('/tenant-settings') }]">
          <span>邮件/认证/注册</span>
        </a>
      </nav>
    </aside>

    <main class="main-area">
      <header class="top-bar">
        <div class="breadcrumb">
          <span>首页</span>
          <span v-if="route.meta.title"> / {{ route.meta.title }}</span>
        </div>
        <div class="actions">
          <ThemeSwitcher />
          <ColorPicker />
          <button class="settings-btn" @click="showThemeSettings = true">&#9881;</button>
          <span class="user-name">{{ userName }}</span>
          <button class="logout-btn" @click="handleLogout">退出</button>
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
    try {
      userName.value = JSON.parse(user).name || '租户管理员'
    } catch {}
  }
})

const handleLogout = () => {
  localStorage.removeItem('console_token')
  localStorage.removeItem('console_user')
  router.push('/console/login')
}
</script>

<style scoped>
.layout {
  display: flex;
  height: 100vh;
}

.sidebar {
  width: 220px;
  background: var(--bg-color-container, #2c3e50);
  color: var(--text-color-primary, #ecf0f1);
  display: flex;
  flex-direction: column;
}

.logo {
  height: 60px;
  display: flex;
  align-items: center;
  justify-content: center;
  border-bottom: 1px solid rgba(255,255,255,0.1);
}

.logo h2 { margin: 0; font-size: 16px; }

.nav-menu { flex: 1; padding: 8px 0; }
.nav-divider { height: 1px; background: rgba(255,255,255,0.1); margin: 4px 16px; }

.nav-item {
  display: block;
  padding: 12px 20px;
  color: rgba(255,255,255,0.7);
  text-decoration: none;
  transition: all 0.15s;
}

.nav-item:hover { background: rgba(255,255,255,0.05); color: #fff; }

.nav-item.active {
  color: var(--primary-color, #409eff);
  background: color-mix(in srgb, var(--primary-color, #409eff) 12%, transparent);
  border-right: 3px solid var(--primary-color, #409eff);
}

.main-area { flex: 1; display: flex; flex-direction: column; background: var(--bg-color-page, #f5f7fa); }

.top-bar {
  height: 56px;
  background: var(--bg-color, #fff);
  border-bottom: 1px solid var(--border-color, #eee);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 20px;
}

.breadcrumb { color: var(--text-color-secondary, #999); font-size: 14px; }
.actions { display: flex; align-items: center; gap: 12px; }

.settings-btn {
  width: 32px; height: 32px; border-radius: 50%;
  border: 1px solid var(--border-color, #ddd);
  background: var(--bg-color, #fff); cursor: pointer; font-size: 16px;
}

.user-name { font-size: 14px; color: var(--text-color-primary, #333); }

.logout-btn {
  padding: 4px 12px; border: 1px solid var(--border-color, #ddd);
  border-radius: 4px; background: var(--bg-color, #fff);
  cursor: pointer; font-size: 13px; color: var(--text-color-secondary, #666);
}

.logout-btn:hover { color: #f56c6c; border-color: #f56c6c; }
.content { flex: 1; padding: 20px; overflow-y: auto; }
</style>
