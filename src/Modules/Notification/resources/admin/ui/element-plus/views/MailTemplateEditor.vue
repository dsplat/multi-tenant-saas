<template>
  <div class="page">
    <div class="page-header">
      <h2>编辑邮件模板</h2>
      <div>
        <el-button @click="$router.back()">返回</el-button>
        <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
      </div>
    </div>
    <el-card v-if="template" shadow="never">
      <el-form :model="template" label-width="100px">
        <el-form-item label="类型">
          <el-input :model-value="template.type" disabled />
        </el-form-item>
        <el-form-item label="层级">
          <el-tag>{{ scopeLabel(template.scope) }}</el-tag>
        </el-form-item>
        <el-form-item label="语言">
          <el-input v-model="template.locale" placeholder="默认（留空为默认语言）" />
        </el-form-item>
        <el-form-item label="主题">
          <el-input v-model="template.subject" placeholder="邮件主题" />
        </el-form-item>
        <el-form-item label="HTML 模板">
          <el-input v-model="template.html_body" type="textarea" rows="12" placeholder="支持 {{变量}} 占位符" />
        </el-form-item>
        <el-form-item label="纯文本模板">
          <el-input v-model="template.text_body" type="textarea" rows="6" placeholder="纯文本版本（可选）" />
        </el-form-item>
        <el-form-item label="可用变量">
          <div style="color: #909399; font-size: 12px;">
            <span>常用变量: {{ vars.common }}</span>
            <br v-if="template.type === 'verification'" />
            <span v-if="template.type === 'verification'">验证: {{ vars.verification }}</span>
            <br v-if="template.type?.startsWith('application')" />
            <span v-if="template.type?.startsWith('application')">申请: {{ vars.application }}</span>
          </div>
        </el-form-item>
        <el-form-item label="状态">
          <el-switch v-model="templateActive" active-text="启用" inactive-text="禁用" />
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import { ElMessage } from 'element-plus'

const route = useRoute()
const router = useRouter()
const template = ref<any>(null)
const saving = ref(false)

const scopeLabel = (s: string) => ({ system: '系统级', project: '项目级', tenant: '租户级' }[s] || s)
const vars = {
  common: '{{name}}, {{platform_name}}, {{current_year}}',
  verification: '{{verification_url}}, {{expiry_hours}}',
  application: '{{org_name}}, {{application_code}}, {{status_url}}, {{review_notes}}',
}

const templateActive = computed({
  get: () => template.value?.status === 'active',
  set: (v: boolean) => { template.value.status = v ? 'active' : 'inactive' },
})

onMounted(async () => {
  try {
    const res = await axios.get(`/api/v1/mail-templates/${route.params.id}`)
    template.value = res.data.data
  } catch {}
})

const handleSave = async () => {
  saving.value = true
  try {
    await axios.put(`/api/v1/mail-templates/${route.params.id}`, {
      subject: template.value.subject,
      html_body: template.value.html_body,
      text_body: template.value.text_body,
      locale: template.value.locale || null,
      status: template.value.status,
    })
    ElMessage.success('保存成功')
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '保存失败')
  } finally { saving.value = false }
}
</script>
