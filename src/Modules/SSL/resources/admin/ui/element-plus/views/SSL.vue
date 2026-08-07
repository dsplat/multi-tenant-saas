<template>
  <div class="page">
    <div class="page-header"><h2>SSL 证书管理</h2></div>

    <el-card shadow="never">
      <el-empty v-if="!tenantStore.hasTenant" description="请先在页面右上角选择团队" />

      <template v-else>
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

      <div class="actions-bar">
        <el-button type="primary" @click="showUpload = true">上传证书</el-button>
        <el-button v-if="certInfo?.has_certificate" type="danger" @click="handleDelete">删除证书</el-button>
      </div>
      </template>
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
import { ref, onMounted, watch } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'
// 租户上下文统一走头部团队选择器（tenantStore），页面内不再手输租户 ID
import { useTenantStore } from '@/admin/stores/tenant'

const API = '/api/v1/admin/ssl'
const tenantStore = useTenantStore()
const certInfo = ref<any>(null)
const showUpload = ref(false)
const uploadForm = ref({ certificate: '', private_key: '' })

const fetchCert = async () => {
  if (!tenantStore.hasTenant) return
  try {
    const r = await axios.get(API, { params: { tenant_id: tenantStore.tenantId } })
    certInfo.value = r.data.data || r.data
  } catch {
    certInfo.value = null
  }
}

const handleUpload = async () => {
  try {
    await axios.post(`${API}/${tenantStore.tenantId}`, uploadForm.value)
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
    await ElMessageBox.confirm('确定删除该团队的 SSL 证书？', '警告', { type: 'warning' })
    await axios.delete(`${API}/${tenantStore.tenantId}`)
    await fetchCert()
    ElMessage.success('已删除')
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '删除失败')
  }
}

onMounted(() => { if (tenantStore.hasTenant) fetchCert() })
watch(() => tenantStore.tenantId, () => { if (tenantStore.hasTenant) fetchCert(); else certInfo.value = null })
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.actions-bar { display: flex; gap: 8px; }
</style>
