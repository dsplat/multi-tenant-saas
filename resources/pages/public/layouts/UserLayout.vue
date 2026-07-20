<template>
  <div class="user-layout">
    <aside class="user-sidebar">
      <div class="sidebar-header">
        <router-link to="/" class="brand">{{ siteName }}</router-link>
      </div>
      <nav class="sidebar-nav">
        <router-link to="/dashboard" class="nav-item" active-class="active">
          <span class="nav-icon">📊</span> 概览
        </router-link>
        <router-link to="/profile" class="nav-item" active-class="active">
          <span class="nav-icon">👤</span> 个人资料
        </router-link>
        <router-link to="/security" class="nav-item" active-class="active">
          <span class="nav-icon">🔒</span> 安全设置
        </router-link>
        <router-link to="/notifications" class="nav-item" active-class="active">
          <span class="nav-icon">🔔</span> 通知中心
          <span v-if="unreadCount > 0" class="badge">{{ unreadCount }}</span>
        </router-link>
      </nav>
      <div class="sidebar-footer">
        <div class="user-info" v-if="user">
          <span class="user-name">{{ user.name }}</span>
          <span class="user-email">{{ user.email }}</span>
        </div>
        <button class="btn-logout" @click="handleLogout">退出登录</button>
      </div>
    </aside>
    <main class="user-main">
      <router-view />
    </main>
  </div>
</template>

<script setup lang="ts">
import { onMounted, ref } from 'vue'
import { useRouter } from 'vue-router'

const router = useRouter()
const siteName = ref('Platform')
const user = ref<any>(null)
const unreadCount = ref(0)

onMounted(async () => {
  // 读取站点配置
  const config = (window as any).__SITE_CONFIG__
  if (config?.platform_name) {
    siteName.value = config.platform_name
  }

  // 读取用户信息
  try {
    const stored = localStorage.getItem('user_info')
    if (stored) user.value = JSON.parse(stored)
  } catch {}

  // 获取未读通知数
  await fetchUnread()
})

async function fetchUnread() {
  try {
    const token = localStorage.getItem('user_token')
    const res = await fetch('/api/v1/in-app-notifications/unread-count', {
      headers: { Authorization: `Bearer ${token}` },
    })
    const data = await res.json()
    if (data.success) {
      unreadCount.value = data.unread_count ?? data.data?.unread_count ?? 0
    }
  } catch {}
}

async function handleLogout() {
  try {
    const token = localStorage.getItem('user_token')
    await fetch('/api/v1/auth/logout', {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}` },
    })
  } catch {}
  localStorage.removeItem('user_token')
  localStorage.removeItem('user_info')
  window.location.href = '/login'
}
</script>

<style scoped>
.user-layout {
  display: flex;
  min-height: 100vh;
}
.user-sidebar {
  width: 240px;
  background: #1a1a2e;
  color: #fff;
  display: flex;
  flex-direction: column;
  position: fixed;
  top: 0;
  left: 0;
  bottom: 0;
}
.sidebar-header {
  padding: 20px;
  border-bottom: 1px solid rgba(255,255,255,0.1);
}
.brand {
  color: #fff;
  font-size: 18px;
  font-weight: 600;
  text-decoration: none;
}
.sidebar-nav {
  flex: 1;
  padding: 12px 0;
}
.nav-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 20px;
  color: rgba(255,255,255,0.7);
  text-decoration: none;
  font-size: 14px;
  transition: all 0.2s;
}
.nav-item:hover {
  background: rgba(255,255,255,0.05);
  color: #fff;
}
.nav-item.active {
  background: rgba(255,255,255,0.1);
  color: #fff;
  border-right: 3px solid #4fc3f7;
}
.nav-icon {
  font-size: 16px;
}
.badge {
  margin-left: auto;
  background: #e53935;
  color: #fff;
  font-size: 11px;
  padding: 2px 6px;
  border-radius: 10px;
  min-width: 18px;
  text-align: center;
}
.sidebar-footer {
  padding: 16px 20px;
  border-top: 1px solid rgba(255,255,255,0.1);
}
.user-info {
  display: flex;
  flex-direction: column;
  margin-bottom: 12px;
}
.user-name {
  font-size: 14px;
  font-weight: 500;
}
.user-email {
  font-size: 12px;
  color: rgba(255,255,255,0.5);
}
.btn-logout {
  width: 100%;
  padding: 8px;
  background: rgba(255,255,255,0.1);
  border: none;
  border-radius: 4px;
  color: rgba(255,255,255,0.8);
  cursor: pointer;
  font-size: 13px;
}
.btn-logout:hover {
  background: rgba(255,255,255,0.15);
}
.user-main {
  flex: 1;
  margin-left: 240px;
  padding: 32px;
  background: #f5f5f5;
  min-height: 100vh;
}
</style>
