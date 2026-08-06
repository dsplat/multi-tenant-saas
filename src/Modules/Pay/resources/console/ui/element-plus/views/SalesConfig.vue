<template>
  <div class="page-container">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>销售折现配置</span>
        </div>
      </template>

      <el-form
        v-loading="loading"
        :model="formData"
        label-width="200px"
        style="max-width: 640px"
      >
        <el-form-item label="启用混合支付（积分+现金）">
          <el-switch v-model="formData.mixed_pay_enabled" />
          <span class="form-tip">开启后用户可在支付时使用积分抵扣部分金额，剩余部分现金支付</span>
        </el-form-item>

        <el-form-item label="积分折现比例">
          <el-input-number
            v-model="formData.points_to_cash_ratio"
            :min="1"
            :max="100000"
            :step="10"
            style="width: 200px"
          />
          <span class="form-tip">积分 = 1 元（如 100 表示 100 积分抵扣 1 元）</span>
        </el-form-item>

        <el-form-item label="积分最高抵扣比例">
          <el-input-number
            v-model="formData.max_points_deduct_ratio"
            :min="0"
            :max="100"
            :step="10"
            style="width: 200px"
          />
          <span class="form-tip">%（积分抵扣金额占订单总额的上限）</span>
        </el-form-item>

        <el-form-item>
          <el-button type="primary" :loading="submitting" @click="handleSave"> 保存配置 </el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { ElMessage } from 'element-plus'
import { getSalesConfig, updateSalesConfig } from '@modules/Pay/api/sales-config'

defineOptions({ name: 'SalesConfig' })

const loading = ref(false)
const submitting = ref(false)

const formData = reactive({
  mixed_pay_enabled: false,
  points_to_cash_ratio: 100,
  max_points_deduct_ratio: 50,
})

async function loadConfig() {
  loading.value = true
  try {
    const config = await getSalesConfig()
    formData.mixed_pay_enabled = !!config.mixed_pay_enabled
    formData.points_to_cash_ratio = Number(config.points_to_cash_ratio) || 100
    formData.max_points_deduct_ratio = Number(config.max_points_deduct_ratio) || 0
  } catch (e: any) {
    ElMessage.error(e.message || '获取配置失败')
  } finally {
    loading.value = false
  }
}

async function handleSave() {
  submitting.value = true
  try {
    await updateSalesConfig({
      mixed_pay_enabled: formData.mixed_pay_enabled,
      points_to_cash_ratio: formData.points_to_cash_ratio,
      max_points_deduct_ratio: formData.max_points_deduct_ratio,
    })
    ElMessage.success('保存成功')
  } catch (e: any) {
    ElMessage.error(e.message || '保存失败')
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  loadConfig()
})
</script>

<style scoped>
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.form-tip {
  margin-left: 8px;
  color: var(--el-text-color-secondary);
  font-size: 12px;
}
</style>
