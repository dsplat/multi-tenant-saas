<template>
  <div class="onboarding-page">
    <div class="onboarding-card">
      <h1 class="title">创建团队</h1>
      <p class="subtitle">完成以下步骤，提交团队申请等待平台审核</p>

      <!-- 未登录 Operator 提示 -->
      <div v-if="!isLoggedIn" class="login-tip">
        <el-alert
          title="请先登录运营者账户"
          type="warning"
          description="团队注册由已认证的运营者发起。请先登录或注册 Operator 账户后再来本页面。"
          show-icon
          :closable="false"
        />
        <div class="login-actions">
          <el-button type="primary" @click="$router.push('/login')">前往登录</el-button>
          <el-button @click="$router.push('/register')">注册 Operator</el-button>
        </div>
      </div>

      <!-- 步骤指示器 -->
      <el-steps v-if="isLoggedIn" :active="currentStep - 1" finish-status="success" align-center class="steps">
        <el-step title="团队信息" />
        <el-step title="组织信息" />
        <el-step title="套餐" />
        <el-step title="完成" />
      </el-steps>

      <!-- 步骤 1: 团队基本信息 -->
      <div v-if="isLoggedIn && currentStep === 1" class="step-content">
        <el-form :model="form" label-width="100px" @submit.prevent="startOnboarding">
          <el-form-item label="团队名称" required>
            <el-input v-model="form.name" placeholder="如：星河科技有限公司" />
          </el-form-item>
          <el-form-item label="行业">
            <el-select v-model="form.industry" placeholder="请选择行业" style="width: 100%" clearable>
              <el-option label="电商零售" value="retail" />
              <el-option label="教育培训" value="education" />
              <el-option label="金融保险" value="finance" />
              <el-option label="医疗健康" value="healthcare" />
              <el-option label="餐饮服务" value="food" />
              <el-option label="其他" value="other" />
            </el-select>
          </el-form-item>
          <el-form-item label="规模">
            <el-radio-group v-model="form.size">
              <el-radio value="small">1-50人</el-radio>
              <el-radio value="medium">51-200人</el-radio>
              <el-radio value="large">200人以上</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item label="联系电话">
            <el-input v-model="form.contact_phone" placeholder="可选，便于平台审核联络" />
          </el-form-item>
          <el-form-item>
            <el-button type="primary" :loading="loading" @click="startOnboarding">下一步</el-button>
          </el-form-item>
        </el-form>
      </div>

      <!-- 步骤 2: 组织信息（域名） -->
      <div v-if="isLoggedIn && currentStep === 2" class="step-content">
        <el-form :model="orgForm" label-width="120px" @submit.prevent="saveStep2">
          <el-form-item label="域名类型">
            <el-radio-group v-model="orgForm.domain_type">
              <el-radio value="subdomain">使用子域名</el-radio>
              <el-radio value="custom">绑定独立域名</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item v-if="orgForm.domain_type === 'subdomain'" label="子域名">
            <el-input v-model="orgForm.subdomain" placeholder="如：mycompany">
              <template #append>.your-domain.com</template>
            </el-input>
          </el-form-item>
          <el-form-item v-if="orgForm.domain_type === 'custom'" label="独立域名">
            <el-input v-model="orgForm.custom_domain" placeholder="如：www.mycompany.com" />
          </el-form-item>
          <el-form-item>
            <el-button @click="currentStep = 1">上一步</el-button>
            <el-button type="primary" :loading="loading" @click="saveStep2">下一步</el-button>
          </el-form-item>
        </el-form>
      </div>

      <!-- 步骤 3: 套餐 -->
      <div v-if="isLoggedIn && currentStep === 3" class="step-content">
        <el-form :model="planForm" label-width="120px" @submit.prevent="saveStep3">
          <el-form-item label="套餐">
            <el-select v-model="planForm.plan" placeholder="请选择套餐" style="width: 100%">
              <el-option label="免费版" value="free" />
              <el-option label="专业版" value="pro" />
              <el-option label="企业版" value="enterprise" />
            </el-select>
          </el-form-item>
          <el-form-item label="开启试用">
            <el-switch v-model="planForm.trial" />
          </el-form-item>
          <el-form-item>
            <el-button @click="currentStep = 2">上一步</el-button>
            <el-button type="primary" :loading="loading" @click="saveStep3">下一步</el-button>
          </el-form-item>
        </el-form>
      </div>

      <!-- 步骤 4: 完成 -->
      <div v-if="isLoggedIn && currentStep === 4" class="step-content complete">
        <el-result icon="success" title="团队申请已提交" sub-title="平台审核通过后，您可以用当前 Operator 账户直接登录控制台">
          <template #extra>
            <el-button type="primary" @click="goToConsole">进入控制台</el-button>
            <el-button @click="goToDashboard">返回首页</el-button>
          </template>
        </el-result>
        <div v-if="submittedTenant" class="tenant-info">
          <p><strong>团队名称：</strong>{{ submittedTenant.name }}</p>
          <p><strong>状态：</strong>
            <el-tag type="warning">{{ submittedTenant.status }}</el-tag>
          </p>
          <p class="hint">审核通过前进入控制台将看到"等待审核"提示</p>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'
