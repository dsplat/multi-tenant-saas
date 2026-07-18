<template>
  <div class="page">
    <div class="page-header">
      <h2>租户申请</h2>
    </div>
    <el-card shadow="never">
      <div class="filter-bar">
        <el-input v-model="filters.search" placeholder="搜索组织名或编号..." clearable
          style="width: 240px" @keyup.enter="fetchApplications" />
        <el-select v-model="filters.status" placeholder="全部状态" clearable
          style="width: 140px" @change="fetchApplications">
          <el-option label="全部状态" value="" />
          <el-option label="已提交" value="submitted" />
          <el-option label="审核中" value="under_review" />
          <el-option label="已通过" value="approved" />
          <el-option label="已拒绝" value="rejected" />
        </el-select>
        <el-button type="primary" @click="fetchApplications">查询</el-button>
      </div>

      <el-table :data="applications" stripe style="width: 100%" empty-text="暂无申请">
        <el-table-column prop="code" label="编号" width="180" />
        <el-table-column prop="org_name" label="组织名称" />
        <el-table-column prop="org_industry" label="行业" width="100" />
        <el-table-column prop="org_size" label="规模" width="80" />
        <el-table-column label="申请人" width="120">
          <template #default="{ row }">{{ row.operator?.name || '-' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="提交时间" width="120">
          <template #default="{ row }">{{ formatDate(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="150" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="goDetail(row)">详情</el-button>
            <el-button v-if="canReview(row)" link type="success" size="small" @click="goDetail(row)">审批</el-button>
          </template>
        </el-table-column>
      </el-table>

      <el-pagination v-if="totalPages > 1" v-model:current-page="currentPage" :page-size="perPage"
        :total="total" layout="prev, pager, next" @current-change="fetchApplications" />
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'

const router = useRouter()
const applications = ref<any[]>([])
const currentPage = ref(1)
const totalPages = ref(1)
const total = ref(0)
const perPage = 15
const filters = reactive({ search: '', status: '' })

const statusType = (s: string) => ({ submitted: 'warning', under_review: 'info', approved: 'success', rejected: 'danger' }[s] || 'info') as any
const statusLabel = (s: string) => ({ submitted: '已提交', under_review: '审核中', approved: '已通过', rejected: '已拒绝' }[s] || s)
const formatDate = (d: string) => d ? d.substring(0, 10) : '-'
const canReview = (row: any) => ['submitted', 'under_review'].includes(row.status)

const fetchApplications = async (page = 1) => {
  try {
    const res = await axios.get('/v1/admin/applications', { params: { ...filters, page, per_page: perPage } })
    applications.value = res.data.data?.items || []
    total.value = res.data.data?.total || 0
    totalPages.value = res.data.data?.last_page || 1
    currentPage.value = page
  } catch { applications.value = [] }
}

const goDetail = (row: any) => router.push(`/admin/tenant-applications/${row.application_id}`)

onMounted(() => fetchApplications())
</script>
