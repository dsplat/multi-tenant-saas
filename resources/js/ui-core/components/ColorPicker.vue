<template>
  <div class="color-picker-wrapper" ref="wrapperRef">
    <button
      class="color-trigger"
      :style="{ backgroundColor: primaryColor }"
      @click="showPopover = !showPopover"
    />
    <div class="color-popover" v-if="showPopover">
      <div class="color-grid">
        <div
          v-for="color in presetColors"
          :key="color"
          class="color-item"
          :class="{ active: primaryColor === color }"
          :style="{ backgroundColor: color }"
          @click="handleSelect(color)"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useTheme } from '../theme-manager'

const { config, setPrimaryColor } = useTheme()

const primaryColor = computed(() => config.value.primaryColor)
const showPopover = ref(false)
const wrapperRef = ref<HTMLElement>()

const presetColors = [
  '#409eff', '#67c23a', '#e6a23c', '#f56c6c',
  '#909399', '#00d1b2', '#b388ff', '#ff6b6b',
]

const handleSelect = (color: string) => {
  setPrimaryColor(color)
  showPopover.value = false
}

const handleClickOutside = (e: MouseEvent) => {
  if (wrapperRef.value && !wrapperRef.value.contains(e.target as Node)) {
    showPopover.value = false
  }
}

onMounted(() => document.addEventListener('click', handleClickOutside))
onUnmounted(() => document.removeEventListener('click', handleClickOutside))
</script>

<style scoped>
.color-picker-wrapper {
  position: relative;
}

.color-trigger {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  border: 2px solid var(--border-color, #ddd);
  cursor: pointer;
}

.color-popover {
  position: absolute;
  top: 36px;
  right: 0;
  background: var(--bg-color, #fff);
  border: 1px solid var(--border-color, #ddd);
  border-radius: 8px;
  padding: 12px;
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
  z-index: 1000;
}

.color-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 8px;
}

.color-item {
  width: 24px;
  height: 24px;
  border-radius: 4px;
  cursor: pointer;
  transition: transform 0.15s;
}

.color-item:hover {
  transform: scale(1.2);
}

.color-item.active {
  box-shadow: 0 0 0 2px var(--bg-color, #fff), 0 0 0 4px var(--primary-color, #409eff);
}
</style>
