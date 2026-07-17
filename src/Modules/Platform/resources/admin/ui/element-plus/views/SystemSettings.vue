<template>
  <div class="page">
    <div class="page-header"><h2>系统设置</h2></div>

    <el-card shadow="never" style="max-width: 640px">
      <el-tabs v-model="activeGroup" @tab-change="fetchSettings">
        <el-tab-pane v-for="g in groups" :key="g" :label="g" :name="g" />
      </el-tabs>

      <el-form label-width="200px" style="margin-top: 16px">
        <el-form-item v-for="s in settings" :key="s.key ?? s.setting_key" :label="s.key ?? s.setting_key">
          <div v-if="s.description" style="font-size: 12px; color: #999; margin-bottom: 4px">{{ s.description }}</div>
          <el-input v-if="!s.is_encrypted" v-model="s.value" />
          <el-input v-else v-model="s.value" type="password" placeholder="••••••" show-password />
        </el-form-item>
        <el-form-item v-if="settings.length === 0">
          <el-empty description="暂无设置项" :image-size="60" />
        </el-form-item>
        <el-form-item v-if="settings.length > 0">
          <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'

const API = '/api/v1/admin/system-settings'
const groups = ref<string[]>(['app', 'mail', 'cache', 'queue', 'session'])
const activeGroup = ref('app')
const settings = ref<any[]>([])
const saving = ref(false)

const fetchSettings = async () => {
  try {
    const r = await axios.get(API, { params: { group: activeGroup.value } })
    settings.value = r.data.data || []
  } catch {
    settings.value = []
  }
}

const handleSave = async () => {
  saving.value = true
  try {
    await axios.put(`${API}/${activeGroup.value}`, {
      settings: settings.value.map(s => ({ key: s.key ?? s.setting_key, value: s.value, is_encrypted: s.is_encrypted }))
    })
    ElMessage.success('保存成功')
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

onMounted(fetchSettings)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
</style>
