<template>
  <div class="domain-page">
    <div class="page-header"><h2>域名管理</h2></div>

    <div class="panel" style="margin-bottom: 16px;">
      <h3>域名列表</h3>
      <table class="data-table">
        <thead>
          <tr><th>租户ID</th><th>租户名称</th><th>自定义域名</th><th>状态</th><th>备案</th><th>SSL</th><th>操作</th></tr>
        </thead>
        <tbody>
          <tr v-for="t in tenants" :key="t.tenant_id">
            <td>{{ t.tenant_id }}</td>
            <td>{{ t.name }}</td>
            <td>{{ t.custom_domain || '-' }}</td>
            <td>
              <span :class="['badge', domainStatusClass(t.domain_status)]">
                {{ domainStatusLabel(t.domain_status) }}
              </span>
            </td>
            <td>
              <span :class="['badge', t.icp_verified ? 'badge-success' : 'badge-info']">
                {{ t.icp_verified ? '已备案' : '未验证' }}
              </span>
            </td>
            <td>
              <span :class="['badge', t.has_ssl ? 'badge-success' : 'badge-info']">
                {{ t.has_ssl ? '已配置' : '未配置' }}
              </span>
            </td>
            <td>
              <button class="link-btn" @click="handleApprove(t)" v-if="t.domain_status === 'pending'">审核</button>
              <button class="link-btn" @click="handleViewSsl(t)">SSL</button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- 审核对话框 -->
    <div class="modal-backdrop" v-if="showApprove" @click="showApprove = false">
      <div class="modal-content" @click.stop>
        <h3>域名审核 - {{ currentTenant?.name }}</h3>
        <div class="info-row"><span>域名</span><span>{{ currentTenant?.custom_domain }}</span></div>
        <div class="form-actions">
          <button class="btn-danger" @click="handleReject">拒绝</button>
          <button class="primary-btn" @click="handleApproveConfirm">通过</button>
        </div>
      </div>
    </div>

    <!-- SSL 对话框 -->
    <div class="modal-backdrop" v-if="showSsl" @click="showSsl = false">
      <div class="modal-content" @click.stop>
        <h3>SSL 证书 - {{ currentTenant?.custom_domain }}</h3>
        <div class="info-row"><span>证书状态</span><span>{{ sslInfo.has_certificate ? '已配置' : '未配置' }}</span></div>
        <div class="info-row" v-if="sslInfo.expires_at"><span>过期时间</span><span>{{ sslInfo.expires_at }}</span></div>
        <div class="info-row" v-if="sslInfo.is_expired"><span>状态</span><span class="badge badge-danger">已过期</span></div>
        <form @submit.prevent="handleUploadSsl" style="margin-top: 16px;">
          <div class="form-group">
            <label>证书 (PEM)</label>
            <textarea v-model="sslForm.certificate" rows="4" placeholder="-----BEGIN CERTIFICATE-----"></textarea>
          </div>
          <div class="form-group">
            <label>私钥 (PEM)</label>
            <textarea v-model="sslForm.private_key" rows="4" placeholder="-----BEGIN PRIVATE KEY-----"></textarea>
          </div>
          <div class="form-actions">
            <button type="button" class="btn-danger" @click="handleDeleteSsl" v-if="sslInfo.has_certificate">删除证书</button>
            <button type="submit" class="primary-btn">上传证书</button>
          </div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'

const tenants = ref<any[]>([])
const showApprove = ref(false)
const showSsl = ref(false)
const currentTenant = ref<any>(null)
const sslInfo = ref<any>({})
const sslForm = reactive({ certificate: '', private_key: '' })

const domainStatusClass = (s: string) => ({ approved: 'badge-success', pending: 'badge-warning', rejected: 'badge-danger' }[s] || 'badge-info')
const domainStatusLabel = (s: string) => ({ approved: '已通过', pending: '待审核', rejected: '已拒绝' }[s] || s)

const fetchTenants = async () => {
  try {
    const res = await axios.get('/api/v1/tenants')
    const list = res.data.data || []
    // 为每个租户获取域名信息
    for (const t of list) {
      try {
        const domainRes = await axios.get(`/api/v1/tenants/${t.tenant_id}/domain`)
        Object.assign(t, domainRes.data.data)
      } catch {
        t.domain_status = 'pending'
        t.icp_verified = false
      }
      try {
        const sslRes = await axios.get(`/api/v1/tenants/${t.tenant_id}/ssl`)
        t.has_ssl = sslRes.data.data?.has_certificate || false
      } catch {
        t.has_ssl = false
      }
    }
    tenants.value = list
  } catch {
    tenants.value = []
  }
}

const handleApprove = (t: any) => {
  currentTenant.value = t
  showApprove.value = true
}

const handleApproveConfirm = async () => {
  await axios.post(`/api/v1/tenants/${currentTenant.value.tenant_id}/domain/approve`)
  showApprove.value = false
  fetchTenants()
}

const handleReject = async () => {
  await axios.post(`/api/v1/tenants/${currentTenant.value.tenant_id}/domain/reject`)
  showApprove.value = false
  fetchTenants()
}

const handleViewSsl = async (t: any) => {
  currentTenant.value = t
  try {
    const res = await axios.get(`/api/v1/tenants/${t.tenant_id}/ssl`)
    sslInfo.value = res.data.data || {}
  } catch {
    sslInfo.value = {}
  }
  sslForm.certificate = ''
  sslForm.private_key = ''
  showSsl.value = true
}

const handleUploadSsl = async () => {
  await axios.post(`/api/v1/tenants/${currentTenant.value.tenant_id}/ssl`, sslForm)
  showSsl.value = false
  fetchTenants()
}

const handleDeleteSsl = async () => {
  await axios.delete(`/api/v1/tenants/${currentTenant.value.tenant_id}/ssl`)
  showSsl.value = false
  fetchTenants()
}

onMounted(fetchTenants)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.panel h3 { margin: 0 0 16px; font-size: 15px; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-success { background: var(--badge-success-bg); color: var(--badge-success-fg); }
.badge-warning { background: var(--badge-warning-bg); color: var(--badge-warning-fg); }
.badge-danger { background: var(--badge-danger-bg); color: var(--badge-danger-fg); }
.badge-info { background: var(--badge-info-bg); color: var(--badge-info-fg); }
.link-btn { background: none; border: none; color: var(--link-color); cursor: pointer; font-size: 13px; margin-right: 8px; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 3000; }
.modal-content { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; width: 500px; max-height: 80vh; overflow-y: auto; }
.modal-content h3 { margin: 0 0 16px; }
.info-row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.info-row span:first-child { color: var(--text-color-secondary, #999); }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group textarea { width: 100%; padding: 8px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 12px; font-family: monospace; box-sizing: border-box; resize: vertical; }
.form-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 16px; }
.primary-btn { padding: 8px 16px; border: none; border-radius: 6px; background: var(--primary-color, #409eff); color: #fff; cursor: pointer; font-size: 13px; }
.btn-danger { padding: 8px 16px; border: none; border-radius: 6px; background: #f56c6c; color: #fff; cursor: pointer; font-size: 13px; }
</style>
