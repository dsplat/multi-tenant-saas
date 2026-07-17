<template>
  <div class="page">
    <div class="page-header"><h2>积分总览</h2></div>

    <el-card shadow="never">
      <div v-if="overview" class="stats-grid">
        <el-row :gutter="16">
          <el-col :span="6">
            <el-card shadow="hover"><el-statistic title="租户总数" :value="overview.total_tenants ?? '-'" /></el-card>
          </el-col>
          <el-col :span="6">
            <el-card shadow="hover"><el-statistic title="总余额" :value="overview.total_balance ?? '-'" /></el-card>
          </el-col>
          <el-col :span="6">
            <el-card shadow="hover"><el-statistic title="总充值" :value="overview.total_recharged ?? '-'" /></el-card>
          </el-col>
          <el-col :span="6">
            <el-card shadow="hover"><el-statistic title="总消耗" :value="overview.total_consumed ?? '-'" /></el-card>
          </el-col>
        </el-row>
      </div>

      <h3 style="margin: 24px 0 12px;">批量充值</h3>
      <el-form @submit.prevent="handleBatchRecharge" label-width="120px" style="max-width: 400px">
        <el-form-item label="租户 ID"><el-input v-model="rechargeForm.tenant_ids" placeholder="1001,1002,1003" /></el-form-item>
        <el-form-item label="充值金额"><el-input-number v-model="rechargeForm.amount" :min="1" style="width: 100%" /></el-form-item>
        <el-form-item label="备注"><el-input v-model="rechargeForm.remark" /></el-form-item>
        <el-form-item><el-button type="primary" :loading="recharging" @click="handleBatchRecharge">充值</el-button></el-form-item>
      </el-form>

      <el-alert v-if="rechargeResult" :title="`成功: ${rechargeResult.succeeded ?? 0}，失败: ${rechargeResult.failed ?? 0}`" :type="rechargeResult.error ? 'error' : 'success'" show-icon :closable="false" style="margin-top: 16px" />
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const overview = ref<any>(null)
const rechargeForm = ref({ tenant_ids: '', amount: 0, remark: '' })
const recharging = ref(false)
const rechargeResult = ref<any>(null)

const fetchOverview = async () => { try { const r = await axios.get('/v1/admin/admin/billing/credits/overview'); overview.value = r.data.data || r.data } catch {} }

const handleBatchRecharge = async () => {
  recharging.value = true; rechargeResult.value = null
  try {
    const ids = rechargeForm.value.tenant_ids.split(',').map(s => parseInt(s.trim())).filter(Boolean)
    const r = await axios.post('/v1/admin/admin/billing/credits/batch-recharge', { tenant_ids: ids, amount: rechargeForm.value.amount, remark: rechargeForm.value.remark })
    rechargeResult.value = r.data.data || r.data; await fetchOverview()
  } catch (e: any) { rechargeResult.value = { error: e.response?.data?.message || e.message } } finally { recharging.value = false }
}

onMounted(fetchOverview)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.stats-grid { margin-bottom: 24px; }
</style>
