<template>
  <div class="theme-settings-panel" v-if="visible">
    <div class="panel-backdrop" @click="visible = false"></div>
    <div class="panel-content">
      <div class="panel-header">
        <h3>主题设置</h3>
        <button class="close-btn" @click="visible = false">&times;</button>
      </div>

      <div class="panel-body">
        <div class="setting-section">
          <h4>主题模式</h4>
          <div class="mode-buttons">
            <button
              :class="['mode-btn', { active: config.mode === 'light' }]"
              @click="setMode('light')"
            >
              浅色
            </button>
            <button
              :class="['mode-btn', { active: config.mode === 'dark' }]"
              @click="setMode('dark')"
            >
              深色
            </button>
            <button
              :class="['mode-btn', { active: config.mode === 'auto' }]"
              @click="setMode('auto')"
            >
              自动
            </button>
          </div>
        </div>

        <div class="setting-section">
          <h4>主色调</h4>
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
          <h4>圆角大小</h4>
          <input
            type="range"
            :min="0"
            :max="12"
            :step="2"
            :value="config.borderRadius"
            @input="setBorderRadius(Number($event.target.value))"
            class="radius-slider"
          />
          <span class="radius-value">{{ config.borderRadius }}px</span>
        </div>

        <div class="setting-section">
          <h4>UI 框架</h4>
          <select v-model="selectedFramework" @change="handleFrameworkChange" class="framework-select">
            <option
              v-for="fw in frameworks"
              :key="fw.name"
              :value="fw.name"
            >
              {{ fw.label }}
            </option>
          </select>
        </div>
      </div>

      <div class="panel-footer">
        <button class="reset-btn" @click="reset">重置默认</button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useTheme } from '../theme-manager'
import { uiRegistry } from '../registry'

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

const handleFrameworkChange = () => {
  localStorage.setItem('multi-tenant-saas-ui-framework', selectedFramework.value)
  window.location.reload()
}
</script>

<style scoped>
.panel-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.3);
  z-index: 2000;
}

.panel-content {
  position: fixed;
  top: 0;
  right: 0;
  width: 320px;
  height: 100vh;
  background: var(--bg-color, #fff);
  box-shadow: -2px 0 12px rgba(0, 0, 0, 0.15);
  z-index: 2001;
  display: flex;
  flex-direction: column;
}

.panel-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 20px;
  border-bottom: 1px solid var(--border-color, #eee);
}

.panel-header h3 {
  margin: 0;
  font-size: 16px;
}

.close-btn {
  background: none;
  border: none;
  font-size: 24px;
  cursor: pointer;
  color: var(--text-color-secondary, #999);
}

.panel-body {
  flex: 1;
  overflow-y: auto;
  padding: 16px 20px;
}

.setting-section {
  margin-bottom: 24px;
}

.setting-section h4 {
  margin: 0 0 12px;
  font-size: 13px;
  color: var(--text-color-secondary, #999);
}

.mode-buttons {
  display: flex;
  gap: 8px;
}

.mode-btn {
  flex: 1;
  padding: 8px;
  border: 1px solid var(--border-color, #ddd);
  border-radius: 6px;
  background: var(--bg-color, #fff);
  cursor: pointer;
  font-size: 13px;
  color: var(--text-color-primary, #333);
}

.mode-btn.active {
  border-color: var(--primary-color, #409eff);
  color: var(--primary-color, #409eff);
  background: color-mix(in srgb, var(--primary-color, #409eff) 10%, transparent);
}

.preset-colors {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

.color-swatch {
  width: 28px;
  height: 28px;
  border-radius: 6px;
  cursor: pointer;
  transition: transform 0.15s;
  border: 2px solid transparent;
}

.color-swatch:hover {
  transform: scale(1.15);
}

.color-swatch.active {
  border-color: var(--text-color-primary, #333);
  box-shadow: 0 0 0 2px var(--bg-color, #fff);
}

.radius-slider {
  width: 100%;
  margin: 8px 0;
}

.radius-value {
  font-size: 12px;
  color: var(--text-color-secondary, #999);
}

.framework-select {
  width: 100%;
  padding: 8px 12px;
  border: 1px solid var(--border-color, #ddd);
  border-radius: 6px;
  background: var(--bg-color, #fff);
  color: var(--text-color-primary, #333);
  font-size: 13px;
}

.panel-footer {
  padding: 16px 20px;
  border-top: 1px solid var(--border-color, #eee);
}

.reset-btn {
  width: 100%;
  padding: 8px;
  border: 1px solid var(--border-color, #ddd);
  border-radius: 6px;
  background: var(--bg-color, #fff);
  cursor: pointer;
  color: var(--text-color-secondary, #999);
}

.reset-btn:hover {
  color: var(--text-color-primary, #333);
}
</style>
