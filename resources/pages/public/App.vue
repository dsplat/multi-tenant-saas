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

const siteConfig = ref<any>({})

onMounted(async () => {
  try {
    const res = await fetch('/api/v1/public/site-config')
    const data = await res.json()
    if (data.success) siteConfig.value = data.data
  } catch {}
})
</script>
