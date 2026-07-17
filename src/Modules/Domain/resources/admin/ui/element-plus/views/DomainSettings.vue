<template>
  <div class="page">
    <div class="page-header"><h2>域名管理</h2></div>

    <el-card shadow="never" style="margin-bottom: 16px">
      <template #header><span style="font-size: 15px; font-weight: 500">域名列表</span></template>
      <el-table :data="tenants" stripe style="width: 100%" empty-text="暂无数据">
        <el-table-column prop="tenant_id" label="租户ID" width="100" />
        <el-table-column prop="name" label="租户名称" width="120" />
        <el-table-column label="自定义域名">
          <template #default="{ row }">{{ row.custom_domain || '-' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="domainStatusType(row.domain_status)" size="small">{{ domainStatusLabel(row.domain_status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="备案" width="90">
          <template #default="{ row }">
            <el-tag :type="row.icp_verified ? 'success' : 'info'" size="small">{{ row.icp_verified ? '已备案' : '未验证' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="SSL" width="90">
          <template #default="{ row }">
            <el-tag :type="row.has_ssl ? 'success' : 'info'" size="small">{{ row.has_ssl ? '已配置' : '未配置' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="120">
          <template #default="{ row }">
            <el-button v-if="row.domain_status === 'pending'" link type="primary" size="small" @click="handleApprove(row)">审核</el-button>
            <el-button link type="primary" size="small" @click="handleViewSsl(row)">SSL</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 审核对话框 -->
    <el-dialog v-model="showApprove" :title="`域名审核 - ${currentTenant?.name ?? ''}`" width="440px">
      <el-descriptions :column="1" border>
        <el-descriptions-item label="域名">{{ currentTenant?.custom_domain }}</el-descriptions-item>
      </el-descriptions>
      <template #footer>
        <el-button type="danger" @click="handleReject">拒绝</el-button>
        <el-button type="primary" @click="handleApproveConfirm">通过</el-button>
      </template>
    </el-dialog>

    <!-- SSL 对话框 -->
    <el-dialog v-model="showSsl" :title="`SSL 证书 - ${currentTenant?.custom_domain ?? ''}`" width="540px">
      <el-descriptions :column="1" border style="margin-bottom: 16px">
        <el-descriptions-item label="证书状态">{{ sslInfo.has_certificate ? '已配置' : '未配置' }}</el-descriptions-item>
        <el-descriptions-item v-if="sslInfo.expires_at" label="过期时间">{{ sslInfo.expires_at }}</el-descriptions-item>
        <el-descriptions-item v-if="sslInfo.is_expired" label="状态">
          <el-tag type="danger" size="small">已过期</el-tag>
        </el-descriptions-item>
      </el-descriptions>
      <el-form :model="sslForm" label-width="100px">
        <el-form-item label="证书 (PEM)">
          <el-input v-model="sslForm.certificate" type="textarea" :rows="4" placeholder="-----BEGIN CERTIFICATE-----" />
        </el-form-item>
        <el-form-item label="私钥 (PEM)">
          <el-input v-model="sslForm.private_key" type="textarea" :rows="4" placeholder="-----BEGIN PRIVATE KEY-----" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button v-if="sslInfo.has_certificate" type="danger" @click="handleDeleteSsl">删除证书</el-button>
        <el-button type="primary" @click="handleUploadSsl">上传证书</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'

const tenants = ref<any[]>([])
const showApprove = ref(false)
const showSsl = ref(false)
const currentTenant = ref<any>(null)
const sslInfo = ref<any>({})
const sslForm = reactive({ certificate: '', private_key: '' })

const domainStatusType = (s: string) => ({ approved: 'success', pending: 'warning', rejected: 'danger' }[s] || 'info')
const domainStatusLabel = (s: string) => ({ approved: '已通过', pending: '待审核', rejected: '已拒绝' }[s] || s)

const fetchTenants = async () => {
  try {
    const res = await axios.get('/api/v1/tenants')
    const list = res.data.data || []
    for (const t of list) {
      try {
        const domainRes = await axios.get(`/api/v1/tenants/${t.tenant_id}/domain`)
        Object.assign(t, domainRes.data.data)
      } catch {
        t.domain_status = 'pending'
        t.icp_verified = false
      }
      try {
        const sslRes = await axios.get(`/api/v1/tenants/${t.tenant_id}/ssl`)
        t.has_ssl = sslRes.data.data?.has_certificate || false
      } catch {
        t.has_ssl = false
      }
    }
    tenants.value = list
  } catch {
    tenants.value = []
  }
}

const handleApprove = (t: any) => {
  currentTenant.value = t
  showApprove.value = true
}

const handleApproveConfirm = async () => {
  await axios.post(`/api/v1/tenants/${currentTenant.value.tenant_id}/domain/approve`)
  showApprove.value = false
  fetchTenants()
}

const handleReject = async () => {
  await axios.post(`/api/v1/tenants/${currentTenant.value.tenant_id}/domain/reject`)
  showApprove.value = false
  fetchTenants()
}

const handleViewSsl = async (t: any) => {
  currentTenant.value = t
  try {
    const res = await axios.get(`/api/v1/tenants/${t.tenant_id}/ssl`)
    sslInfo.value = res.data.data || {}
  } catch {
    sslInfo.value = {}
  }
  sslForm.certificate = ''
  sslForm.private_key = ''
  showSsl.value = true
}

const handleUploadSsl = async () => {
  await axios.post(`/api/v1/tenants/${currentTenant.value.tenant_id}/ssl`, sslForm)
  showSsl.value = false
  fetchTenants()
}

const handleDeleteSsl = async () => {
  await axios.delete(`/api/v1/tenants/${currentTenant.value.tenant_id}/ssl`)
  showSsl.value = false
  fetchTenants()
}

onMounted(fetchTenants)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
</style>
