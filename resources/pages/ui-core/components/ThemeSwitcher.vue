<template>
  <el-button :icon="isDark ? Moon : Sunny" circle size="default" @click="toggleDark"
    :title="isDark ? '切换到浅色模式' : '切换到深色模式'" />
</template>

<script setup lang="ts">
import { Moon, Sunny } from '@element-plus/icons-vue'
import { useTheme } from '@multi-tenant-saas/ui-core/theme-manager'

const { isDark, toggleDark } = useTheme()
</script>
