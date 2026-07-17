<template>
  <div class="page">
    <div class="page-header"><h2>第三方登录配置</h2></div>

    <el-card shadow="never" style="max-width: 640px">
      <div class="config-section">
        <!-- 微信 / 企业微信 -->
        <el-card shadow="never" class="config-card">
          <template #header>
            <div class="config-header">
              <span style="font-size: 15px; font-weight: 500">微信 / 企业微信</span>
              <el-switch v-model="config.wechat.enabled" />
            </div>
          </template>
          <el-form v-if="config.wechat.enabled" label-width="90px" style="max-width: 500px">
            <el-form-item label="Corp ID"><el-input v-model="config.wechat.corp_id" placeholder="wx1234567890" /></el-form-item>
            <el-form-item label="Agent ID"><el-input v-model="config.wechat.agent_id" placeholder="1000001" /></el-form-item>
            <el-form-item label="Secret"><el-input v-model="config.wechat.secret" type="password" show-password placeholder="******" /></el-form-item>
          </el-form>
        </el-card>

        <!-- 钉钉 -->
        <el-card shadow="never" class="config-card">
          <template #header>
            <div class="config-header">
              <span style="font-size: 15px; font-weight: 500">钉钉</span>
              <el-switch v-model="config.dingtalk.enabled" />
            </div>
          </template>
          <el-form v-if="config.dingtalk.enabled" label-width="90px" style="max-width: 500px">
            <el-form-item label="App Key"><el-input v-model="config.dingtalk.app_key" /></el-form-item>
            <el-form-item label="App Secret"><el-input v-model="config.dingtalk.app_secret" type="password" show-password placeholder="******" /></el-form-item>
          </el-form>
        </el-card>

        <!-- 飞书 -->
        <el-card shadow="never" class="config-card">
          <template #header>
            <div class="config-header">
              <span style="font-size: 15px; font-weight: 500">飞书</span>
              <el-switch v-model="config.feishu.enabled" />
            </div>
          </template>
          <el-form v-if="config.feishu.enabled" label-width="90px" style="max-width: 500px">
            <el-form-item label="App ID"><el-input v-model="config.feishu.app_id" /></el-form-item>
            <el-form-item label="App Secret"><el-input v-model="config.feishu.app_secret" type="password" show-password placeholder="******" /></el-form-item>
          </el-form>
        </el-card>
      </div>

      <el-button type="primary" :loading="saving" style="margin-top: 16px" @click="handleSave">保存配置</el-button>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'
import { useUserStore } from '@stores/user'

const userStore = useUserStore()
const saving = ref(false)
const config = reactive({
  wechat: { enabled: false, corp_id: '', agent_id: '', secret: '' },
  dingtalk: { enabled: false, app_key: '', app_secret: '' },
  feishu: { enabled: false, app_id: '', app_secret: '' },
})

const loadConfig = async () => {
  try {
    const res = await axios.get(`/api/v1/tenants/${userStore.tenantId}/settings/oauth`)
    const data = res.data.data || res.data
    if (data.wechat) Object.assign(config.wechat, data.wechat)
    if (data.dingtalk) Object.assign(config.dingtalk, data.dingtalk)
    if (data.feishu) Object.assign(config.feishu, data.feishu)
  } catch {}
}

const handleSave = async () => {
  saving.value = true
  try {
    await axios.put(`/api/v1/tenants/${userStore.tenantId}/settings/oauth`, config)
    ElMessage.success('保存成功')
  } catch {
    ElMessage.error('保存失败')
  } finally {
    saving.value = false
  }
}

onMounted(loadConfig)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.config-section { display: flex; flex-direction: column; gap: 16px; }
.config-header { display: flex; justify-content: space-between; align-items: center; }
.config-card { border: 1px solid var(--el-border-color); }
</style>
