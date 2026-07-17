<template>
  <div class="page">
    <div class="page-header"><h2>品牌配置</h2></div>

    <el-card shadow="never" style="max-width: 640px">
      <el-form :model="form" label-width="120px">
        <el-form-item label="Logo URL"><el-input v-model="form.logo_url" placeholder="https://..." /></el-form-item>
        <el-form-item label="Favicon URL"><el-input v-model="form.favicon_url" placeholder="https://..." /></el-form-item>
        <el-form-item label="主色调">
          <div style="display: flex; gap: 8px; align-items: center">
            <el-color-picker v-model="form.primary_color" />
            <el-input v-model="form.primary_color" style="flex: 1" />
          </div>
        </el-form-item>
        <el-form-item label="辅助色">
          <div style="display: flex; gap: 8px; align-items: center">
            <el-color-picker v-model="form.secondary_color" />
            <el-input v-model="form.secondary_color" style="flex: 1" />
          </div>
        </el-form-item>
        <el-form-item label="登录页样式">
          <el-select v-model="form.login_page_style" style="width: 100%">
            <el-option label="默认" value="default" />
            <el-option label="紧凑" value="compact" />
            <el-option label="插画" value="illustration" />
          </el-select>
        </el-form-item>
        <el-form-item label="邮件模板">
          <el-select v-model="form.email_template" style="width: 100%">
            <el-option label="默认" value="default" />
            <el-option label="极简" value="minimal" />
          </el-select>
        </el-form-item>
        <el-form-item label="自定义 CSS">
          <el-input v-model="form.custom_css" type="textarea" :rows="4" placeholder="/* custom styles */" />
        </el-form-item>
        <el-form-item label="允许自定义域名">
          <el-switch v-model="form.custom_domain_enabled" />
        </el-form-item>
        <el-form-item>
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

const API = '/api/v1/admin/branding'
const saving = ref(false)
const form = ref({ logo_url: '', favicon_url: '', primary_color: '#1890ff', secondary_color: '#666666', login_page_style: 'default', email_template: 'default', custom_css: '', custom_domain_enabled: true })

const fetch = async () => { try { const r = await axios.get(API); if (r.data.data) Object.assign(form.value, r.data.data) } catch {} }
const handleSave = async () => { saving.value = true; try { await axios.put(API, form.value); ElMessage.success('保存成功') } catch (e: any) { ElMessage.error(e.response?.data?.message || '保存失败') } finally { saving.value = false } }

onMounted(fetch)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
</style>
