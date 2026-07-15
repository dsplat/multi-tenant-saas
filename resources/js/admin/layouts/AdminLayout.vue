<template>
  <div class="layout">
    <aside class="sidebar">
      <div class="logo">
        <h2>系统后台</h2>
      </div>
      <nav class="nav-menu">
        <a href="/admin/dashboard" :class="['nav-item', { active: isActive('/dashboard') }]">
          <span>仪表盘</span>
        </a>
        <a href="/admin/tenants" :class="['nav-item', { active: isActive('/tenants') }]">
          <span>租户管理</span>
        </a>
        <a href="/admin/users" :class="['nav-item', { active: isActive('/users') }]">
          <span>用户管理</span>
        </a>
        <a href="/admin/domains" :class="['nav-item', { active: isActive('/domains') }]">
          <span>域名管理</span>
        </a>
        <a href="/admin/oauth" :class="['nav-item', { active: isActive('/oauth') }]">
          <span>第三方登录</span>
        </a>
        <a href="/admin/audit-logs" :class="['nav-item', { active: isActive('/audit-logs') }]">
          <span>审计日志</span>
        </a>
        <a href="/admin/sms" :class="['nav-item', { active: isActive('/sms') }]">
          <span>短信配置</span>
        </a>
        <a href="/admin/payments" :class="['nav-item', { active: isActive('/payments') }]">
          <span>支付订单</span>
        </a>
        <a href="/admin/api-tokens" :class="['nav-item', { active: isActive('/api-tokens') }]">
          <span>API Token</span>
        </a>
        <a href="/admin/quotas" :class="['nav-item', { active: isActive('/quotas') }]">
          <span>配额管理</span>
        </a>
        <div class="nav-divider"></div>
        <a href="/admin/operators" :class="['nav-item', { active: isActive('/operators') }]">
          <span>运营人员</span>
        </a>
        <a href="/admin/roles" :class="['nav-item', { active: isActive('/roles') }]">
          <span>角色权限</span>
        </a>
        <a href="/admin/plans" :class="['nav-item', { active: isActive('/plans') }]">
          <span>订阅计划</span>
        </a>
        <div class="nav-divider"></div>
        <a href="/admin/modules" :class="['nav-item', { active: isActive('/modules') }]">
          <span>模块管理</span>
        </a>
        <a href="/admin/plugins" :class="['nav-item', { active: isActive('/plugins') }]">
          <span>插件管理</span>
        </a>
        <a href="/admin/ssl" :class="['nav-item', { active: isActive('/ssl') }]">
          <span>SSL 证书</span>
        </a>
        <a href="/admin/webhooks" :class="['nav-item', { active: isActive('/webhooks') }]">
          <span>Webhooks</span>
        </a>
        <div class="nav-divider"></div>
        <a href="/admin/feature-flags" :class="['nav-item', { active: isActive('/feature-flags') }]">
          <span>功能开关</span>
        </a>
        <a href="/admin/ip-whitelist" :class="['nav-item', { active: isActive('/ip-whitelist') }]">
          <span>IP 白名单</span>
        </a>
        <a href="/admin/branding" :class="['nav-item', { active: isActive('/branding') }]">
          <span>品牌配置</span>
        </a>
        <a href="/admin/sso-providers" :class="['nav-item', { active: isActive('/sso-providers') }]">
          <span>SSO 提供商</span>
        </a>
        <a href="/admin/credits" :class="['nav-item', { active: isActive('/credits') }]">
          <span>积分总览</span>
        </a>
        <div class="nav-divider"></div>
        <a href="/admin/system-settings" :class="['nav-item', { active: isActive('/system-settings') }]">
          <span>系统设置</span>
        </a>
        <a href="/admin/tenant-keys" :class="['nav-item', { active: isActive('/tenant-keys') }]">
          <span>租户密钥</span>
        </a>
        <a href="/admin/retention-policies" :class="['nav-item', { active: isActive('/retention-policies') }]">
          <span>数据保留</span>
        </a>
        <a href="/admin/consents" :class="['nav-item', { active: isActive('/consents') }]">
          <span>合规同意</span>
        </a>
        <a href="/admin/sandbox" :class="['nav-item', { active: isActive('/sandbox') }]">
          <span>沙箱环境</span>
        </a>
        <div class="nav-divider"></div>
        <a href="/admin/settings" :class="['nav-item', { active: isActive('/settings') }]">
          <span>配置中心</span>
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
          <span class="user-name">{{ userStore.user?.name || '管理员' }}</span>
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
import { ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useUserStore } from '../stores/user'
import ThemeSwitcher from '@multi-tenant-saas/ui-core/components/ThemeSwitcher.vue'
import ColorPicker from '@multi-tenant-saas/ui-core/components/ColorPicker.vue'
import ThemeSettings from '@multi-tenant-saas/ui-core/components/ThemeSettings.vue'

const route = useRoute()
const router = useRouter()
const userStore = useUserStore()
const showThemeSettings = ref(false)

const isActive = (path: string) => route.path.startsWith('/admin' + path)

const handleLogout = async () => {
  await userStore.logout()
  router.push('/admin/login')
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

.logo h2 {
  margin: 0;
  font-size: 16px;
}

.nav-menu {
  flex: 1;
  padding: 8px 0;
}

.nav-divider {
  height: 1px;
  background: rgba(255,255,255,0.1);
  margin: 4px 16px;
}

.nav-item {
  display: block;
  padding: 12px 20px;
  color: rgba(255,255,255,0.7);
  text-decoration: none;
  transition: all 0.15s;
}

.nav-item:hover {
  background: rgba(255,255,255,0.05);
  color: #fff;
}

.nav-item.active {
  color: var(--primary-color, #409eff);
  background: color-mix(in srgb, var(--primary-color, #409eff) 12%, transparent);
  border-right: 3px solid var(--primary-color, #409eff);
}

.main-area {
  flex: 1;
  display: flex;
  flex-direction: column;
  background: var(--bg-color-page, #f5f7fa);
}

.top-bar {
  height: 56px;
  background: var(--bg-color, #fff);
  border-bottom: 1px solid var(--border-color, #eee);
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 20px;
}

.breadcrumb {
  color: var(--text-color-secondary, #999);
  font-size: 14px;
}

.actions {
  display: flex;
  align-items: center;
  gap: 12px;
}

.settings-btn {
  width: 32px;
  height: 32px;
  border-radius: 50%;
  border: 1px solid var(--border-color, #ddd);
  background: var(--bg-color, #fff);
  cursor: pointer;
  font-size: 16px;
}

.user-name {
  font-size: 14px;
  color: var(--text-color-primary, #333);
}

.logout-btn {
  padding: 4px 12px;
  border: 1px solid var(--border-color, #ddd);
  border-radius: 4px;
  background: var(--bg-color, #fff);
  cursor: pointer;
  font-size: 13px;
  color: var(--text-color-secondary, #666);
}

.logout-btn:hover {
  color: #f56c6c;
  border-color: #f56c6c;
}

.content {
  flex: 1;
  padding: 20px;
  overflow-y: auto;
}
</style>
