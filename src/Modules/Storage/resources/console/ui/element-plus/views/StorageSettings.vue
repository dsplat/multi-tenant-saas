<template>
  <div class="page">
    <div class="page-header"><h2>存储配置</h2></div>

    <el-card shadow="never" style="max-width: 640px">
      <template #header>
        <div class="config-header">
          <span style="font-size: 15px; font-weight: 500">对象存储 (OSS)</span>
          <el-tag :type="statusTagType" size="small">{{ statusLabel }}</el-tag>
        </div>
      </template>

      <el-alert
        v-if="status.source !== 'tenant'"
        type="info"
        :closable="false"
        show-icon
        style="margin-bottom: 16px"
        title="当前使用平台提供的存储服务"
        description="未配置自有对象存储时，文件将自动存储到平台默认服务。配置并启用后优先使用您自己的存储。"
      />

      <el-form :model="form" label-width="140px">
        <el-form-item label="启用自有存储">
          <el-switch v-model="form.enabled" />
        </el-form-item>
        <el-form-item label="Endpoint"><el-input v-model="form.endpoint" placeholder="https://oss-cn-hangzhou.aliyuncs.com" /></el-form-item>
        <el-row :gutter="16">
          <el-col :span="12">
            <el-form-item label="Bucket"><el-input v-model="form.bucket" /></el-form-item>
          </el-col>
          <el-col :span="12">
            <el-form-item label="Region"><el-input v-model="form.region" placeholder="cn-hangzhou" /></el-form-item>
          </el-col>
        </el-row>
        <el-form-item label="AccessKey ID"><el-input v-model="form.access_key_id" /></el-form-item>
        <el-form-item label="AccessKey Secret"><el-input v-model="form.access_key_secret" type="password" placeholder="********" show-password /></el-form-item>
        <el-form-item label="自定义域名"><el-input v-model="form.url" placeholder="https://cdn.example.com（可选）" /></el-form-item>
        <el-form-item label="Path-Style 访问">
          <el-switch v-model="form.use_path_style" />
          <div class="form-hint">MinIO 等自建存储需开启</div>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'

const saving = ref(false)

const form = reactive({
  enabled: false,
  driver: 's3',
  endpoint: '',
  bucket: '',
  region: '',
  access_key_id: '',
  access_key_secret: '',
  url: '',
  use_path_style: false,
})

const status = reactive({ source: 'system', disk: 'local' })

const statusLabel = computed(() =>
  status.source === 'tenant' ? '使用自有存储'
    : status.source === 'platform' ? '使用平台默认存储'
    : '使用系统本地存储')

const statusTagType = computed(() =>
  status.source === 'tenant' ? 'success' : status.source === 'platform' ? 'warning' : 'info')

const asBool = (v: any) => v === true || v === 'true' || v === '1' || v === 1

const loadConfig = async () => {
  try {
    const res = await axios.get('/api/v1/tenant/storage/config')
    const data = res.data.data || {}
    if (data.config) {
      Object.assign(form, data.config)
      form.enabled = asBool(form.enabled)
      form.use_path_style = asBool(form.use_path_style)
    }
    if (data.status) Object.assign(status, data.status)
  } catch {}
}

const handleSave = async () => {
  saving.value = true
  try {
    const res = await axios.put('/api/v1/tenant/storage/config', form)
    if (res.data.data?.status) Object.assign(status, res.data.data.status)
    ElMessage.success('保存成功')
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

onMounted(loadConfig)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.config-header { display: flex; justify-content: space-between; align-items: center; }
.form-hint { font-size: 12px; color: #999; margin-top: 4px; }
</style>
