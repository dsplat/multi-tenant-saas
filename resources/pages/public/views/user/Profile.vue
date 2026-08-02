<template>
  <div class="page">
    <h1 class="page-title">个人资料</h1>
    <div class="panel">
      <div v-if="msg" :class="['alert', success ? 'alert-success' : 'alert-error']">{{ msg }}</div>
      <form @submit.prevent="save">
        <div class="form-group">
          <label>头像</label>
          <div class="avatar-row">
            <div class="avatar-preview">
              <img v-if="avatarUrl" :src="avatarUrl" alt="头像" />
              <span v-else>{{ (form.name || user?.email || '?').slice(0, 1).toUpperCase() }}</span>
            </div>
            <input
              ref="avatarInput"
              type="file"
              accept="image/jpeg,image/png,image/gif,image/webp"
              class="avatar-input"
              @change="uploadAvatar"
            />
            <button type="button" class="btn btn-outline" :disabled="uploading" @click="avatarInput?.click()">
              {{ uploading ? '上传中...' : '上传头像' }}
            </button>
          </div>
          <span class="hint">支持 JPG/PNG/GIF/WebP，不超过 2MB</span>
        </div>
        <div class="form-group">
          <label>姓名</label>
          <input v-model="form.name" type="text" placeholder="您的姓名" />
        </div>
        <div class="form-group">
          <label>邮箱</label>
          <input :value="user?.email" type="email" disabled class="disabled" />
          <span class="hint">邮箱不可修改</span>
        </div>
        <div class="form-group">
          <label>手机号</label>
          <input v-model="form.phone" type="text" placeholder="选填" />
        </div>
        <button type="submit" class="btn btn-primary" :disabled="saving">
          {{ saving ? '保存中...' : '保存修改' }}
        </button>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'

const user = ref<any>(null)
const avatarInput = ref<HTMLInputElement>()
const saving = ref(false)
const uploading = ref(false)
const msg = ref('')
const success = ref(false)
const form = reactive({ name: '', phone: '' })

const avatarUrl = computed(() => user.value?.avatar || '')

onMounted(() => {
  try {
    const stored = localStorage.getItem('user_info')
    if (stored) {
      user.value = JSON.parse(stored)
      form.name = user.value.name || ''
      form.phone = user.value.phone || ''
    }
  } catch {}
})

async function uploadAvatar(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  if (file.size > 2 * 1024 * 1024) {
    success.value = false
    msg.value = '头像文件不能超过 2MB'
    input.value = ''
    return
  }
  uploading.value = true
  msg.value = ''
  try {
    const token = localStorage.getItem('user_token')
    const formData = new FormData()
    formData.append('avatar', file)
    const res = await fetch('/api/v1/auth/profile/avatar', {
      method: 'POST',
      headers: { Authorization: `Bearer ${token}` },
      body: formData,
    })
    const data = await res.json()
    if (data.success) {
      success.value = true
      msg.value = '头像已更新'
      if (user.value) {
        // 追加缓存破坏参数，避免浏览器缓存旧图片
        user.value.avatar = `${data.data.avatar}?t=${Date.now()}`
        localStorage.setItem('user_info', JSON.stringify(user.value))
      }
    } else {
      success.value = false
      msg.value = data.message || '上传失败'
    }
  } catch {
    success.value = false
    msg.value = '网络错误'
  } finally {
    uploading.value = false
    input.value = ''
  }
}

async function save() {
  saving.value = true
  msg.value = ''
  try {
    const token = localStorage.getItem('user_token')
    const res = await fetch('/api/v1/auth/profile', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', Authorization: `Bearer ${token}` },
      body: JSON.stringify(form),
    })
    const data = await res.json()
    if (data.success) {
      success.value = true
      msg.value = '保存成功'
      // 更新本地缓存
      if (data.data) {
        localStorage.setItem('user_info', JSON.stringify(data.data))
        user.value = data.data
      }
    } else {
      success.value = false
      msg.value = data.message || '保存失败'
    }
  } catch {
    success.value = false
    msg.value = '网络错误'
  } finally {
    saving.value = false
  }
}
</script>

<style scoped>
.page-title { font-size: 24px; margin-bottom: 24px; }
.panel { background: #fff; border-radius: 8px; padding: 24px; max-width: 480px; box-shadow: 0 1px 3px rgba(0,0,0,0.08); }
.form-group { margin-bottom: 16px; }
.avatar-row { display: flex; align-items: center; gap: 12px; }
.avatar-preview { width: 64px; height: 64px; border-radius: 50%; background: #e3f2fd; color: #1565c0; display: flex; align-items: center; justify-content: center; font-size: 24px; overflow: hidden; flex-shrink: 0; }
.avatar-preview img { width: 100%; height: 100%; object-fit: cover; }
.avatar-input { display: none; }
.btn-outline { background: #fff; border: 1px solid #ddd; color: #333; }
.btn-outline:disabled { opacity: 0.6; cursor: not-allowed; }
.form-group label { display: block; font-size: 13px; color: #555; margin-bottom: 6px; }
.form-group input { width: 100%; padding: 10px 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px; }
.form-group input.disabled { background: #f5f5f5; color: #999; }
.hint { font-size: 12px; color: #999; margin-top: 4px; display: block; }
.btn { padding: 10px 20px; border: none; border-radius: 6px; font-size: 14px; cursor: pointer; }
.btn-primary { background: #1976d2; color: #fff; }
.btn-primary:disabled { opacity: 0.6; cursor: not-allowed; }
.alert { padding: 10px 14px; border-radius: 6px; margin-bottom: 16px; font-size: 14px; }
.alert-success { background: #e8f5e9; color: #2e7d32; }
.alert-error { background: #fbe9e7; color: #c62828; }
</style>
