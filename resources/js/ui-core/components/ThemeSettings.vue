<template>
  <el-drawer
    v-model="visible"
    title="主题设置"
    size="300px"
  >
    <div class="theme-settings">
      <div class="setting-item">
        <span class="label">主题模式</span>
        <el-radio-group v-model="themeMode" @change="handleModeChange">
          <el-radio-button value="light">浅色</el-radio-button>
          <el-radio-button value="dark">深色</el-radio-button>
          <el-radio-button value="auto">自动</el-radio-button>
        </el-radio-group>
      </div>
      
      <el-divider />
      
      <div class="setting-item">
        <span class="label">主色调</span>
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
      </div>
      
      <el-divider />
      
      <div class="setting-item">
        <span class="label">圆角大小</span>
        <el-slider
          v-model="borderRadius"
          :min="0"
          :max="12"
          :step="2"
          @change="handleRadiusChange"
        />
      </div>
      
      <el-divider />
      
      <div class="setting-item">
        <span class="label">UI 框架</span>
        <el-select v-model="selectedFramework" @change="handleFrameworkChange">
          <el-option
            v-for="framework in availableFrameworks"
            :key="framework"
            :label="frameworkLabels[framework]"
            :value="framework"
          />
        </el-select>
      </div>
    </div>
  </el-drawer>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useTheme, getAvailableFrameworks } from '@multi-tenant-saas/ui-core'
import type { UIFramework } from '@multi-tenant-saas/ui-core'

const visible = defineModel<boolean>('visible', { default: false })

const { theme, setThemeMode, setPrimaryColor, setBorderRadius } = useTheme()

const themeMode = ref(theme.value.mode)
const borderRadius = ref(theme.value.borderRadius)
const selectedFramework = ref<UIF>(import.meta.env.VITE_UI_FRAMEWORK || 'element-plus')

const availableFrameworks = getAvailableFrameworks()

const frameworkLabels: Record<UIFramework, string> = {
  'element-plus': 'Element Plus',
  'ant-design': 'Ant Design Vue',
  'naive-ui': 'Naive UI',
}

const presetColors = [
  '#409eff',
  '#67c23a',
  '#e6a23c',
  '#f56c6c',
  '#909399',
  '#00d1b2',
  '#b388ff',
  '#ff6b6b',
]

const primaryColor = computed(() => theme.value.primaryColor)

const handleModeChange = (mode: string) => {
  setThemeMode(mode as any)
}

const handleRadiusChange = (radius: number) => {
  setBorderRadius(radius)
}

const handleFrameworkChange = (framework: UIFramework) => {
  // 保存到本地存储
  localStorage.setItem('multi-tenant-saas-ui-framework', framework)
  // 提示用户需要刷新
  window.location.reload()
}
</script>

<style scoped>
.theme-settings {
  padding: 0 16px;
}

.setting-item {
  margin-bottom: 16px;
}

.setting-item .label {
  display: block;
  margin-bottom: 8px;
  font-weight: 500;
}

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
</style>
