<template>
  <div class="page">
    <div class="page-header"><h2>SSL 证书管理</h2></div>

    <el-card shadow="never">
      <div class="filter-bar">
        <span style="font-size: 14px; color: #666">租户 ID：</span>
        <el-input v-model="tenantId" placeholder="输入租户ID" style="width: 220px" @keyup.enter="fetchCert" />
        <el-button type="primary" @click="fetchCert">查询</el-button>
      </div>

      <el-descriptions v-if="certInfo" :column="1" border style="margin-bottom: 20px">
        <el-descriptions-item label="证书状态">
          <el-tag v-if="!certInfo.has_certificate" type="info" size="small">未上传</el-tag>
          <el-tag v-else-if="certInfo.is_expired" type="danger" size="small">已过期</el-tag>
          <el-tag v-else type="success" size="small">有效</el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="上传时间">{{ certInfo.uploaded_at || '-' }}</el-descriptions-item>
        <el-descriptions-item label="过期时间">{{ certInfo.expires_at || '-' }}</el-descriptions-item>
        <el-descriptions-item v-if="certInfo.expires_soon" label="警告">
          <el-alert title="证书即将过期（30天内）" type="warning" :closable="false" show-icon />
        </el-descriptions-item>
      </el-descriptions>

      <div v-if="tenantId" class="actions-bar">
        <el-button type="primary" @click="showUpload = true">上传证书</el-button>
        <el-button v-if="certInfo?.has_certificate" type="danger" @click="handleDelete">删除证书</el-button>
      </div>
    </el-card>

    <el-dialog v-model="showUpload" title="上传 SSL 证书" width="560px">
      <el-form :model="uploadForm" label-width="120px">
        <el-form-item label="证书内容（PEM）">
          <el-input v-model="uploadForm.certificate" type="textarea" :rows="6" placeholder="-----BEGIN CERTIFICATE-----" />
        </el-form-item>
        <el-form-item label="私钥（PEM）">
          <el-input v-model="uploadForm.private_key" type="textarea" :rows="6" placeholder="-----BEGIN PRIVATE KEY-----" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="showUpload = false">取消</el-button>
        <el-button type="primary" @click="handleUpload">上传</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'

const API = '/api/v1/admin/ssl'
const tenantId = ref('')
const certInfo = ref<any>(null)
const showUpload = ref(false)
const uploadForm = ref({ certificate: '', private_key: '' })

const fetchCert = async () => {
  if (!tenantId.value) return
  try {
    const r = await axios.get(API, { params: { tenant_id: tenantId.value } })
    certInfo.value = r.data.data || r.data
  } catch {
    certInfo.value = null
  }
}

const handleUpload = async () => {
  try {
    await axios.post(`${API}/${tenantId.value}`, uploadForm.value)
    showUpload.value = false
    uploadForm.value = { certificate: '', private_key: '' }
    await fetchCert()
    ElMessage.success('上传成功')
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '上传失败')
  }
}

const handleDelete = async () => {
  try {
    await ElMessageBox.confirm('确定删除该租户的 SSL 证书？', '警告', { type: 'warning' })
    await axios.delete(`${API}/${tenantId.value}`)
    await fetchCert()
    ElMessage.success('已删除')
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '删除失败')
  }
}
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.filter-bar { display: flex; align-items: center; gap: 12px; margin-bottom: 20px; }
.actions-bar { display: flex; gap: 8px; }
</style>
