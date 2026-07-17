<template>
  <el-color-picker v-model="colorVal" size="default" :predefine="presetColors" @change="handleChange" />
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useTheme } from '@multi-tenant-saas/ui-core/theme-manager'

const { config, setPrimaryColor } = useTheme()

const presetColors = [
  '#409eff', '#67c23a', '#e6a23c', '#f56c6c',
  '#909399', '#00d1b2', '#b388ff', '#ff6b6b',
]

const colorVal = ref(config.value.primaryColor)

watch(() => config.value.primaryColor, (v) => { colorVal.value = v })

const handleChange = (val: string | null) => {
  if (val) setPrimaryColor(val)
}
</script>
