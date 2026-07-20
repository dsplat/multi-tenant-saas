<template>
  <div class="apply-page">
    <div class="apply-shell">
      <!-- 左侧：品牌与说明 -->
      <aside class="apply-aside">
        <div class="brand">
          <el-icon :size="30" color="#fff"><OfficeBuilding /></el-icon>
          <span class="brand-name">SCRM Platform</span>
        </div>
        <h1 class="aside-title">创建你的团队</h1>
        <p class="aside-desc">
          你还没有加入任何团队。提交申请后，平台管理员将为你开通专属工作空间，
          审核通过后即可开始使用完整的 SCRM 能力。
        </p>
        <ul class="aside-steps">
          <li><span class="step-dot">1</span>填写团队基本信息</li>
          <li><span class="step-dot">2</span>平台管理员审核</li>
          <li><span class="step-dot">3</span>开通专属工作空间</li>
        </ul>
        <div class="aside-foot">审核结果将通过邮件通知你</div>
      </aside>

      <!-- 右侧：表单 / 申请记录 -->
      <main class="apply-main">
        <div class="main-head">
          <h2>{{ hasPending ? '申请进度' : '申请创建团队' }}</h2>
          <el-button v-if="applications.length" link type="primary" @click="showForm = !showForm">
            {{ showForm ? '查看申请记录' : '发起新申请' }}
          </el-button>
        </div>

        <el-alert
          v-if="message"
          :title="message"
          :type="messageType"
          show-icon
          :closable="false"
          style="margin-bottom: 20px"
        />

        <!-- 申请表单 -->
        <el-form
          v-if="showForm"
          :model="form"
          label-position="top"
          class="apply-form"
          @submit.prevent="submit"
        >
          <el-form-item label="团队 / 组织名称" required>
            <el-input v-model="form.org_name" placeholder="例如：示例科技有限公司" size="large" maxlength="255" />
          </el-form-item>

          <el-form-item label="所属行业">
            <el-select v-model="form.org_industry" placeholder="请选择行业" size="large" style="width: 100%" clearable>
              <el-option v-for="ind in industries" :key="ind" :label="ind" :value="ind" />
            </el-select>
          </el-form-item>

          <el-form-item label="团队规模">
            <el-radio-group v-model="form.org_size" size="large">
              <el-radio-button v-for="s in sizes" :key="s" :value="s">{{ s }}</el-radio-button>
            </el-radio-group>
          </el-form-item>

          <el-form-item label="联系方式（选填）">
            <el-input v-model="contactPhone" placeholder="手机号 / 微信，便于管理员联系你" size="large" />
          </el-form-item>

          <el-button type="primary" size="large" :loading="submitting" style="width: 100%" @click="submit">
            {{ submitting ? '提交中...' : '提交申请' }}
          </el-button>
        </el-form>

        <!-- 申请记录 -->
        <div v-if="!showForm && applications.length" class="app-list">
          <div v-for="app in applications" :key="app.application_id" class="app-card" :class="`st-${app.status}`">
            <div class="app-card-head">
              <span class="app-org">{{ app.org_name }}</span>
              <el-tag :type="statusTag(app.status)" effect="light" round>{{ statusLabel(app.status) }}</el-tag>
            </div>
            <div class="app-meta">
              <span>申请编号：{{ app.code }}</span>
              <span>提交时间：{{ formatDate(app.created_at) }}</span>
            </div>
            <div v-if="app.review_notes" class="app-notes">审核意见：{{ app.review_notes }}</div>
          </div>
        </div>

        <el-empty v-if="!showForm && !applications.length && !loadingApps" description="暂无申请记录" :image-size="80" />
      </main>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { OfficeBuilding } from '@element-plus/icons-vue'
import axios from 'axios'
import { useUserStore } from '@stores/user'

interface Application {
  application_id: number
  code: string
  org_name: string
  org_industry?: string
  org_size?: string
  status: string
  review_notes?: string
  created_at?: string
  reviewed_at?: string
}

const userStore = useUserStore()

const industries = ['电商零售', '教育培训', '餐饮连锁', '美妆个护', '母婴亲子', '医疗健康', '金融保险', '旅游酒店', '其他']
const sizes = ['1-10 人', '11-50 人', '51-200 人', '200 人以上']

const form = reactive({ org_name: '', org_industry: '', org_size: '' })
const contactPhone = ref('')
const submitting = ref(false)
const loadingApps = ref(false)
const showForm = ref(true)
const message = ref('')
const messageType = ref<'success' | 'error' | 'info'>('info')
const applications = ref<Application[]>([])

const hasPending = computed(() =>
  applications.value.some(a => ['submitted', 'under_review'].includes(a.status))
)

const statusLabel = (s: string) =>
  ({ submitted: '待审核', under_review: '审核中', approved: '已通过', rejected: '已拒绝' }[s] || s)

