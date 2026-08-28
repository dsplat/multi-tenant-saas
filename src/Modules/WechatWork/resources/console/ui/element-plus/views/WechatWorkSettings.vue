<template>
  <div class="page">
    <div class="page-header"><h2>企业微信配置</h2></div>

    <el-card shadow="never" style="max-width: 860px">
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 16px">
        <template #title>
          企业微信扫码登录开关在「第三方登录配置 → 企业微信」；本页承载企业微信域名归属认证（WW_verify 验证文件）等企微能力配置。
        </template>
      </el-alert>

      <div class="section-title">可信域名验证文件（WW_verify）</div>
      <p class="form-tip">
        自建应用在企微后台「应用详情 → 开发者接口 → 网页授权及JS-SDK」配置<b>可信域名</b>时，企微会下发
        <code>WW_verify_xxx.txt</code> 验证文件用于域名归属认证。将文件名登记到下方列表，系统自动在回调域名
        （{{ verifyDomain || '未配置回调域' }}）根路径提供该文件，企微校验即可通过。
      </p>
      <p class="form-tip" style="color: var(--el-color-warning, #e6a23c)">
        注意：该验证与扫码登录无关——OAuth 登录只需配置「授权回调域」，不需要验证文件。
      </p>

      <div v-if="verifyFiles.length" style="margin-bottom: 8px">
        <div
          v-for="f in verifyFiles"
          :key="f"
          style="display: flex; align-items: center; gap: 6px; margin-bottom: 4px"
        >
          <code>{{ f }}</code>
          <a v-if="verifyDomain" :href="`https://${verifyDomain}/${f}`" target="_blank" rel="noopener">验证</a>
          <el-button link type="danger" size="small" @click="handleRemoveVerifyFile(f)">删除</el-button>
        </div>
      </div>
      <div style="display: flex; gap: 6px; margin-top: 4px">
        <el-input
          v-model="verifyFileInput"
          size="small"
          style="max-width: 280px"
          placeholder="如：WW_verify_mLUxXhK2fEC6jPsB"
          @keyup.enter="handleAddVerifyFile"
        />
        <el-button size="small" type="primary" :loading="verifyFilesSaving" @click="handleAddVerifyFile">添加</el-button>
      </div>

      <el-divider />

      <div class="help-box">
        <div class="help-title">📖 配置指引（企业微信管理后台）</div>
        <ol>
          <li>管理员登录 <a href="https://work.weixin.qq.com/wework_admin/" target="_blank" rel="noopener">企业微信管理后台</a> →「应用管理」→ 进入自建应用详情。</li>
          <li>「开发者接口」→「网页授权及JS-SDK」→ 配置<b>可信域名</b>：下载企微下发的 <code>WW_verify_xxx.txt</code>，将文件名（如 <code>WW_verify_mLUxXhK2fEC6jPsB</code>）填入上方列表添加。</li>
          <li>添加后点「验证」链接确认文件可在域名根路径访问，再回企微后台保存可信域名。</li>
          <li>扫码登录（OAuth）走「企业微信授权登录」的「授权回调域」，与本页验证文件无关。</li>
        </ol>
      </div>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'
import { useUserStore } from '@stores/user'

const userStore = useUserStore()

const tenantDomain = ref('')
const verifyFiles = ref<string[]>([])
const verifyFileInput = ref('')
const verifyFilesSaving = ref(false)

// 验证文件宿主域名 = 企微后台填写的可信域名（租户自定义域名或平台统一回调域）
const verifyDomain = computed(() => tenantDomain.value || window.location.host)

const loadVerifyFiles = async () => {
  try {
    const res = await axios.get(`/api/v1/tenant/${userStore.tenantId}/domain/verify-info`)
    const data = res.data.data || {}
    tenantDomain.value = data.domain || ''
    verifyFiles.value = data.third_party_verify_files || []
  } catch {}
}

const saveVerifyFiles = async (files: string[]) => {
  verifyFilesSaving.value = true
  try {
    const res = await axios.post(`/api/v1/tenant/${userStore.tenantId}/domain/verify-files`, { files })
    const data = res.data.data || {}
    verifyFiles.value = data.third_party_verify_files || []
    return true
  } catch (e) {
    const m = e?.response?.data?.message
    ElMessage.error(typeof m === 'string' ? m : '操作失败')
    return false
  } finally {
    verifyFilesSaving.value = false
  }
}

const handleAddVerifyFile = async () => {
  const name = verifyFileInput.value.trim()
  if (!name) return
  if (verifyFiles.value.includes(name) || verifyFiles.value.includes(name + '.txt')) {
    ElMessage.warning('该验证文件已存在')
    return
  }
  const ok = await saveVerifyFiles([...verifyFiles.value, name])
  if (ok) {
    verifyFileInput.value = ''
    ElMessage.success('验证文件已添加，企微可立即校验')
  }
}

const handleRemoveVerifyFile = async (file: string) => {
  const ok = await saveVerifyFiles(verifyFiles.value.filter(f => f !== file))
  if (ok) ElMessage.success('验证文件已删除')
}

onMounted(loadVerifyFiles)
</script>
