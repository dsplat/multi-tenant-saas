<template>
  <div class="ui-framework-selector">
    <el-dialog
      v-model="visible"
      title="选择 UI 框架"
      width="800px"
      :close-on-click-modal="false"
    >
      <div class="framework-grid">
        <div
          v-for="framework in frameworks"
          :key="framework.name"
          class="framework-card"
          :class="{ active: activeFramework === framework.name }"
          @click="handleSelect(framework.name)"
        >
          <div class="framework-header">
            <h3>{{ framework.label }}</h3>
            <el-tag v-if="activeFramework === framework.name" type="success" size="small">
              当前使用
            </el-tag>
          </div>
          
          <p class="framework-desc">{{ framework.description }}</p>
          
          <div class="framework-features">
            <el-tag
              v-for="feature in framework.features.slice(0, 3)"
              :key="feature"
              size="small"
              type="info"
            >
              {{ feature }}
            </el-tag>
          </div>
          
          <div class="framework-footer">
            <span class="version">v{{ framework.version }}</span>
            <a :href="framework.website" target="_blank" class="link">
              官网
            </a>
          </div>
        </div>
      </div>
      
      <template #footer>
        <el-button @click="visible = false">取消</el-button>
        <el-button type="primary" @click="handleApply" :loading="applying">
          应用并刷新
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { ElMessage } from 'element-plus'
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
  if (!selectedFramework.value) {
    ElMessage.warning('请先选择一个 UI 框架')
    return
  }
  
  applying.value = true
  
  try {
    // 保存到本地存储
    localStorage.setItem('multi-tenant-saas-ui-framework', selectedFramework.value)
    
    // 设置活跃框架
    uiRegistry.setActive(selectedFramework.value)
    
    ElMessage.success('UI 框架已切换，页面将刷新')
    
    // 刷新页面
    setTimeout(() => {
      window.location.reload()
    }, 1000)
  } catch (error) {
    ElMessage.error('切换失败')
    console.error(error)
  } finally {
    applying.value = false
  }
}
</script>

<style scoped>
.framework-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 16px;
}

.framework-card {
  border: 2px solid var(--border-color-lighter);
  border-radius: 8px;
  padding: 16px;
  cursor: pointer;
  transition: all 0.3s;
}

.framework-card:hover {
  border-color: var(--primary-color);
  box-shadow: 0 2px 12px rgba(0, 0, 0, 0.1);
}

.framework-card.active {
  border-color: var(--primary-color);
  background-color: var(--primary-color-light-9);
}

.framework-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
}

.framework-header h3 {
  margin: 0;
  font-size: 16px;
}

.framework-desc {
  color: var(--text-color-secondary);
  font-size: 13px;
  margin: 0 0 12px;
  line-height: 1.5;
}

.framework-features {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  margin-bottom: 12px;
}

.framework-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.version {
  color: var(--text-color-secondary);
  font-size: 12px;
}

.link {
  color: var(--primary-color);
  text-decoration: none;
  font-size: 13px;
}

.link:hover {
  text-decoration: underline;
}
</style>
