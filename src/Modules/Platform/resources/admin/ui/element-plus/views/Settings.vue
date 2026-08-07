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
            <el-form-item>
              <el-button type="primary" :loading="saving" @click="handleSave('mail')">保存</el-button>
            </el-form-item>
            <el-form-item label="测试邮件">
              <el-input v-model="testEmail" placeholder="收件人邮箱" style="width: 240px; margin-right: 8px" />
              <el-button :loading="testingMail" :disabled="!testEmail" @click="handleTestMail">发送测试邮件</el-button>
              <div class="form-hint">使用上方已保存的配置发送；未配置时回退 .env MAIL_*</div>
            </el-form-item>
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

        <!-- 存储配置（平台默认 OSS，租户未配置时回退使用） -->
        <el-tab-pane label="存储配置" name="storage">
          <el-form :model="storage" label-width="140px" style="margin-top: 12px">
            <el-form-item label="启用平台默认 OSS">
              <el-switch v-model="storage.enabled" />
              <div class="form-hint">租户未配置自有存储时，回退到此平台默认存储</div>
            </el-form-item>
            <el-form-item label="Endpoint"><el-input v-model="storage.endpoint" placeholder="https://oss-cn-hangzhou.aliyuncs.com" /></el-form-item>
            <el-row :gutter="16">
              <el-col :span="12">
                <el-form-item label="Bucket"><el-input v-model="storage.bucket" /></el-form-item>
              </el-col>
              <el-col :span="12">
                <el-form-item label="Region"><el-input v-model="storage.region" placeholder="cn-hangzhou" /></el-form-item>
              </el-col>
            </el-row>
            <el-form-item label="AccessKey ID"><el-input v-model="storage.access_key_id" /></el-form-item>
            <el-form-item label="AccessKey Secret"><el-input v-model="storage.access_key_secret" type="password" placeholder="********" show-password /></el-form-item>
            <el-form-item label="自定义域名"><el-input v-model="storage.url" placeholder="https://cdn.example.com（可选）" /></el-form-item>
            <el-form-item label="Path-Style 访问">
              <el-switch v-model="storage.use_path_style" />
              <div class="form-hint">MinIO 等自建存储需开启</div>
            </el-form-item>
            <el-form-item><el-button type="primary" :loading="saving" @click="handleSave('storage')">保存</el-button></el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- 外部知识库（平台默认连接，租户未配置时回退使用） -->
        <el-tab-pane label="外部知识库" name="external_kb">
          <el-form :model="externalKb" label-width="140px" style="margin-top: 12px">
            <el-form-item label="启用平台默认连接">
              <el-switch v-model="externalKb.enabled" />
              <div class="form-hint">租户未配置自有知识库时，AI 检索回退到此平台默认连接</div>
            </el-form-item>
            <el-form-item label="服务商">
              <el-select v-model="externalKb.provider_type" style="width: 100%">
                <el-option label="Dify" value="dify" />
                <el-option label="RAGFlow" value="ragflow" />
                <el-option label="FastGPT" value="fastgpt" />
                <el-option label="阿里云百炼" value="bailian" />
              </el-select>
            </el-form-item>
            <el-form-item label="API 地址"><el-input v-model="externalKb.api_url" :placeholder="externalKb.provider_type === 'bailian' ? 'https://bailian.cn-beijing.aliyuncs.com' : 'https://api.dify.ai'" /></el-form-item>
            <template v-if="externalKb.provider_type === 'bailian'">
              <el-form-item label="AccessKey ID"><el-input v-model="externalKb.access_key_id" /></el-form-item>
              <el-form-item label="AccessKey Secret"><el-input v-model="externalKb.api_key" type="password" placeholder="********" show-password /></el-form-item>
              <el-form-item label="业务空间 ID"><el-input v-model="externalKb.workspace_id" placeholder="llm-xxxx" /></el-form-item>
              <el-form-item label="知识库 ID"><el-input v-model="externalKb.index_id" placeholder="CreateIndex 返回的索引 ID" /></el-form-item>
            </template>
            <template v-else>
              <el-form-item label="API Key"><el-input v-model="externalKb.api_key" type="password" placeholder="********" show-password /></el-form-item>
              <el-form-item label="知识库/数据集 ID"><el-input v-model="externalKb.dataset_id" /></el-form-item>
            </template>
            <el-form-item><el-button type="primary" :loading="saving" @click="handleSave('external_kb')">保存</el-button></el-form-item>
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
const testEmail = ref('')
const testingMail = ref(false)

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

const storage = reactive({
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

const externalKb = reactive({
  enabled: false,
  provider_type: 'dify',
  api_url: '',
  api_key: '',
  dataset_id: '',
  access_key_id: '',
  workspace_id: '',
  index_id: '',
})

// 后端返回的是设置记录数组，转为 key => value 映射
const toKv = (items: any): Record<string, any> => {
  if (Array.isArray(items)) {
    return Object.fromEntries(items.map((s: any) => [s.key, s.value]))
  }
  return items || {}
}

const asBool = (v: any) => v === true || v === 'true' || v === '1' || v === 1

const loadSettings = async () => {
  try {
    const res = await axios.get('/api/v1/admin/settings')
    const data = res.data.data || {}
    if (data.system) Object.assign(system, toKv(data.system))
    if (data.mail) Object.assign(mail, toKv(data.mail))
    if (data.credit) Object.assign(credit, toKv(data.credit))
    if (data.dify) Object.assign(dify, toKv(data.dify))
    if (data.storage) {
      Object.assign(storage, toKv(data.storage))
      storage.enabled = asBool(storage.enabled)
      storage.use_path_style = asBool(storage.use_path_style)
    }
    if (data.external_kb) {
      Object.assign(externalKb, toKv(data.external_kb))
      externalKb.enabled = asBool(externalKb.enabled)
    }
  } catch {}
}

const handleSave = async (group: string) => {
  saving.value = true
  try {
    const data = group === 'system' ? system
      : group === 'mail' ? mail
      : group === 'credit' ? credit
      : group === 'storage' ? storage
      : group === 'external_kb' ? externalKb
      : dify
    await axios.put(`/api/v1/admin/settings/${group}`, data)
    ElMessage.success('保存成功')
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

const handleTestMail = async () => {
  testingMail.value = true
  try {
    const res = await axios.post('/api/v1/admin/settings/mail/test', { email: testEmail.value })
    ElMessage.success(res.data?.message || '测试邮件已发送')
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '发送失败')
  } finally {
    testingMail.value = false
  }
}

onMounted(loadSettings)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.form-hint { font-size: 12px; color: #999; margin-top: 4px; }
</style>
