<template>
  <div class="invite-page">
    <div class="invite-card">
      <div class="header">
        <el-icon :size="48" color="#10b981"><Promotion /></el-icon>
        <h1 class="title">接受邀请</h1>
        <p class="subtitle">您被邀请加入团队，请设置密码完成注册</p>
      </div>

      <!-- 已接受状态 -->
      <el-result
        v-if="accepted"
        icon="success"
        title="邀请已接受"
        sub-title="您的账号已激活，请登录开始使用"
      >
        <template #extra>
          <el-button type="primary" @click="$router.push('/login')">前往登录</el-button>
          <el-button @click="$router.push('/apply')">申请团队</el-button>
        </template>
      </el-result>

      <!-- 表单 -->
      <el-form
        v-else
        :model="form"
        :rules="rules"
        ref="formRef"
        label-width="100px"
        @submit.prevent="handleAccept"
      >
        <el-form-item label="邀请码" v-if="!hasTokenInUrl">
          <el-input v-model="form.token" placeholder="请输入邀请码" />
        </el-form-item>
        <el-form-item label="邮箱" v-if="emailFromUrl">
          <el-input :model-value="emailFromUrl" disabled />
        </el-form-item>
        <el-form-item label="密码" prop="password">
          <el-input v-model="form.password" type="password" show-password placeholder="至少8位" />
        </el-form-item>
        <el-form-item label="确认密码" prop="password_confirmation">
          <el-input
            v-model="form.password_confirmation"
            type="password"
            show-password
            placeholder="再次输入密码"
          />
        </el-form-item>
        <el-form-item>
          <el-button type="primary" :loading="loading" @click="handleAccept">接受邀请</el-button>
          <el-button @click="$router.push('/login')">返回登录</el-button>
        </el-form-item>
      </el-form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import axios from 'axios'
import { ElMessage, type FormInstance, type FormRules } from 'element-plus'
import { Promotion } from '@element-plus/icons-vue'

const router = useRouter()
const formRef = ref<FormInstance>()
const loading = ref(false)
const accepted = ref(false)

const urlParams = new URLSearchParams(window.location.search)
const tokenFromUrl = urlParams.get('token')
const emailFromUrl = urlParams.get('email')
const hasTokenInUrl = computed(() => !!tokenFromUrl)

const form = ref({
  token: tokenFromUrl || '',
  password: '',
  password_confirmation: '',
})

const rules: FormRules = {
  password: [
    { required: true, message: '请输入密码', trigger: 'blur' },
    { min: 8, message: '密码至少8位', trigger: 'blur' },
  ],
  password_confirmation: [
    { required: true, message: '请确认密码', trigger: 'blur' },
    {
      validator: (_rule: any, value: string, callback: any) => {
        if (value !== form.value.password) {
          callback(new Error('两次输入的密码不一致'))
        } else {
          callback()
        }
      },
      trigger: 'blur',
    },
  ],
}

const handleAccept = async () => {
  if (!formRef.value) return
  await formRef.value.validate(async (valid) => {
    if (!valid) return
    loading.value = true
    try {
      const res = await axios.post('/api/v1/operator/accept-invite', {
        token: form.value.token,
        password: form.value.password,
        password_confirmation: form.value.password_confirmation,
      })
      if (res.data.success) {
        accepted.value = true
        ElMessage.success('邀请已接受，请登录')
      }
    } catch (err: any) {
      ElMessage.error(err.response?.data?.message || '接受邀请失败，请检查邀请码')
    } finally {
      loading.value = false
    }
  })
}
</script>

<style scoped>
.invite-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
  padding: 20px;
}

.invite-card {
  width: 100%;
  max-width: 480px;
  background: #fff;
  border-radius: 16px;
  padding: 40px;
  box-shadow: 0 20px 60px rgba(0, 0, 0, 0.15);
}

.header {
  text-align: center;
  margin-bottom: 32px;
}

.title {
  font-size: 24px;
  font-weight: 700;
  color: #1f2937;
  margin: 16px 0 8px;
}

.subtitle {
  font-size: 14px;
  color: #6b7280;
  margin: 0;
}
</style>
