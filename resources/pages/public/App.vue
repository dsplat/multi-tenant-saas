<template>
  <div id="public-app">
    <!-- 公开页面导航栏（用户中心页面由 UserLayout 接管） -->
    <nav v-if="!isUserCenter" class="nav">
      <router-link to="/" class="nav-brand">{{ siteConfig.platform_name || 'Multi-Tenant SaaS' }}</router-link>
      <div class="nav-links">
        <template v-if="!isLoggedIn">
          <router-link to="/login">登录</router-link>
          <router-link to="/register">注册</router-link>
        </template>
        <template v-else>
          <router-link to="/dashboard">用户中心</router-link>
        </template>
      </div>
    </nav>
    <router-view />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'

const route = useRoute()

// 初始值优先读 index.html 预注入的 window.__SITE_CONFIG__，避免首屏闪烁
const siteConfig = ref<any>((window as any).__SITE_CONFIG__ || {})

// 判断是否在用户中心区域（UserLayout 子路由）
const isUserCenter = computed(() => {
  return route.matched.some(r => r.meta?.requiresAuth === true)
})

const isLoggedIn = computed(() => !!localStorage.getItem('user_token'))

onMounted(async () => {
  try {
    const res = await fetch('/api/v1/public/site-config')
    const data = await res.json()
    if (data.success) {
      siteConfig.value = data.data
      // 缓存到 localStorage，下次首屏即可同步读取
      try {
        localStorage.setItem('__site_config__', JSON.stringify(data.data))
      } catch {}
    }
  } catch {}
})
</script>
