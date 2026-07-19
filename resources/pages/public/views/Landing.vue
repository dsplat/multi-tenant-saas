<template>
  <div class="hero">
    <h1>{{ siteConfig.platform_name || 'Multi-Tenant SaaS' }}</h1>
    <p>一站式多团队 SaaS 平台，助力企业快速搭建数字化业务系统</p>
    <div class="hero-actions">
      <router-link to="/register" class="btn btn-primary" style="width: auto;">立即注册</router-link>
      <router-link to="/apply" class="btn btn-outline">申请团队</router-link>
    </div>
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
