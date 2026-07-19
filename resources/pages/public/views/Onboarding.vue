<template>
  <div class="onboarding-page">
    <div class="onboarding-card">
      <h1 class="title">租户注册引导</h1>
      <p class="subtitle">完成以下步骤，快速创建您的租户</p>

      <!-- 步骤指示器 -->
      <el-steps :active="currentStep - 1" finish-status="success" align-center class="steps">
        <el-step title="基本信息" />
        <el-step title="组织信息" />
        <el-step title="完成" />
      </el-steps>

      <!-- 步骤 1: 基本信息 -->
      <div v-if="currentStep === 1" class="step-content">
        <el-form :model="form" label-width="100px" @submit.prevent="startOnboarding">
          <el-form-item label="租户名称" required>
            <el-input v-model="form.name" placeholder="请输入租户/组织名称" />
          </el-form-item>
          <el-form-item label="管理员邮箱" required>
            <el-input v-model="form.admin_email" type="email" placeholder="admin@example.com" />
          </el-form-item>
          <el-form-item label="密码" required>
            <el-input v-model="form.password" type="password" show-password placeholder="至少8位" />
          </el-form-item>
          <el-form-item>
            <el-button type="primary" :loading="loading" @click="startOnboarding">下一步</el-button>
            <el-button @click="$router.push('/login')">已有账号？登录</el-button>
          </el-form-item>
        </el-form>
      </div>

      <!-- 步骤 2: 组织信息 -->
      <div v-if="currentStep === 2" class="step-content">
        <el-form :model="orgForm" label-width="100px" @submit.prevent="saveStep2">
          <el-form-item label="行业">
            <el-select v-model="orgForm.industry" placeholder="请选择行业" style="width: 100%">
              <el-option label="电商零售" value="retail" />
              <el-option label="教育培训" value="education" />
              <el-option label="金融保险" value="finance" />
              <el-option label="医疗健康" value="healthcare" />
              <el-option label="餐饮服务" value="food" />
              <el-option label="其他" value="other" />
            </el-select>
          </el-form-item>
          <el-form-item label="规模">
            <el-radio-group v-model="orgForm.size">
              <el-radio value="small">1-50人</el-radio>
              <el-radio value="medium">51-200人</el-radio>
              <el-radio value="large">200人以上</el-radio>
            </el-radio-group>
          </el-form-item>
          <el-form-item label="联系电话">
            <el-input v-model="orgForm.phone" placeholder="可选" />
          </el-form-item>
          <el-form-item>
            <el-button @click="currentStep = 1">上一步</el-button>
            <el-button type="primary" :loading="loading" @click="saveStep2">完成注册</el-button>
          </el-form-item>
        </el-form>
      </div>

      <!-- 步骤 3: 完成 -->
      <div v-if="currentStep === 3" class="step-content complete">
        <el-result icon="success" title="注册成功" sub-title="您的租户已创建完成，试用期已激活">
          <template #extra>
            <el-button type="primary" @click="goToConsole">进入控制台</el-button>
            <el-button @click="$router.push('/login')">前往登录</el-button>
          </template>
        </el-result>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'
import { ElMessage } from 'element-plus'

const router = useRouter()
const currentStep = ref(1)
const loading = ref(false)
const authToken = ref<string | null>(null)

const form = ref({
  name: '',
  admin_email: '',
  password: '',
})

const orgForm = ref({
  industry: '',
  size: 'small',
  phone: '',
})

// 检查是否有 onboarding token（从 URL 参数或 localStorage）
onMounted(async () => {
  const token = new URLSearchParams(window.location.search).get('token')
  if (token) {
    authToken.value = token
    // 查询已有进度
    try {
      const res = await axios.post('/api/v1/tenants/onboarding/status', { auth_token: token })
      if (res.data.success && res.data.data.current_step > 1) {
        currentStep.value = res.data.data.current_step
      }
    } catch {
      // token 无效，从头开始
    }
  }
})

const startOnboarding = async () => {
  if (!form.value.name || !form.value.admin_email || !form.value.password) {
    ElMessage.warning('请填写所有必填项')
    return
  }
  loading.value = true
  try {
    const res = await axios.post('/api/v1/tenants/register', form.value)
    if (res.data.success) {
      authToken.value = res.data.data.auth_token
      currentStep.value = 2
    }
  } catch (err: any) {
    ElMessage.error(err.response?.data?.message || '注册失败，请重试')
  } finally {
    loading.value = false
  }
}

const saveStep2 = async () => {
  if (!authToken.value) {
    ElMessage.error('会话已过期，请重新开始')
    currentStep.value = 1
    return
  }
  loading.value = true
  try {
    // 保存步骤2数据
    await axios.post('/api/v1/tenants/onboarding/2', {
      auth_token: authToken.value,
      ...orgForm.value,
    })
    // 完成注册
    const res = await axios.post('/api/v1/tenants/onboarding/complete', {
      auth_token: authToken.value,
    })
    if (res.data.success) {
      // 保存后端返回的 auth_token，实现自动登录
      const data = res.data.data || {}
      if (data.auth_token) {
        localStorage.setItem('user_token', data.auth_token)
        localStorage.setItem('user', JSON.stringify(data.user || {}))
        localStorage.setItem('current_tenant', JSON.stringify({
          tenant_id: data.tenant_id,
          name: data.name,
          slug: data.slug,
        }))
      }
      currentStep.value = 3
    }
  } catch (err: any) {
    ElMessage.error(err.response?.data?.message || '提交失败，请重试')
  } finally {
    loading.value = false
  }
}

const goToConsole = () => {
  // 如果有 auth_token，直接进入 console；否则跳转登录页
  if (localStorage.getItem('user_token')) {
    window.location.href = '/console/'
  } else {
    window.location.href = '/login'
  }
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
</style>
