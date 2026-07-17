<template>
  <el-dialog v-model="visible" title="选择 UI 框架" width="700px" :close-on-click-modal="false">
    <div class="framework-grid">
      <el-card
        v-for="framework in frameworks"
        :key="framework.name"
        shadow="hover"
        :class="['framework-card', { active: activeFramework === framework.name, selected: selectedFramework === framework.name }]"
        @click="handleSelect(framework.name)"
      >
        <div class="framework-header">
          <span class="framework-name">{{ framework.label }}</span>
          <el-tag v-if="activeFramework === framework.name" type="success" size="small">当前使用</el-tag>
        </div>
        <p class="framework-desc">{{ framework.description }}</p>
        <div class="framework-features">
          <el-tag v-for="feature in framework.features.slice(0, 3)" :key="feature" type="info" size="small">
            {{ feature }}
          </el-tag>
        </div>
        <div class="framework-footer">
          <span class="version">v{{ framework.version }}</span>
          <el-link type="primary" :href="framework.website" target="_blank" size="small">官网</el-link>
        </div>
      </el-card>
    </div>

    <template #footer>
      <el-button @click="visible = false">取消</el-button>
      <el-button type="primary" @click="handleApply" :disabled="!selectedFramework || applying">
        {{ applying ? '切换中...' : '应用并刷新' }}
      </el-button>
    </template>
  </el-dialog>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { uiRegistry } from '@multi-tenant-saas/ui-core/registry'
import type { UIFrameworkName } from '@multi-tenant-saas/ui-core/registry'

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
.framework-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 16px; }
.framework-card { cursor: pointer; transition: all 0.2s; position: relative; }
.framework-card:hover { transform: translateY(-2px); }
.framework-card.active { border-color: var(--el-color-success, #67c23a); }
.framework-card.selected { border-color: var(--el-color-primary, #409eff); box-shadow: 0 0 0 2px var(--el-color-primary, #409eff); }
.framework-card.selected::after { content: '✓'; position: absolute; top: 8px; right: 12px; font-size: 18px; font-weight: 700; color: var(--el-color-primary, #409eff); }
.framework-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px; }
.framework-name { font-size: 15px; font-weight: 600; }
.framework-desc { color: var(--text-color-secondary, #909399); font-size: 12px; margin: 0 0 10px; }
.framework-features { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 10px; }
.framework-footer { display: flex; justify-content: space-between; align-items: center; }
.version { font-size: 11px; color: var(--text-color-secondary, #909399); }
</style>
