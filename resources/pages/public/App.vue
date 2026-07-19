<template>
  <div id="public-app">
    <nav class="nav">
      <router-link to="/" class="nav-brand">{{ siteConfig.platform_name || 'Multi-Tenant SaaS' }}</router-link>
      <div class="nav-links">
        <router-link to="/login">登录</router-link>
        <router-link to="/register">注册</router-link>
      </div>
    </nav>
    <router-view />
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'

// 初始值优先读 index.html 预注入的 window.__SITE_CONFIG__，避免首屏闪烁
const siteConfig = ref<any>((window as any).__SITE_CONFIG__ || {})

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
