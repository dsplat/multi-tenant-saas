<template>
  <div class="stats-card">
    <div class="stats-label">{{ label }}</div>
    <div class="stats-value">{{ value }}</div>
    <div v-if="suffix" class="stats-suffix">{{ suffix }}</div>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  label: string
  value: string | number
  suffix?: string
}>()
</script>

<style scoped>
.stats-card {
  background: var(--bg-color, #fff);
  border-radius: 8px;
  padding: 20px;
  box-shadow: 0 1px 4px rgba(0, 0, 0, 0.08);
}

.stats-label {
  font-size: 13px;
  color: var(--text-color-secondary, #999);
  margin-bottom: 8px;
}

.stats-value {
  font-size: 28px;
  font-weight: bold;
  color: var(--primary-color, #409eff);
}

.stats-suffix {
  font-size: 12px;
  color: var(--text-color-secondary, #999);
  margin-top: 4px;
}
</style>
