<template>
  <div class="login-page">
    <el-card class="login-card" shadow="always">
      <div class="login-header">
        <el-icon :size="32" color="var(--el-color-primary)"><Platform /></el-icon>
        <h2>系统后台登录</h2>
      </div>

      <el-form ref="formRef" :model="form" label-position="top" @submit.prevent="handleLogin">
        <el-form-item label="邮箱" prop="email">
          <el-input
            v-model="form.email"
            type="email"
            placeholder="请输入邮箱"
            :prefix-icon="Message"
            size="large"
          />
        </el-form-item>

        <el-form-item label="密码" prop="password">
          <el-input
            v-model="form.password"
            type="password"
            placeholder="请输入密码"
            :prefix-icon="Lock"
            show-password
            size="large"
            @keyup.enter="handleLogin"
          />
        </el-form-item>

        <el-button
          type="primary"
          size="large"
          :loading="loading"
          @click="handleLogin"
          style="width: 100%"
        >
          {{ loading ? '登录中...' : '登录' }}
        </el-button>

        <el-alert
          v-if="error"
          :title="error"
          type="error"
          show-icon
          :closable="false"
          style="margin-top: 16px"
        />
      </el-form>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { Platform, Message, Lock } from '@element-plus/icons-vue'
import { useUserStore } from '@/admin/stores/user'

const router = useRouter()
const route = useRoute()
const userStore = useUserStore()

const loading = ref(false)
const error = ref('')

const form = reactive({
  email: '',
  password: '',
})

const handleLogin = async () => {
  loading.value = true
  error.value = ''
  try {
    await userStore.login(form.email, form.password)
    const redirect = (route.query.redirect as string) || '/dashboard'
    router.push(redirect)
  } catch (e: any) {
    error.value = e.response?.data?.message || '登录失败'
  } finally {
    loading.value = false
  }
}
</script>

<style scoped>
.login-page {
  height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  background: var(--bg-color-page, #f5f7fa);
}

.login-card {
  width: 420px;
  padding: 16px 20px;
}

.login-header {
  text-align: center;
  margin-bottom: 28px;
}

.login-header h2 {
  margin: 12px 0 0;
  color: var(--text-color-primary, #303133);
}
</style>
