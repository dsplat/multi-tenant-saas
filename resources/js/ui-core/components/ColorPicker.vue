<template>
  <div class="color-picker">
    <el-popover trigger="click" width="300">
      <template #reference>
        <el-button :style="{ backgroundColor: primaryColor }" circle />
      </template>
      
      <div class="color-options">
        <div
          v-for="color in presetColors"
          :key="color"
          class="color-item"
          :class="{ active: primaryColor === color }"
          :style="{ backgroundColor: color }"
          @click="setPrimaryColor(color)"
        />
      </div>
      
      <el-divider />
      
      <div class="custom-color">
        <span>自定义颜色：</span>
        <el-color-picker v-model="customColor" @change="handleCustomColor" />
      </div>
    </el-popover>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useTheme } from '@multi-tenant-saas/ui-core'

const { primaryColor, setPrimaryColor } = useTheme()

const customColor = ref(primaryColor.value)

const presetColors = [
  '#409eff', // 默认蓝
  '#67c23a', // 绿色
  '#e6a23c', // 橙色
  '#f56c6c', // 红色
  '#909399', // 灰色
  '#00d1b2', // 青色
  '#b388ff', // 紫色
  '#ff6b6b', // 粉红
]

const handleCustomColor = (color: string) => {
  if (color) {
    setPrimaryColor(color)
  }
}
</script>

<style scoped>
.color-options {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 8px;
}

.color-item {
  width: 32px;
  height: 32px;
  border-radius: 4px;
  cursor: pointer;
  transition: transform 0.2s;
}

.color-item:hover {
  transform: scale(1.1);
}

.color-item.active {
  box-shadow: 0 0 0 2px #fff, 0 0 0 4px var(--primary-color);
}

.custom-color {
  display: flex;
  align-items: center;
  gap: 8px;
}
</style>
