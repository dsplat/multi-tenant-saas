<template>
  <div class="detail-panel">
    <div v-if="title" class="panel-header">
      <h3>{{ title }}</h3>
      <slot name="header-actions" />
    </div>

    <div class="panel-body">
      <div v-for="item in items" :key="item.key" class="detail-row">
        <span class="detail-label">{{ item.label }}</span>
        <span class="detail-value">
          <slot :name="`value-${item.key}`" :value="data[item.key]" :data="data">
            {{ formatValue(item, data[item.key]) }}
          </slot>
        </span>
      </div>
    </div>

    <div v-if="$slots.footer" class="panel-footer">
      <slot name="footer" />
    </div>
  </div>
</template>

<script setup lang="ts">
export interface DetailItem {
  key: string
  label: string
  type?: 'text' | 'badge' | 'date' | 'boolean'
  badgeMap?: Record<string, { text: string; class: string }>
}

defineProps<{
  title?: string
  items: DetailItem[]
  data: Record<string, any>
}>()

const formatValue = (item: DetailItem, value: any): string => {
  if (value === null || value === undefined) return '-'
  if (item.type === 'boolean') return value ? '是' : '否'
  if (item.type === 'badge' && item.badgeMap) {
    return item.badgeMap[value]?.text ?? value
  }
  return String(value)
}
</script>

<style scoped>
.detail-panel { background: var(--bg-color, #fff); border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.panel-header { display: flex; justify-content: space-between; align-items: center; padding: 20px 24px; border-bottom: 1px solid var(--border-color, #eee); }
.panel-header h3 { margin: 0; font-size: 16px; }
.panel-body { padding: 16px 24px; }
.detail-row { display: flex; padding: 10px 0; border-bottom: 1px solid var(--border-color, #f0f0f0); }
.detail-row:last-child { border-bottom: none; }
.detail-label { width: 120px; flex-shrink: 0; font-size: 13px; color: var(--text-color-secondary, #999); }
.detail-value { flex: 1; font-size: 13px; color: var(--text-color-primary, #333); word-break: break-all; }
.panel-footer { padding: 16px 24px; border-top: 1px solid var(--border-color, #eee); }
</style>
