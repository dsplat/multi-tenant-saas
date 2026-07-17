<template>
  <el-drawer v-model="visible" title="主题设置" size="320px" direction="rtl">
    <div class="settings-body">
      <div class="setting-section">
        <div class="section-label">主题模式</div>
        <el-radio-group v-model="modeVal" @change="handleModeChange">
          <el-radio-button value="light">浅色</el-radio-button>
          <el-radio-button value="dark">深色</el-radio-button>
          <el-radio-button value="auto">自动</el-radio-button>
        </el-radio-group>
      </div>

      <div class="setting-section">
        <div class="section-label">主色调</div>
        <div class="preset-colors">
          <div
            v-for="preset in presets"
            :key="preset.name"
            class="color-swatch"
            :class="{ active: config.primaryColor === preset.config.primaryColor }"
            :style="{ backgroundColor: preset.config.primaryColor }"
            :title="preset.label"
            @click="setPrimaryColor(preset.config.primaryColor!)"
          />
        </div>
      </div>

      <div class="setting-section">
        <div class="section-label">圆角大小</div>
        <div class="radius-row">
          <el-slider :min="0" :max="12" :step="2" :model-value="config.borderRadius"
            @update:model-value="(v: number) => setBorderRadius(v)" style="flex: 1" />
          <span class="radius-value">{{ config.borderRadius }}px</span>
        </div>
      </div>

      <div class="setting-section">
        <div class="section-label">UI 框架</div>
        <el-select v-model="selectedFramework" @change="handleFrameworkChange" style="width: 100%">
          <el-option
            v-for="fw in frameworks"
            :key="fw.name"
            :label="fw.label"
            :value="fw.name"
          />
        </el-select>
      </div>
    </div>

    <template #footer>
      <el-button @click="reset">重置默认</el-button>
    </template>
  </el-drawer>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useTheme } from '@multi-tenant-saas/ui-core/theme-manager'
import { uiRegistry } from '@multi-tenant-saas/ui-core/registry'

const visible = defineModel<boolean>('visible', { default: false })

const {
  config,
  presets,
  setMode,
  setPrimaryColor,
  setBorderRadius,
  reset,
} = useTheme()

const frameworks = uiRegistry.getAllMetadata()
const selectedFramework = ref(uiRegistry.getActiveName() || 'element-plus')

const modeVal = computed({
  get: () => config.value.mode,
  set: (v) => setMode(v as 'light' | 'dark' | 'auto'),
})

const handleModeChange = (v: string) => setMode(v as 'light' | 'dark' | 'auto')

const handleFrameworkChange = () => {
  localStorage.setItem('multi-tenant-saas-ui-framework', selectedFramework.value)
  window.location.reload()
}
</script>

<style scoped>
.settings-body { padding: 0 4px; }
.setting-section { margin-bottom: 24px; }
.section-label { font-size: 13px; color: var(--text-color-secondary, #909399); margin-bottom: 10px; }
.preset-colors { display: flex; gap: 8px; flex-wrap: wrap; }
.color-swatch { width: 28px; height: 28px; border-radius: 6px; cursor: pointer; transition: transform 0.15s; border: 2px solid transparent; }
.color-swatch:hover { transform: scale(1.15); }
.color-swatch.active { border-color: var(--text-color-primary, #303133); box-shadow: 0 0 0 2px var(--bg-color, #fff); }
.radius-row { display: flex; align-items: center; gap: 12px; }
.radius-value { font-size: 12px; color: var(--text-color-secondary, #909399); white-space: nowrap; }
</style>