const statusTag = (s: string): 'warning' | 'info' | 'success' | 'danger' =>
  ({ submitted: 'warning', under_review: 'info', approved: 'success', rejected: 'danger' }[s] || 'info')

const formatDate = (iso?: string) => (iso ? new Date(iso).toLocaleString('zh-CN') : '-')

async function loadApplications() {
  loadingApps.value = true
  try {
    const { data } = await axios.get('/api/v1/operator/applications')
    applications.value = data.data?.items || []
    // 已有待审核申请时，默认展示进度而非表单
    if (hasPending.value) showForm.value = false
  } catch {
    applications.value = []
  } finally {
    loadingApps.value = false
  }
}

async function submit() {
  if (!form.org_name.trim()) {
    message.value = '请填写团队 / 组织名称'
    messageType.value = 'error'
    return
  }
  submitting.value = true
  message.value = ''
  try {
    await axios.post('/api/v1/operator/apply', {
      org_name: form.org_name.trim(),
      org_industry: form.org_industry || null,
      org_size: form.org_size || null,
      contact_info: contactPhone.value ? { phone: contactPhone.value } : null,
    })
    message.value = '申请已提交，请等待平台管理员审核，结果将发送至你的邮箱。'
    messageType.value = 'success'
    form.org_name = ''
    form.org_industry = ''
    form.org_size = ''
    contactPhone.value = ''
    await loadApplications()
    showForm.value = false
  } catch (e: any) {
    message.value = e.response?.data?.message || '提交失败，请稍后重试'
    messageType.value = 'error'
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  if (userStore.token) {
    axios.defaults.headers.common['Authorization'] = `Bearer ${userStore.token}`
  }
  loadApplications()
})
</script>

<style scoped>
.apply-page {
  min-height: 100vh;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 40px 20px;
  background:
    radial-gradient(1000px 500px at 85% -10%, rgba(64, 158, 255, 0.14), transparent 60%),
    radial-gradient(800px 400px at -10% 110%, rgba(103, 194, 58, 0.10), transparent 60%),
    #f5f7fa;
}
.apply-shell {
  display: flex;
  width: 100%;
  max-width: 920px;
  min-height: 540px;
  border-radius: 14px;
  overflow: hidden;
  background: #fff;
  box-shadow: 0 12px 40px rgba(30, 60, 110, 0.12);
}
.apply-aside {
  flex: 0 0 320px;
  display: flex;
  flex-direction: column;
  padding: 36px 32px;
  color: #fff;
  background: linear-gradient(160deg, #1f5fbf 0%, #2f7fe0 55%, #3d9bea 100%);
}
.brand { display: flex; align-items: center; gap: 10px; font-weight: 600; }
.brand-name { font-size: 16px; letter-spacing: 0.5px; }
.aside-title { margin: 44px 0 14px; font-size: 26px; font-weight: 700; line-height: 1.3; }
.aside-desc { font-size: 14px; line-height: 1.8; opacity: 0.9; }
.aside-steps { list-style: none; padding: 0; margin: 30px 0 0; display: flex; flex-direction: column; gap: 14px; }
.aside-steps li { display: flex; align-items: center; gap: 10px; font-size: 14px; opacity: 0.95; }
.step-dot {
  display: inline-flex; align-items: center; justify-content: center;
  width: 22px; height: 22px; border-radius: 50%;
  background: rgba(255, 255, 255, 0.22); font-size: 12px; font-weight: 600;
}
.aside-foot { margin-top: auto; font-size: 12px; opacity: 0.7; }
.apply-main { flex: 1; padding: 40px 44px; overflow-y: auto; }
.main-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 24px; }
.main-head h2 { margin: 0; font-size: 20px; font-weight: 700; color: #303133; }
.apply-form { max-width: 460px; }
.app-list { display: flex; flex-direction: column; gap: 14px; }
.app-card {
  border: 1px solid #ebeef5; border-left: 4px solid #e6a23c;
  border-radius: 8px; padding: 16px 18px;
  transition: box-shadow 0.2s ease, transform 0.2s ease;
}
.app-card:hover { box-shadow: 0 6px 18px rgba(30, 60, 110, 0.10); transform: translateY(-2px); }
.app-card.st-approved { border-left-color: #67c23a; }
.app-card.st-rejected { border-left-color: #f56c6c; }
.app-card.st-under_review { border-left-color: #909399; }
.app-card-head { display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; }
.app-org { font-size: 15px; font-weight: 600; color: #303133; }
.app-meta { display: flex; gap: 20px; font-size: 12px; color: #909399; }
.app-notes { margin-top: 10px; font-size: 13px; color: #606266; background: #f8f9fb; border-radius: 6px; padding: 8px 12px; }
@media (max-width: 768px) {
  .apply-shell { flex-direction: column; }
  .apply-aside { flex: none; }
  .apply-main { padding: 28px 24px; }
}
</style>
