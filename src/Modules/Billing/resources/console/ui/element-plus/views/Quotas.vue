<template>
  <div class="page">
    <div class="page-header"><h2>配额管理</h2></div>

    <!-- 配额概览卡片 -->
    <el-row :gutter="16" style="margin-bottom: 20px">
      <el-col v-for="q in quotas" :key="q.resource" :span="8">
        <el-card shadow="never">
          <div class="quota-card">
            <div class="quota-label">{{ q.label }}</div>
            <div class="quota-value">
              <span class="used">{{ q.used }}</span>
              <span class="separator">/</span>
              <span class="limit">{{ q.limit === -1 ? '无限制' : q.limit }}</span>
              <span v-if="q.unit" class="unit">{{ q.unit }}</span>
            </div>
            <el-progress
              v-if="q.limit !== -1 && q.limit > 0"
              :percentage="progressPercent(q)"
              :status="progressStatus(q)"
              :stroke-width="10"
              style="margin-top: 12px"
            />
            <el-tag v-else size="small" type="success" style="margin-top: 12px">无限制</el-tag>
          </div>
        </el-card>
      </el-col>
    </el-row>

    <!-- 配额详情表格 -->
    <el-card shadow="never">
      <template #header><span style="font-size: 15px; font-weight: 500">配额详情</span></template>
      <el-table :data="quotas" stripe style="width: 100%" empty-text="暂无配额数据">
        <el-table-column prop="label" label="资源类型" />
        <el-table-column label="限制" width="120">
          <template #default="{ row }">{{ row.limit === -1 ? '无限制' : row.limit }}</template>
        </el-table-column>
        <el-table-column label="已用" width="100">
          <template #default="{ row }">{{ row.used }}</template>
        </el-table-column>
        <el-table-column label="剩余" width="100">
          <template #default="{ row }">{{ row.limit === -1 ? '-' : Math.max(0, row.limit - row.used) }}</template>
        </el-table-column>
        <el-table-column label="使用情况" width="220">
          <template #default="{ row }">
            <el-progress
              v-if="row.limit !== -1 && row.limit > 0"
              :percentage="progressPercent(row)"
              :status="progressStatus(row)"
              :stroke-width="8"
            />
            <el-tag v-else size="small">无限制</el-tag>
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const quotas = ref<any[]>([])

const progressPercent = (q: any) => {
  if (!q.limit || q.limit <= 0 || q.limit === -1) return 0
  return Math.min(100, Math.round((q.used / q.limit) * 100))
}

const progressStatus = (q: any) => {
  const pct = progressPercent(q)
  if (pct >= 90) return 'exception'
  if (pct >= 70) return 'warning'
  return 'success'
}

const fetchQuotas = async () => {
  try {
    const res = await axios.get('/api/v1/billing/quotas')
    quotas.value = res.data.data || []
  } catch {
    quotas.value = []
  }
}

onMounted(fetchQuotas)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }

.quota-card { text-align: center; padding: 8px 0; }
.quota-label { font-size: 13px; color: #909399; margin-bottom: 8px; }
.quota-value { font-size: 24px; font-weight: 600; color: #303133; }
.quota-value .used { color: var(--primary-color, #10b981); }
.quota-value .separator { color: #c0c4cc; margin: 0 4px; font-weight: 400; }
.quota-value .limit { color: #606266; }
.quota-value .unit { font-size: 14px; color: #909399; margin-left: 4px; font-weight: 400; }
</style>
