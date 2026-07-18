<template>
  <div class="page">
    <div class="page-header">
      <h2>申请租户</h2>
    </div>
    <el-card shadow="never" style="max-width: 600px;">
      <div v-if="error" class="el-alert el-alert--error" style="margin-bottom: 16px;">{{ error }}</div>
      <div v-if="submitted" class="el-alert el-alert--success" style="margin-bottom: 16px;">
        申请已提交！编号: {{ submitted.code }}
        <router-link to="/console/my-applications">查看我的申请</router-link>
      </div>
      <el-form v-if="!submitted" :model="form" label-width="100px" @submit.prevent="handleApply">
        <el-form-item v-for="field in visibleFields" :key="field.name" :label="field.label" :required="field.required">
          <el-input v-if="field.type === 'text' || field.type === 'tel' || field.type === 'email'"
            v-model="form[field.name]" :placeholder="`请输入${field.label}`" />
          <el-input v-else-if="field.type === 'textarea'" v-model="form[field.name]"
            type="textarea" rows="3" :placeholder="`请输入${field.label}`" />
          <el-select v-else-if="field.type === 'select'" v-model="form[field.name]" placeholder="请选择" style="width: 100%">
            <el-option v-for="opt in field.options" :key="opt" :label="opt" :value="opt" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" native-type="submit" :loading="loading">提交申请</el-button>
        </el-form-item>
      </el-form>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted, computed } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'

const loading = ref(false)
const error = ref('')
const submitted = ref<any>(null)
const fields = ref<any[]>([])
const form = reactive<Record<string, any>>({
  org_name: '', org_industry: '', org_size: '', contact_name: '', contact_phone: '', description: '',
})

const visibleFields = computed(() => fields.value.filter(f => f.enabled !== false))

onMounted(async () => {
  try {
    const res = await axios.get('/api/v1/public/apply-fields')
    fields.value = res.data.data?.fields || []
  } catch {}
})

const handleApply = async () => {
  loading.value = true
  error.value = ''
  try {
    const res = await axios.post('/api/v1/operator/apply', {
      org_name: form.org_name,
      org_industry: form.org_industry || undefined,
      org_size: form.org_size || undefined,
      contact_info: { name: form.contact_name, phone: form.contact_phone },
    })
    if (res.data.success) {
      submitted.value = res.data.data.application
      ElMessage.success('申请已提交')
    } else {
      error.value = res.data.message || '提交失败'
    }
  } catch (e: any) {
    error.value = e.response?.data?.message || '网络错误'
  } finally {
    loading.value = false
  }
}
</script>
