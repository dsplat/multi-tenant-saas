<template>
  <div class="page">
    <div class="page-header"><h2>积分管理</h2></div>

    <el-row :gutter="16" style="margin-bottom: 20px">
      <el-col :span="8">
        <el-card shadow="never">
          <el-statistic title="总积分" :value="balance.total" />
        </el-card>
      </el-col>
      <el-col :span="8">
        <el-card shadow="never">
          <el-statistic title="已使用" :value="balance.used" />
        </el-card>
      </el-col>
      <el-col :span="8">
        <el-card shadow="never">
          <el-statistic title="可用积分" :value="balance.available" />
        </el-card>
      </el-col>
    </el-row>

    <el-card shadow="never">
      <template #header><span style="font-size: 15px; font-weight: 500">交易记录</span></template>
      <el-table :data="transactions" stripe style="width: 100%" empty-text="暂无交易记录">
        <el-table-column label="时间" width="160">
          <template #default="{ row }">{{ formatDate(row.created_at) }}</template>
        </el-table-column>
        <el-table-column label="类型" width="90">
          <template #default="{ row }">
            <el-tag :type="typeTag(row.type ?? row.transaction_type)" size="small">{{ typeLabel(row.type ?? row.transaction_type) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="金额" width="100">
          <template #default="{ row }">
            <span :style="{ color: (row.amount ?? 0) > 0 ? '#67c23a' : (row.amount ?? 0) < 0 ? '#f56c6c' : '' }">
              {{ (row.amount ?? 0) > 0 ? '+' : '' }}{{ row.amount }}
            </span>
          </template>
        </el-table-column>
        <el-table-column label="余额" width="100">
          <template #default="{ row }">{{ row.balance_after ?? row.balance ?? '-' }}</template>
        </el-table-column>
        <el-table-column label="描述" show-overflow-tooltip>
          <template #default="{ row }">{{ row.description ?? row.remark ?? '-' }}</template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'
import { useUserStore } from '@stores/user'

const userStore = useUserStore()
const balance = reactive({ total: 0, used: 0, available: 0 })
const transactions = ref<any[]>([])
const formatDate = (d: string) => d ? d.substring(0, 16) : '-'
const typeTag = (t: string) => ({ recharge: 'success', consume: 'danger', gift: 'info', refund: 'warning', credit: 'success', debit: 'danger' }[t] || 'info')
const typeLabel = (t: string) => ({ recharge: '充值', consume: '消费', gift: '赠送', refund: '退款', credit: '充值', debit: '消费' }[t] || t)

const fetchCredits = async () => {
  try {
    const r = await axios.get(`/api/v1/tenants/${userStore.tenantId}/credits`)
    const data = r.data.data || r.data
    balance.total = data.total ?? data.balance ?? 0
    balance.used = data.used ?? data.consumed ?? 0
    balance.available = data.available ?? data.balance ?? 0
    transactions.value = data.transactions ?? data.history ?? []
  } catch {}
}

onMounted(fetchCredits)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
</style>
