<template>
  <div class="page">
    <div class="page-header"><h2>短信配置</h2></div>

    <el-card shadow="never">
      <div class="tenant-select">
        <span style="font-size: 14px; color: #666">选择租户：</span>
        <el-select v-model="selectedTenantId" placeholder="请选择" style="width: 220px" @change="loadConfig">
          <el-option v-for="t in tenants" :key="t.tenant_id" :label="t.name" :value="t.tenant_id" />
        </el-select>
      </div>

      <template v-if="selectedTenantId">
        <el-card shadow="never" style="margin-bottom: 16px">
          <template #header><span style="font-size: 14px; font-weight: 500">短信驱动</span></template>
          <el-form :model="config" label-width="120px">
            <el-form-item label="驱动类型">
              <el-select v-model="config.driver" style="width: 100%">
                <el-option label="Log（日志）" value="log" />
                <el-option label="SMS" value="sms" />
              </el-select>
            </el-form-item>
            <template v-if="config.driver === 'sms'">
              <el-form-item label="API URL">
                <el-input v-model="config.sms.api_url" placeholder="https://api.example.com/sms" />
              </el-form-item>
              <el-form-item label="Access Key">
                <el-input v-model="config.sms.access_key" />
              </el-form-item>
              <el-form-item label="Secret Key">
                <el-input v-model="config.sms.secret_key" type="password" placeholder="******" show-password />
              </el-form-item>
              <el-form-item label="签名">
                <el-input v-model="config.sms.sign_name" />
              </el-form-item>
            </template>
          </el-form>
        </el-card>

        <el-card shadow="never" style="margin-bottom: 16px">
          <template #header><span style="font-size: 14px; font-weight: 500">测试发送</span></template>
          <el-form label-width="120px">
            <el-form-item label="手机号">
              <el-input v-model="testPhone" placeholder="13800138000" style="width: 220px" />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :disabled="!testPhone" @click="handleTest">发送测试短信</el-button>
              <el-alert
                v-if="testResult"
                :title="testResult.msg"
                :type="testResult.ok ? 'success' : 'error'"
                :closable="false"
                show-icon
                style="display: inline-flex; margin-left: 12px"
              />
            </el-form-item>
          </el-form>
        </el-card>

        <el-button type="primary" @click="handleSave">保存配置</el-button>
      </template>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'

const tenants = ref<any[]>([])
const selectedTenantId = ref('')
const testPhone = ref('')
const testResult = ref<{ ok: boolean; msg: string } | null>(null)

const config = reactive({
  driver: 'log',
  sms: { api_url: '', access_key: '', secret_key: '', sign_name: '' },
})

const fetchTenants = async () => {
  try {
    const res = await axios.get('/api/v1/tenants')
    tenants.value = res.data.data || []
  } catch {}
}

const loadConfig = async () => {
  if (!selectedTenantId.value) return
  testResult.value = null
  try {
    const res = await axios.get(`/api/v1/tenants/${selectedTenantId.value}/settings/sms`)
    const data = res.data.data || {}
    if (data.driver) config.driver = data.driver
    if (data.sms) Object.assign(config.sms, data.sms)
  } catch {}
}

const handleSave = async () => {
  try {
    await axios.put(`/api/v1/tenants/${selectedTenantId.value}/settings/sms`, config)
    ElMessage.success('保存成功')
  } catch {
    ElMessage.error('保存失败')
  }
}

const handleTest = async () => {
  testResult.value = null
  try {
    const res = await axios.post(`/api/v1/tenants/${selectedTenantId.value}/settings/sms/test`, { phone: testPhone.value })
    testResult.value = { ok: true, msg: res.data.message || '发送成功' }
  } catch (e: any) {
    testResult.value = { ok: false, msg: e.response?.data?.message || '发送失败' }
  }
}

onMounted(fetchTenants)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.tenant-select { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
</style>
