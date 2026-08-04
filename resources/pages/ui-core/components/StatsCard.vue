<template>
  <el-card shadow="hover" class="stats-card" @click="$emit('click')">
    <div class="stats-card__body">
      <div class="stats-card__icon" v-if="icon">
        <el-icon :size="28"><component :is="icon" /></el-icon>
      </div>
      <div class="stats-card__content">
        <div class="stats-card__label">{{ label }}</div>
        <div class="stats-card__value">{{ value }}</div>
        <div v-if="trend != null" class="stats-card__trend" :class="trend >= 0 ? 'is-up' : 'is-down'">
          {{ trend >= 0 ? '↑' : '↓' }} {{ Math.abs(trend) }}%
          <span v-if="trendLabel" class="stats-card__trend-label">{{ trendLabel }}</span>
        </div>
      </div>
    </div>
  </el-card>
</template>

<script setup lang="ts">
/**
 * Dashboard 统计卡片
 *
 * 对接 /api/v1/{admin,console}/dashboard 返回的统计项
 * （{ label, value, key }），trend 为可选环比百分比。
 */
defineProps<{
  label: string
  value: string | number
  icon?: any
  trend?: number | null
  trendLabel?: string
}>()

defineEmits<{
  (e: 'click'): void
}>()
</script>

<style scoped>
.stats-card {
  cursor: default;
}
.stats-card__body {
  display: flex;
  align-items: center;
  gap: 16px;
}
.stats-card__icon {
  color: var(--el-color-primary);
}
.stats-card__label {
  font-size: 13px;
  color: var(--el-text-color-secondary);
}
.stats-card__value {
  font-size: 26px;
  font-weight: 600;
  line-height: 1.3;
}
.stats-card__trend {
  font-size: 12px;
}
.stats-card__trend.is-up {
  color: var(--el-color-success);
}
.stats-card__trend.is-down {
  color: var(--el-color-danger);
}
.stats-card__trend-label {
  color: var(--el-text-color-secondary);
  margin-left: 4px;
}
</style>
