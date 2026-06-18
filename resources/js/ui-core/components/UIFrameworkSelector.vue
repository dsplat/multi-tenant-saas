<template>
  <div class="ui-framework-selector" v-if="visible">
    <div class="modal-backdrop" @click="visible = false"></div>
    <div class="modal-content" @click.stop>
      <div class="modal-header">
        <h3>选择 UI 框架</h3>
        <button class="close-btn" @click="visible = false">&times;</button>
      </div>

      <div class="modal-body">
        <div class="framework-grid">
          <div
            v-for="framework in frameworks"
            :key="framework.name"
            class="framework-card"
            :class="{ active: activeFramework === framework.name }"
            @click="handleSelect(framework.name)"
          >
            <div class="framework-header">
              <h4>{{ framework.label }}</h4>
              <span v-if="activeFramework === framework.name" class="current-badge">当前使用</span>
            </div>
            <p class="framework-desc">{{ framework.description }}</p>
            <div class="framework-features">
              <span v-for="feature in framework.features.slice(0, 3)" :key="feature" class="feature-tag">
                {{ feature }}
              </span>
            </div>
            <div class="framework-footer">
              <span class="version">v{{ framework.version }}</span>
              <a :href="framework.website" target="_blank" class="link">官网</a>
            </div>
          </div>
        </div>
      </div>

      <div class="modal-footer">
        <button class="cancel-btn" @click="visible = false">取消</button>
        <button class="apply-btn" @click="handleApply" :disabled="!selectedFramework || applying">
          {{ applying ? '切换中...' : '应用并刷新' }}
        </button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { uiRegistry } from '../registry'
import type { UIFrameworkName } from '../registry'

const visible = defineModel<boolean>('visible', { default: false })

const applying = ref(false)
const selectedFramework = ref<UIFrameworkName | null>(null)

const frameworks = computed(() => uiRegistry.getAllMetadata())
const activeFramework = computed(() => uiRegistry.getActiveName())

const handleSelect = (name: UIFrameworkName) => {
  selectedFramework.value = name
}

const handleApply = async () => {
  if (!selectedFramework.value) return
  
  applying.value = true
  
  try {
    localStorage.setItem('multi-tenant-saas-ui-framework', selectedFramework.value)
    uiRegistry.setActive(selectedFramework.value)
    
    setTimeout(() => {
      window.location.reload()
    }, 500)
  } catch (error) {
    console.error(error)
  } finally {
    applying.value = false
  }
}
</script>

<style scoped>
.modal-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.5);
  z-index: 2000;
}

.modal-content {
  position: fixed;
  top: 50%;
  left: 50%;
  transform: translate(-50%, -50%);
  background: var(--bg-color, #fff);
  border-radius: 12px;
  width: 700px;
  max-height: 80vh;
  z-index: 2001;
  box-shadow: 0 8px 32px rgba(0, 0, 0, 0.2);
}

.modal-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 20px 24px;
  border-bottom: 1px solid var(--border-color, #eee);
}

.modal-header h3 { margin: 0; }

.close-btn {
  background: none;
  border: none;
  font-size: 24px;
  cursor: pointer;
  color: var(--text-color-secondary, #999);
}

.modal-body {
  padding: 20px 24px;
  overflow-y: auto;
  max-height: 50vh;
}

.framework-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 16px;
}

.framework-card {
  border: 2px solid var(--border-color, #ddd);
  border-radius: 8px;
  padding: 16px;
  cursor: pointer;
  transition: all 0.2s;
}

.framework-card:hover {
  border-color: var(--primary-color, #409eff);
}

.framework-card.active {
  border-color: var(--primary-color, #409eff);
  background: color-mix(in srgb, var(--primary-color, #409eff) 5%, transparent);
}

.framework-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}

.framework-header h4 { margin: 0; font-size: 15px; }

.current-badge {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 4px;
  background: #e8f5e9;
  color: #2e7d32;
}

.framework-desc {
  color: var(--text-color-secondary, #999);
  font-size: 12px;
  margin: 0 0 10px;
}

.framework-features {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 10px;
}

.feature-tag {
  font-size: 11px;
  padding: 2px 6px;
  border-radius: 3px;
  background: var(--fill-color, #f5f5f5);
  color: var(--text-color-secondary, #666);
}

.framework-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.version { font-size: 11px; color: var(--text-color-secondary, #999); }
.link { font-size: 12px; color: var(--primary-color, #409eff); text-decoration: none; }

.modal-footer {
  display: flex;
  justify-content: flex-end;
  gap: 12px;
  padding: 16px 24px;
  border-top: 1px solid var(--border-color, #eee);
}

.cancel-btn, .apply-btn {
  padding: 8px 20px;
  border-radius: 6px;
  font-size: 13px;
  cursor: pointer;
}

.cancel-btn {
  border: 1px solid var(--border-color, #ddd);
  background: var(--bg-color, #fff);
}

.apply-btn {
  border: none;
  background: var(--primary-color, #409eff);
  color: #fff;
}

.apply-btn:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