import { ElMessage } from 'element-plus'

const router = useRouter()
const currentStep = ref(1)
const loading = ref(false)
const onboardingToken = ref<string | null>(null)
const submittedTenant = ref<{ name: string; status: string } | null>(null)

const form = ref({
  name: '',
  industry: '',
  size: 'small',
  contact_phone: '',
})

const orgForm = ref({
  domain_type: 'subdomain',
  subdomain: '',
  custom_domain: '',
})

const planForm = ref({
  plan: 'free',
  trial: true,
})

// 已登录 Operator 判断（用 operator_token）
const isLoggedIn = computed(() => !!localStorage.getItem('operator_token'))

const operatorAuthHeader = computed(() => ({
  Authorization: `Bearer ${localStorage.getItem('operator_token') || ''}`,
}))

// 检查是否有 onboarding token（URL 参数），支持断点续填
onMounted(async () => {
  const token = new URLSearchParams(window.location.search).get('token')
  if (token && isLoggedIn.value) {
    onboardingToken.value = token
    try {
      const res = await axios.post(
        '/api/v1/tenants/onboarding/status',
        { onboarding_token: token },
        { headers: operatorAuthHeader.value },
      )
      if (res.data.success && res.data.data.current_step > 1) {
        currentStep.value = res.data.data.current_step
      }
    } catch {
      // token 无效，从头开始
      onboardingToken.value = null
    }
  }
})

const startOnboarding = async () => {
  if (!form.value.name) {
    ElMessage.warning('请填写团队名称')
    return
  }
  loading.value = true
  try {
    const res = await axios.post(
      '/api/v1/tenants/onboarding/start',
      form.value,
      { headers: operatorAuthHeader.value },
    )
    if (res.data.success) {
      onboardingToken.value = res.data.data.onboarding_token
      currentStep.value = 2
    }
  } catch (err: any) {
    ElMessage.error(err.response?.data?.message || '提交失败，请重试')
  } finally {
    loading.value = false
  }
}

const saveStep2 = async () => {
  if (!onboardingToken.value) {
    ElMessage.error('会话已过期，请重新开始')
    currentStep.value = 1
    return
  }
  loading.value = true
  try {
    await axios.post(
      '/api/v1/tenants/onboarding/2',
      { onboarding_token: onboardingToken.value, ...orgForm.value },
      { headers: operatorAuthHeader.value },
    )
    currentStep.value = 3
  } catch (err: any) {
    ElMessage.error(err.response?.data?.message || '提交失败，请重试')
  } finally {
    loading.value = false
  }
}

const saveStep3 = async () => {
  if (!onboardingToken.value) {
    ElMessage.error('会话已过期，请重新开始')
    currentStep.value = 1
    return
  }
  loading.value = true
  try {
    // 跳过支付步骤（试用版无支付）
    await axios.post(
      '/api/v1/tenants/onboarding/3',
      { onboarding_token: onboardingToken.value, ...planForm.value },
      { headers: operatorAuthHeader.value },
    )
    // Step4：跳过支付直接完成
    await axios.post(
      '/api/v1/tenants/onboarding/4',
      { onboarding_token: onboardingToken.value, skip: true },
      { headers: operatorAuthHeader.value },
    )
    // 完成注册
    const res = await axios.post(
      '/api/v1/tenants/onboarding/complete',
      { onboarding_token: onboardingToken.value },
      { headers: operatorAuthHeader.value },
    )
    if (res.data.success) {
      submittedTenant.value = {
        name: res.data.data.name,
        status: res.data.data.status,
      }
      currentStep.value = 4
    }
  } catch (err: any) {
    ElMessage.error(err.response?.data?.message || '提交失败，请重试')
  } finally {
    loading.value = false
  }
}

const goToConsole = () => {
  // 用 operator_token 进入 console；后端 console 入口会自动识别 Operator 关联的团队
  window.location.href = '/console/'
}

const goToDashboard = () => {
  window.location.href = '/'
}
</script>

<style scoped>
.onboarding-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  padding: 20px;
}

.onboarding-card {
  width: 100%;
  max-width: 560px;
  background: #fff;
  border-radius: 16px;
  padding: 40px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
}

.title {
  font-size: 24px;
  font-weight: 700;
  color: #1f2937;
  margin: 0 0 8px;
  text-align: center;
}

.subtitle {
  font-size: 14px;
  color: #6b7280;
  text-align: center;
  margin: 0 0 32px;
}

.steps {
  margin-bottom: 32px;
}

.step-content {
  min-height: 200px;
}

.complete {
  text-align: center;
  padding: 20px 0;
}

.login-tip {
  padding: 20px 0;
}

.login-actions {
  margin-top: 16px;
  text-align: center;
}

.tenant-info {
  margin-top: 20px;
  padding: 16px;
  background: #f9fafb;
  border-radius: 8px;
  text-align: left;
}

.tenant-info p {
  margin: 4px 0;
  color: #4b5563;
  font-size: 14px;
}

.tenant-info .hint {
  color: #9ca3af;
  font-size: 12px;
  margin-top: 8px;
}
</style>
