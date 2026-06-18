<template>
  <div class="settings">
    <el-card>
      <template #header>
        <span>系统设置</span>
      </template>
      
      <el-form :model="settings" label-width="120px">
        <el-form-item label="系统名称">
          <el-input v-model="settings.app_name" />
        </el-form-item>
        <el-form-item label="系统URL">
          <el-input v-model="settings.app_url" />
        </el-form-item>
        <el-form-item label="管理员邮箱">
          <el-input v-model="settings.admin_email" />
        </el-form-item>
        <el-form-item label="默认套餐">
          <el-select v-model="settings.default_plan">
            <el-option label="免费版" value="free" />
            <el-option label="专业版" value="pro" />
          </el-select>
        </el-form-item>
        <el-form-item label="默认积分">
          <el-input-number v-model="settings.default_credits" :min="0" />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSave">保存设置</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import axios from 'axios'

const settings = ref({
  app_name: 'Multi-Tenant SaaS',
  app_url: 'http://ai.lyt.com',
  admin_email: 'admin@example.com',
  default_plan: 'free',
  default_credits: 500,
})

const fetchSettings = async () => {
  try {
    // 这里调用实际 API
    // const response = await axios.get('/api/v1/admin/settings')
    // settings.value = response.data.data
  } catch (error) {
    console.error('获取系统设置失败:', error)
  }
}

const handleSave = async () => {
  try {
    // 这里调用实际 API
    // await axios.put('/api/v1/admin/settings', settings.value)
    ElMessage.success('保存成功')
  } catch (error) {
    console.error('保存系统设置失败:', error)
    ElMessage.error('保存失败')
  }
}

onMounted(() => {
  fetchSettings()
})
</script>
