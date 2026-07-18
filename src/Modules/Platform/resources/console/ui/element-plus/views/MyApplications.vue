<template>
  <div class="page">
    <div class="page-header">
      <h2>我的申请</h2>
    </div>
    <el-card shadow="never">
      <el-table :data="applications" stripe style="width: 100%" empty-text="暂无申请记录">
        <el-table-column prop="code" label="编号" width="180" />
        <el-table-column prop="org_name" label="组织名称" />
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="提交时间" width="120">
          <template #default="{ row }">{{ row.created_at?.substring(0, 10) }}</template>
        </el-table-column>
        <el-table-column label="审批备注">
          <template #default="{ row }">{{ row.review_notes || '-' }}</template>
        </el-table-column>
      </el-table>

      <el-pagination v-if="totalPages > 1" v-model:current-page="currentPage" :page-size="perPage"
        :total="total" layout="prev, pager, next" @current-change="fetchApplications" />
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const applications = ref<any[]>([])
const currentPage = ref(1)
const totalPages = ref(1)
const total = ref(0)
const perPage = 10

const statusType = (s: string) => ({ submitted: 'warning', under_review: 'info', approved: 'success', rejected: 'danger' }[s] || 'info') as any
const statusLabel = (s: string) => ({ submitted: '已提交', under_review: '审核中', approved: '已通过', rejected: '已拒绝' }[s] || s)

const fetchApplications = async (page = 1) => {
  try {
    const res = await axios.get('/api/v1/operator/applications', { params: { page, per_page: perPage } })
    applications.value = res.data.data?.items || []
    total.value = res.data.data?.total || 0
    totalPages.value = res.data.data?.last_page || 1
    currentPage.value = page
  } catch { applications.value = [] }
}

onMounted(() => fetchApplications())
</script>
