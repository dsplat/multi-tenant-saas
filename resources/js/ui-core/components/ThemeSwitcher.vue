<template>
  <div class="theme-switcher">
    <el-dropdown @command="handleCommand">
      <el-button :icon="isDarkMode ? 'Moon' : 'Sunny'" circle />
      <template #dropdown>
        <el-dropdown-menu>
          <el-dropdown-item command="light">
            <el-icon><Sunny /></el-icon>
            浅色模式
          </el-dropdown-item>
          <el-dropdown-item command="dark">
            <el-icon><Moon /></el-icon>
            深色模式
          </el-dropdown-item>
          <el-dropdown-item command="auto">
            <el-icon><Monitor /></el-icon>
            跟随系统
          </el-dropdown-item>
        </el-dropdown-menu>
      </template>
    </el-dropdown>
  </div>
</template>

<script setup lang="ts">
import { useTheme } from '@multi-tenant-saas/ui-core'

const { isDarkMode, setThemeMode } = useTheme()

const handleCommand = (command: string) => {
  setThemeMode(command as any)
}
</script>

<style scoped>
.theme-switcher {
  display: inline-flex;
  align-items: center;
}
</style>
