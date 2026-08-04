<template>
  <el-drawer :model-value="visible" :title="title" :size="size" @close="emit('update:visible', false)">
    <el-descriptions :column="column" border>
      <el-descriptions-item v-for="item in items" :key="item.label" :label="item.label" :span="item.span">
        <slot :name="`item-${item.prop || item.label}`" :item="item">
          {{ item.value ?? '—' }}
        </slot>
      </el-descriptions-item>
    </el-descriptions>
    <slot />
  </el-drawer>
</template>

<script setup lang="ts">
/**
 * 只读详情抽屉
 *
 * items 为 key-value 描述列表；单项可用 `item-<prop>` 插槽自定义渲染。
 */
export interface DetailItem {
  label: string
  value?: string | number | boolean | null
  prop?: string
  span?: number
}

withDefaults(defineProps<{
  visible: boolean
  title?: string
  items: DetailItem[]
  column?: number
  size?: string | number
}>(), {
  title: '详情',
  column: 1,
  size: '420px',
})

const emit = defineEmits<{
  (e: 'update:visible', val: boolean): void
}>()
</script>
