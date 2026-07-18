<template>
  <div class="page">
    <div class="page-header">
      <h2>邮件模板管理</h2>
    </div>
    <el-card shadow="never">
      <div class="filter-bar">
        <el-select v-model="filterScope" placeholder="全部层级" clearable style="width: 140px" @change="fetchTemplates">
          <el-option label="全部" value="" />
          <el-option label="系统级" value="system" />
          <el-option label="项目级" value="project" />
          <el-option label="租户级" value="tenant" />
        </el-select>
        <el-select v-model="filterType" placeholder="全部类型" clearable style="width: 160px" @change="fetchTemplates">
          <el-option label="全部" value="" />
          <el-option label="注册" value="registration" />
          <el-option label="验证" value="verification" />
          <el-option label="邀请" value="invitation" />
          <el-option label="申请提交" value="application_submitted" />
          <el-option label="申请通过" value="application_approved" />
          <el-option label="申请拒绝" value="application_rejected" />
          <el-option label="重置密码" value="reset" />
        </el-select>
      </div>

      <el-table :data="templates" stripe style="width: 100%" empty-text="暂无模板">
        <el-table-column prop="type" label="类型" width="180" />
        <el-table-column label="层级" width="100">
          <template #default="{ row }">
            <el-tag :type="scopeType(row.scope)" size="small">{{ scopeLabel(row.scope) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="subject" label="主题" />
        <el-table-column label="语言" width="80">
          <template #default="{ row }">{{ row.locale || '默认' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.status === 'active' ? 'success' : 'info'" size="small">{{ row.status }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="100" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="editTemplate(row)">编辑</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'

const router = useRouter()
const templates = ref<any[]>([])
const filterScope = ref('')
const filterType = ref('')

const scopeType = (s: string) => ({ system: 'info', project: 'warning', tenant: 'success' }[s] || 'info') as any
const scopeLabel = (s: string) => ({ system: '系统级', project: '项目级', tenant: '租户级' }[s] || s)

const fetchTemplates = async () => {
  try {
    const params: any = {}
    if (filterScope.value) params.scope = filterScope.value
    if (filterType.value) params.type = filterType.value
    const res = await axios.get('/api/v1/mail-templates', { params })
    templates.value = res.data.data || []
  } catch { templates.value = [] }
}

const editTemplate = (row: any) => router.push(`/admin/mail-templates/${row.mail_template_id}`)

onMounted(fetchTemplates)
</script>
