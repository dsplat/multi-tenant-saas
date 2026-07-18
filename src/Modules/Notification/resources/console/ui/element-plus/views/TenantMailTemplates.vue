<template>
  <div class="page">
    <div class="page-header">
      <h2>邮件模板管理</h2>
      <el-button type="primary" size="small" @click="createOverride">创建租户覆盖</el-button>
    </div>
    <el-card shadow="never">
      <p style="color: #909399; margin-bottom: 16px;">
        管理租户级邮件模板。创建覆盖将基于系统默认模板创建租户定制版本。
      </p>
      <el-table :data="templates" stripe style="width: 100%" empty-text="暂无模板">
        <el-table-column prop="type" label="类型" width="180" />
        <el-table-column label="层级" width="100">
          <template #default="{ row }">
            <el-tag :type="row.scope === 'tenant' ? 'success' : 'info'" size="small">
              {{ row.scope === 'tenant' ? '租户定制' : '系统默认' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="subject" label="主题" />
        <el-table-column label="操作" width="120">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="editTemplate(row)">编辑</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="showEditor" :title="editingTemplate ? '编辑模板' : '创建覆盖'" width="680px">
      <el-form v-if="editingTemplate" :model="editingTemplate" label-width="80px">
        <el-form-item label="主题">
          <el-input v-model="editingTemplate.subject" />
        </el-form-item>
        <el-form-item label="HTML">
          <el-input v-model="editingTemplate.html_body" type="textarea" rows="8" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="showEditor = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="saveTemplate">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'

const templates = ref<any[]>([])
const showEditor = ref(false)
const editingTemplate = ref<any>(null)
const saving = ref(false)

const fetchTemplates = async () => {
  try {
    const res = await axios.get('/api/v1/mail-templates', { params: { scope: 'tenant' } })
    templates.value = res.data.data || []
  } catch { templates.value = [] }
}

const editTemplate = (row: any) => {
  editingTemplate.value = { ...row }
  showEditor.value = true
}

const createOverride = () => {
  editingTemplate.value = {
    type: 'registration', scope: 'tenant', subject: '', html_body: '', text_body: '', locale: null, status: 'active',
  }
  showEditor.value = true
}

const saveTemplate = async () => {
  saving.value = true
  try {
    const t = editingTemplate.value
    if (t.mail_template_id) {
      await axios.put(`/api/v1/mail-templates/${t.mail_template_id}`, {
        subject: t.subject, html_body: t.html_body, text_body: t.text_body, status: t.status,
      })
    } else {
      await axios.post('/api/v1/mail-templates', {
        type: t.type, scope: 'tenant', subject: t.subject, html_body: t.html_body, text_body: t.text_body,
        locale: t.locale, status: t.status || 'active',
      })
    }
    ElMessage.success('保存成功')
    showEditor.value = false
    fetchTemplates()
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '保存失败')
  } finally { saving.value = false }
}

onMounted(fetchTemplates)
</script>
