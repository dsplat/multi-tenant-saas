<template>
  <div class="page">
    <div class="page-header"><h2>SSL 证书</h2></div>

    <el-card shadow="never">
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
      <el-empty v-else description="加载中..." :image-size="60" />

      <div class="actions-bar">
        <el-button type="primary" @click="showUpload = true">{{ certInfo?.has_certificate ? '更换证书' : '上传证书' }}</el-button>
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
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'
import { useUserStore } from '@stores/user'

const userStore = useUserStore()
const apiBase = computed(() => `/api/v1/tenants/${userStore.tenantId}/ssl`)
const certInfo = ref<any>(null)
const showUpload = ref(false)
const uploadForm = ref({ certificate: '', private_key: '' })

const fetchCert = async () => {
  try {
    const r = await axios.get(apiBase.value)
    certInfo.value = r.data.data || r.data
  } catch {
    certInfo.value = null
  }
}

const handleUpload = async () => {
  try {
    await axios.post(apiBase.value, uploadForm.value)
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
    await ElMessageBox.confirm('确定删除 SSL 证书？', '警告', { type: 'warning' })
    await axios.delete(apiBase.value)
    await fetchCert()
    ElMessage.success('已删除')
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '删除失败')
  }
}

onMounted(fetchCert)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.actions-bar { display: flex; gap: 8px; }
</style>
