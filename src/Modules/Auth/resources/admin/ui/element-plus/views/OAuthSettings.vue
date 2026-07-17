<template>
  <div class="page">
    <div class="page-header"><h2>第三方登录配置</h2></div>

    <el-card shadow="never">
      <div class="tenant-select">
        <span>选择租户：</span>
        <el-select v-model="selectedTenantId" placeholder="请选择" style="width: 240px" @change="loadConfig">
          <el-option v-for="t in tenants" :key="t.tenant_id" :label="t.name" :value="t.tenant_id" />
        </el-select>
      </div>

      <div v-if="selectedTenantId" class="config-section">
        <el-card v-for="item in configItems" :key="item.key" shadow="hover" class="config-card">
          <template #header>
            <div class="config-header">
              <span>{{ item.title }}</span>
              <el-switch v-model="config[item.key].enabled" />
            </div>
          </template>
          <el-form v-if="config[item.key].enabled" label-width="100px">
            <el-form-item v-for="field in item.fields" :key="field.key" :label="field.label">
              <el-input v-model="config[item.key][field.key]" :type="field.type || 'text'" :placeholder="field.placeholder" />
            </el-form-item>
          </el-form>
        </el-card>

        <el-button type="primary" @click="handleSave">保存配置</el-button>
      </div>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'

const tenants = ref<any[]>([])
const selectedTenantId = ref('')
const config = reactive({
  wechat: { enabled: false, corp_id: '', agent_id: '', secret: '' },
  dingtalk: { enabled: false, app_key: '', app_secret: '' },
  feishu: { enabled: false, app_id: '', app_secret: '' },
})

const configItems = [
  { key: 'wechat', title: '微信 / 企业微信', fields: [
    { key: 'corp_id', label: 'Corp ID', placeholder: 'wx1234567890' },
    { key: 'agent_id', label: 'Agent ID', placeholder: '1000001' },
    { key: 'secret', label: 'Secret', type: 'password', placeholder: '******' },
  ]},
  { key: 'dingtalk', title: '钉钉', fields: [
    { key: 'app_key', label: 'App Key' },
    { key: 'app_secret', label: 'App Secret', type: 'password', placeholder: '******' },
  ]},
  { key: 'feishu', title: '飞书', fields: [
    { key: 'app_id', label: 'App ID' },
    { key: 'app_secret', label: 'App Secret', type: 'password', placeholder: '******' },
  ]},
]

const fetchTenants = async () => { try { const res = await axios.get('/api/v1/tenants'); tenants.value = res.data.data || [] } catch {} }

const loadConfig = async () => {
  if (!selectedTenantId.value) return
  try {
    const res = await axios.get(`/api/v1/tenants/${selectedTenantId.value}/settings/oauth`)
    const data = res.data.data || {}
    if (data.wechat) Object.assign(config.wechat, data.wechat)
    if (data.dingtalk) Object.assign(config.dingtalk, data.dingtalk)
    if (data.feishu) Object.assign(config.feishu, data.feishu)
  } catch {}
}

const handleSave = async () => {
  try { await axios.put(`/api/v1/tenants/${selectedTenantId.value}/settings/oauth`, config); ElMessage.success('保存成功') }
  catch { ElMessage.error('保存失败') }
}

onMounted(fetchTenants)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.tenant-select { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
.config-section { display: flex; flex-direction: column; gap: 16px; }
.config-header { display: flex; justify-content: space-between; align-items: center; }
</style>
