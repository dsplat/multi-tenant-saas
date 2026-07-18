<template>
  <div class="page">
    <div class="page-header">
      <h2>申请详情</h2>
      <el-button @click="$router.back()">返回</el-button>
    </div>
    <el-card v-if="app" shadow="never">
      <el-descriptions :column="2" border>
        <el-descriptions-item label="编号">{{ app.code }}</el-descriptions-item>
        <el-descriptions-item label="状态">
          <el-tag :type="statusType(app.status)" size="small">{{ statusLabel(app.status) }}</el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="组织名称">{{ app.org_name }}</el-descriptions-item>
        <el-descriptions-item label="行业">{{ app.org_industry || '-' }}</el-descriptions-item>
        <el-descriptions-item label="规模">{{ app.org_size || '-' }}</el-descriptions-item>
        <el-descriptions-item label="申请人">{{ app.operator?.name || '-' }} ({{ app.operator?.email || '-' }})</el-descriptions-item>
        <el-descriptions-item label="联系信息">{{ JSON.stringify(app.contact_info || {}) }}</el-descriptions-item>
        <el-descriptions-item label="提交时间">{{ app.created_at }}</el-descriptions-item>
      </el-descriptions>

      <div v-if="app.review_notes" style="margin-top: 16px;">
        <h4>审批备注</h4>
        <p>{{ app.review_notes }}</p>
      </div>

      <div v-if="canReview" style="margin-top: 24px;">
        <h4>审批操作</h4>
        <el-input v-model="reviewNotes" type="textarea" rows="3" placeholder="审批备注（拒绝时必填）" style="margin-bottom: 12px;" />
        <div>
          <el-button type="success" :loading="loading" @click="handleApprove">通过</el-button>
          <el-button type="danger" :loading="loading" @click="handleReject">拒绝</el-button>
        </div>
      </div>

      <div v-if="app.reviewer" style="margin-top: 16px; color: #909399;">
        审批人: {{ app.reviewer.name }} | 审批时间: {{ app.reviewed_at }}
      </div>
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
const app = ref<any>(null)
const reviewNotes = ref('')
const loading = ref(false)

const statusType = (s: string) => ({ submitted: 'warning', under_review: 'info', approved: 'success', rejected: 'danger' }[s] || 'info') as any
const statusLabel = (s: string) => ({ submitted: '已提交', under_review: '审核中', approved: '已通过', rejected: '已拒绝' }[s] || s)
const canReview = computed(() => app.value && ['submitted', 'under_review'].includes(app.value.status))

const fetchDetail = async () => {
  try {
    const res = await axios.get(`/v1/admin/applications/${route.params.id}`)
    app.value = res.data.data
  } catch {}
}

const handleApprove = async () => {
  loading.value = true
  try {
    await axios.post(`/v1/admin/applications/${route.params.id}/approve`, { review_notes: reviewNotes.value })
    ElMessage.success('审批通过')
    router.push('/admin/tenant-applications')
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '操作失败')
  } finally { loading.value = false }
}

const handleReject = async () => {
  if (!reviewNotes.value.trim()) { ElMessage.warning('拒绝时请填写审批备注'); return }
  loading.value = true
  try {
    await axios.post(`/v1/admin/applications/${route.params.id}/reject`, { review_notes: reviewNotes.value })
    ElMessage.success('已拒绝')
    router.push('/admin/tenant-applications')
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '操作失败')
  } finally { loading.value = false }
}

onMounted(fetchDetail)
</script>
