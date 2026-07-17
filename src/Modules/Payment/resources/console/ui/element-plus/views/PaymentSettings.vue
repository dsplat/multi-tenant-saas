<template>
  <div class="page">
    <div class="page-header"><h2>支付配置</h2></div>

    <div class="config-section">
      <!-- 微信支付 -->
      <el-card shadow="never" class="config-card">
        <template #header>
          <div class="config-header">
            <span style="font-size: 15px; font-weight: 500">微信支付</span>
            <el-tag :type="config.wechat.configured ? 'success' : 'info'" size="small">
              {{ config.wechat.configured ? '已配置' : '未配置' }}
            </el-tag>
          </div>
        </template>
        <el-form :model="wechatForm" label-width="120px" style="max-width: 560px">
          <el-form-item label="App ID"><el-input v-model="wechatForm.app_id" placeholder="wx1234567890" /></el-form-item>
          <el-form-item label="商户号 (Mch ID)"><el-input v-model="wechatForm.mch_id" placeholder="1234567890" /></el-form-item>
          <el-form-item label="API 证书序列号"><el-input v-model="wechatForm.serial_no" /></el-form-item>
          <el-form-item label="API 私钥"><el-input v-model="wechatForm.private_key" type="textarea" :rows="3" placeholder="-----BEGIN PRIVATE KEY-----" /></el-form-item>
          <el-form-item label="回调地址"><el-input v-model="wechatForm.notify_url" placeholder="https://your-domain.com/api/v1/pay/wechat/notify" /></el-form-item>
          <el-form-item>
            <el-button type="primary" :loading="saving" @click="handleSaveWechat">保存微信配置</el-button>
          </el-form-item>
        </el-form>
      </el-card>

      <!-- 支付宝 -->
      <el-card shadow="never" class="config-card">
        <template #header>
          <div class="config-header">
            <span style="font-size: 15px; font-weight: 500">支付宝</span>
            <el-tag :type="config.alipay.configured ? 'success' : 'info'" size="small">
              {{ config.alipay.configured ? '已配置' : '未配置' }}
            </el-tag>
          </div>
        </template>
        <el-form :model="alipayForm" label-width="120px" style="max-width: 560px">
          <el-form-item label="App ID"><el-input v-model="alipayForm.app_id" placeholder="2021001234567890" /></el-form-item>
          <el-form-item label="支付宝公钥"><el-input v-model="alipayForm.ali_public_key" type="textarea" :rows="3" placeholder="-----BEGIN PUBLIC KEY-----" /></el-form-item>
          <el-form-item label="应用私钥"><el-input v-model="alipayForm.private_key" type="textarea" :rows="3" placeholder="-----BEGIN PRIVATE KEY-----" /></el-form-item>
          <el-form-item label="回调地址"><el-input v-model="alipayForm.notify_url" placeholder="https://your-domain.com/api/v1/pay/alipay/notify" /></el-form-item>
          <el-form-item label="模式">
            <el-select v-model="alipayForm.mode" style="width: 100%">
              <el-option label="正式环境" value="normal" />
              <el-option label="沙箱环境" value="sandbox" />
            </el-select>
          </el-form-item>
          <el-form-item>
            <el-button type="primary" :loading="saving" @click="handleSaveAlipay">保存支付宝配置</el-button>
          </el-form-item>
        </el-form>
      </el-card>
    </div>
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
  wechat: { configured: false },
  alipay: { configured: false },
})

const wechatForm = reactive({
  app_id: '',
  mch_id: '',
  serial_no: '',
  private_key: '',
  notify_url: '',
})

const alipayForm = reactive({
  app_id: '',
  ali_public_key: '',
  private_key: '',
  notify_url: '',
  mode: 'normal',
})

const loadConfig = async () => {
  try {
    const res = await axios.get(`/api/v1/tenants/${userStore.tenantId}/payment/config`)
    const data = res.data.data || {}
    if (data.wechat) {
      config.wechat.configured = data.wechat.configured
      wechatForm.app_id = data.wechat.app_id || ''
      wechatForm.mch_id = data.wechat.mch_id || ''
    }
    if (data.alipay) {
      config.alipay.configured = data.alipay.configured
      alipayForm.app_id = data.alipay.app_id || ''
    }
  } catch {}
}

const handleSaveWechat = async () => {
  saving.value = true
  try {
    await axios.put(`/api/v1/tenants/${userStore.tenantId}/payment/wechat`, wechatForm)
    ElMessage.success('微信支付配置已保存')
    loadConfig()
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

const handleSaveAlipay = async () => {
  saving.value = true
  try {
    await axios.put(`/api/v1/tenants/${userStore.tenantId}/payment/alipay`, alipayForm)
    ElMessage.success('支付宝配置已保存')
    loadConfig()
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
.config-section { display: flex; flex-direction: column; gap: 16px; max-width: 680px; }
.config-header { display: flex; justify-content: space-between; align-items: center; }
.config-card { border: 1px solid var(--el-border-color); }
</style>
