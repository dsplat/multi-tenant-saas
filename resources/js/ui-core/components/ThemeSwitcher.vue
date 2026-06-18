<template>
  <button class="theme-toggle" @click="toggleDark" :title="isDark ? '切换到浅色模式' : '切换到深色模式'">
    <span v-if="isDark">&#9790;</span>
    <span v-else>&#9728;</span>
  </button>
</template>

<script setup lang="ts">
import { useTheme } from '../theme-manager'

const { isDark, toggleDark } = useTheme()
</script>

<style scoped>
.theme-toggle {
  width: 36px;
  height: 36px;
  border-radius: 50%;
  border: 1px solid var(--border-color, #ddd);
  background: var(--bg-color, #fff);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  transition: all 0.2s;
}

.theme-toggle:hover {
  border-color: var(--primary-color, #409eff);
  color: var(--primary-color, #409eff);
}
</style>
