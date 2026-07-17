<template>
  <div class="page">
    <div class="page-header"><h2>短信配置</h2></div>

    <el-card shadow="never" style="max-width: 600px">
      <el-form :model="config" label-width="100px" @submit.prevent="handleSave">
        <el-form-item label="短信驱动">
          <el-select v-model="config.driver" style="width: 100%">
            <el-option label="仅日志（测试用）" value="log" />
            <el-option label="SMS" value="sms" />
          </el-select>
        </el-form-item>

        <template v-if="config.driver === 'sms'">
          <el-form-item label="网关地址"><el-input v-model="config.sms_endpoint" placeholder="https://sms.example.com/api/send" /></el-form-item>
          <el-form-item label="Access Key"><el-input v-model="config.sms_access_key" /></el-form-item>
          <el-form-item label="Secret Key"><el-input v-model="config.sms_secret_key" type="password" show-password placeholder="******" /></el-form-item>
          <el-form-item label="签名"><el-input v-model="config.sms_sign" placeholder="签名" /></el-form-item>
        </template>

        <el-form-item>
          <el-button type="primary" :loading="saving" @click="handleSave">保存配置</el-button>
        </el-form-item>
      </el-form>

      <el-divider v-if="config.driver !== 'log'" content-position="left">测试发送</el-divider>
      <div v-if="config.driver !== 'log'" class="test-area">
        <el-input v-model="testPhone" placeholder="测试手机号" style="width: 200px" />
        <el-button :loading="testing" @click="handleTest">测试发送</el-button>
      </div>
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
const testing = ref(false)
const testPhone = ref('')

const config = reactive({
  driver: 'log',
  sms_endpoint: '',
  sms_access_key: '',
  sms_secret_key: '',
  sms_sign: '',
})

const loadConfig = async () => {
  try {
    const res = await axios.get(`/api/v1/tenants/${userStore.tenantId}/settings/sms`)
    if (res.data.data) Object.assign(config, res.data.data)
  } catch {}
}

const handleSave = async () => {
  saving.value = true
  try {
    await axios.put(`/api/v1/tenants/${userStore.tenantId}/settings/sms`, config)
    ElMessage.success('保存成功')
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

const handleTest = async () => {
  if (!testPhone.value) {
    ElMessage.warning('请输入测试手机号')
    return
  }
  testing.value = true
  try {
    await axios.post(`/api/v1/tenants/${userStore.tenantId}/settings/sms/test`, { phone: testPhone.value })
    ElMessage.success('测试短信已发送')
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '发送失败')
  } finally {
    testing.value = false
  }
}

onMounted(loadConfig)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.test-area { display: flex; gap: 12px; align-items: center; }
</style>
