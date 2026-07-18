<template>
  <div class="container">
    <div class="card">
      <h2>申请进度查询</h2>
      <div v-if="loading" class="msg msg-info">查询中...</div>
      <div v-if="error" class="msg msg-error">{{ error }}</div>
      <div v-if="application">
        <div style="margin-bottom: 16px;">
          <strong>申请编号：</strong>{{ application.code }}
          <br />
          <strong>组织名称：</strong>{{ application.org_name }}
          <br />
          <strong>当前状态：</strong>
          <span :style="{ color: statusColor }">{{ statusLabel }}</span>
        </div>
        <div v-if="application.review_notes" class="msg msg-info">
          <strong>审批备注：</strong>{{ application.review_notes }}
        </div>
        <div class="timeline">
          <div v-for="(item, i) in application.timeline" :key="i" class="timeline-item">
            <div class="timeline-dot" :class="item.completed ? 'completed' : 'pending'"></div>
            <div>
              <div style="font-weight: 500;">{{ item.label }}</div>
              <div style="font-size: 12px; color: #909399;">{{ item.time ? formatDate(item.time) : '等待中' }}</div>
            </div>
          </div>
        </div>
      </div>
      <div class="text-center mt-16">
        <router-link to="/">返回首页</router-link>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute } from 'vue-router'

const props = defineProps<{ code: string }>()
const route = useRoute()
const loading = ref(true)
const error = ref('')
const application = ref<any>(null)

const statusLabel = computed(() => {
  const map: Record<string, string> = {
    submitted: '已提交', under_review: '审核中', approved: '已通过', rejected: '已拒绝',
  }
  return map[application.value?.status] || application.value?.status
})

const statusColor = computed(() => {
  const map: Record<string, string> = {
    submitted: '#e6a23c', under_review: '#409eff', approved: '#67c23a', rejected: '#f56c6c',
  }
  return map[application.value?.status] || '#909399'
})

function formatDate(iso: string) {
  return new Date(iso).toLocaleString('zh-CN')
}

onMounted(async () => {
  const code = props.code || (route.params.code as string)
  try {
    const res = await fetch(`/api/v1/public/apply/${code}`)
    const data = await res.json()
    if (data.success) application.value = data.data
    else error.value = data.message || '查询失败'
  } catch {
    error.value = '网络错误'
  } finally {
    loading.value = false
  }
})
</script>
