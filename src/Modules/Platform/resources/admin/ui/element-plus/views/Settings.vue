<template>
  <div class="page">
    <div class="page-header"><h2>系统设置</h2></div>

    <el-card shadow="never" style="max-width: 640px">
      <el-tabs v-model="activeTab">
        <!-- 系统配置 -->
        <el-tab-pane label="系统配置" name="system">
          <el-form :model="system" label-width="120px" style="margin-top: 12px">
            <el-form-item label="系统名称"><el-input v-model="system.app_name" /></el-form-item>
            <el-form-item label="系统 URL"><el-input v-model="system.app_url" /></el-form-item>
            <el-form-item label="管理员邮箱"><el-input v-model="system.admin_email" type="email" /></el-form-item>
            <el-form-item label="默认套餐">
              <el-select v-model="system.default_plan" style="width: 100%">
                <el-option label="免费版" value="free" />
                <el-option label="专业版" value="pro" />
                <el-option label="企业版" value="enterprise" />
              </el-select>
            </el-form-item>
            <el-form-item label="默认积分"><el-input-number v-model="system.default_credits" :min="0" style="width: 100%" /></el-form-item>
            <el-form-item label="平台租户 ID"><el-input v-model="system.platform_tenant_id" /></el-form-item>
            <el-form-item><el-button type="primary" :loading="saving" @click="handleSave('system')">保存</el-button></el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- 邮件配置 -->
        <el-tab-pane label="邮件配置" name="mail">
          <el-form :model="mail" label-width="120px" style="margin-top: 12px">
            <el-form-item label="邮件驱动">
              <el-select v-model="mail.driver" style="width: 100%">
                <el-option label="SMTP" value="smtp" />
                <el-option label="仅日志" value="log" />
              </el-select>
            </el-form-item>
            <el-form-item label="SMTP 主机"><el-input v-model="mail.host" placeholder="smtp.example.com" /></el-form-item>
            <el-row :gutter="16">
              <el-col :span="12">
                <el-form-item label="端口"><el-input-number v-model="mail.port" :min="1" :max="65535" style="width: 100%" /></el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="加密方式">
                  <el-select v-model="mail.encryption" style="width: 100%">
                    <el-option label="TLS" value="tls" />
                    <el-option label="SSL" value="ssl" />
                  </el-select>
                </el-form-item>
              </el-col>
            </el-row>
            <el-row :gutter="16">
              <el-col :span="12">
                <el-form-item label="用户名"><el-input v-model="mail.username" /></el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="密码"><el-input v-model="mail.password" type="password" placeholder="******" show-password /></el-form-item>
              </el-col>
            </el-row>
            <el-row :gutter="16">
              <el-col :span="12">
                <el-form-item label="发件人邮箱"><el-input v-model="mail.from_address" type="email" /></el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="发件人名称"><el-input v-model="mail.from_name" /></el-form-item>
              </el-col>
            </el-row>
            <el-form-item><el-button type="primary" :loading="saving" @click="handleSave('mail')">保存</el-button></el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- 积分配置 -->
        <el-tab-pane label="积分配置" name="credit">
          <el-form :model="credit" label-width="140px" style="margin-top: 12px">
            <el-form-item label="新用户欢迎积分">
              <el-input-number v-model="credit.welcome_credits" :min="0" style="width: 100%" />
              <div class="form-hint">用户首次注册赠送的积分数量，0 表示关闭</div>
            </el-form-item>
            <el-form-item label="迁移用户积分">
              <el-input-number v-model="credit.migration_credits" :min="0" style="width: 100%" />
              <div class="form-hint">迁移用户绑定手机号时额外赠送，0 表示关闭</div>
            </el-form-item>
            <el-form-item label="积分过期天数">
              <el-input-number v-model="credit.expire_days" :min="0" style="width: 100%" />
              <div class="form-hint">积分有效期（天），0 表示永不过期</div>
            </el-form-item>
            <el-form-item><el-button type="primary" :loading="saving" @click="handleSave('credit')">保存</el-button></el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- Dify 配置 -->
        <el-tab-pane label="Dify 配置" name="dify">
          <el-form :model="dify" label-width="140px" style="margin-top: 12px">
            <el-form-item label="Dify API 地址"><el-input v-model="dify.api_url" placeholder="https://api.dify.ai/v1" /></el-form-item>
            <el-form-item label="API Key"><el-input v-model="dify.api_key" type="password" placeholder="app-xxxx" show-password /></el-form-item>
            <el-form-item label="默认工作流 ID"><el-input v-model="dify.default_workflow_id" /></el-form-item>
            <el-form-item label="超时时间（秒）"><el-input-number v-model="dify.timeout" :min="1" style="width: 100%" /></el-form-item>
            <el-form-item><el-button type="primary" :loading="saving" @click="handleSave('dify')">保存</el-button></el-form-item>
          </el-form>
        </el-tab-pane>
      </el-tabs>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'

const activeTab = ref('system')
const saving = ref(false)

const system = reactive({
  app_name: '',
  app_url: '',
  admin_email: '',
  default_plan: 'free',
  default_credits: 500,
  platform_tenant_id: '',
})

const mail = reactive({
  driver: 'smtp',
  host: '',
  port: 587,
  encryption: 'tls',
  username: '',
  password: '',
  from_address: '',
  from_name: '',
})

const credit = reactive({
  welcome_credits: 500,
  migration_credits: 0,
  expire_days: 0,
})

const dify = reactive({
  api_url: '',
  api_key: '',
  default_workflow_id: '',
  timeout: 30,
})

const loadSettings = async () => {
  try {
    const res = await axios.get('/api/v1/admin/settings')
    const data = res.data.data || {}
    if (data.system) Object.assign(system, data.system)
    if (data.mail) Object.assign(mail, data.mail)
    if (data.credit) Object.assign(credit, data.credit)
    if (data.dify) Object.assign(dify, data.dify)
  } catch {}
}

const handleSave = async (group: string) => {
  saving.value = true
  try {
    const data = group === 'system' ? system
      : group === 'mail' ? mail
      : group === 'credit' ? credit
      : dify
    await axios.put(`/api/v1/admin/settings/${group}`, data)
    ElMessage.success('保存成功')
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

onMounted(loadSettings)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.form-hint { font-size: 12px; color: #999; margin-top: 4px; }
</style>
