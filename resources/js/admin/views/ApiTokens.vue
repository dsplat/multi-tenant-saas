<template>
  <div class="tokens-page">
    <div class="page-header"><h2>API Token 管理</h2></div>

    <div class="panel">
      <div class="tenant-select">
        <label>选择租户：</label>
        <select v-model="selectedTenantId" @change="loadTokens">
          <option value="">请选择</option>
          <option v-for="t in tenants" :key="t.tenant_id" :value="t.tenant_id">{{ t.name }}</option>
        </select>
      </div>

      <div v-if="selectedTenantId">
        <div class="toolbar">
          <button class="primary-btn" @click="showCreate = true">创建 Token</button>
        </div>

        <table class="data-table">
          <thead>
            <tr><th>名称</th><th>创建时间</th><th>最后使用</th><th>状态</th><th>操作</th></tr>
          </thead>
          <tbody>
            <tr v-for="token in tokens" :key="token.id">
              <td>{{ token.name }}</td>
              <td>{{ token.created_at }}</td>
              <td>{{ token.last_used_at || '从未使用' }}</td>
              <td>
                <span :class="['badge', token.is_active ? 'badge-success' : 'badge-info']">
                  {{ token.is_active ? '有效' : '已禁用' }}
                </span>
              </td>
              <td>
                <button class="link-btn btn-danger-text" @click="handleDelete(token)">删除</button>
              </td>
            </tr>
            <tr v-if="tokens.length === 0">
              <td colspan="5" class="empty-row">暂无 Token</td>
            </tr>
          </tbody>
        </table>
      </div>
    </div>

    <div class="modal-backdrop" v-if="showCreate" @click="showCreate = false">
      <div class="modal-content" @click.stop>
        <h3>创建 API Token</h3>
        <form @submit.prevent="handleCreate">
          <div class="form-group">
            <label>名称</label>
            <input v-model="createForm.name" required placeholder="输入 Token 名称" />
          </div>
          <div class="form-actions">
            <button type="button" class="btn-cancel" @click="showCreate = false">取消</button>
            <button type="submit" class="primary-btn">创建</button>
          </div>
        </form>
      </div>
    </div>

    <div class="modal-backdrop" v-if="showTokenResult" @click="showTokenResult = false">
      <div class="modal-content" @click.stop>
        <h3>Token 已创建</h3>
        <p class="token-warning">请立即复制此 Token，关闭后将无法再次查看：</p>
        <div class="token-display">{{ createdToken }}</div>
        <div class="form-actions">
          <button class="primary-btn" @click="handleCopy">复制</button>
          <button class="btn-cancel" @click="showTokenResult = false">关闭</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'

const tenants = ref<any[]>([])
const selectedTenantId = ref('')
const tokens = ref<any[]>([])
const showCreate = ref(false)
const showTokenResult = ref(false)
const createdToken = ref('')
const createForm = reactive({ name: '' })

const fetchTenants = async () => {
  try {
    const res = await axios.get('/api/v1/tenants')
    tenants.value = res.data.data || []
  } catch {}
}

const loadTokens = async () => {
  if (!selectedTenantId.value) return
  try {
    const res = await axios.get(`/api/v1/tenants/${selectedTenantId.value}/api-tokens`)
    tokens.value = res.data.data || []
  } catch {
    tokens.value = []
  }
}

const handleCreate = async () => {
  try {
    const res = await axios.post(`/api/v1/tenants/${selectedTenantId.value}/api-tokens`, createForm)
    showCreate.value = false
    createdToken.value = res.data.data?.token || ''
    showTokenResult.value = true
    createForm.name = ''
    loadTokens()
  } catch (e: any) {
    alert(e.response?.data?.message || '创建失败')
  }
}

const handleDelete = async (token: any) => {
  if (!confirm(`确定删除 Token「${token.name}」？`)) return
  try {
    await axios.delete(`/api/v1/tenants/${selectedTenantId.value}/api-tokens/${token.id}`)
    loadTokens()
  } catch (e: any) {
    alert(e.response?.data?.message || '删除失败')
  }
}

const handleCopy = () => {
  navigator.clipboard.writeText(createdToken.value)
  alert('已复制到剪贴板')
}

onMounted(fetchTenants)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.tenant-select { display: flex; align-items: center; gap: 12px; margin-bottom: 24px; }
.tenant-select label { font-size: 14px; color: var(--text-color-secondary, #666); }
.tenant-select select { padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; min-width: 200px; }
.toolbar { margin-bottom: 16px; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.empty-row { text-align: center; color: var(--text-color-secondary, #999); padding: 24px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-success { background: var(--badge-success-bg); color: var(--badge-success-fg); }
.badge-info { background: var(--badge-info-bg); color: var(--badge-info-fg); }
.link-btn { background: none; border: none; color: var(--link-color); cursor: pointer; font-size: 13px; }
.btn-danger-text { color: var(--link-danger); }
.primary-btn { padding: 8px 16px; border: none; border-radius: 6px; background: var(--primary-color, #409eff); color: #fff; cursor: pointer; font-size: 13px; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 3000; }
.modal-content { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; width: 480px; }
.modal-content h3 { margin: 0 0 16px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 13px; box-sizing: border-box; }
.form-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 16px; }
.btn-cancel { padding: 8px 16px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; background: #fff; cursor: pointer; font-size: 13px; }
.token-warning { font-size: 13px; color: #e65100; margin-bottom: 8px; }
.token-display { padding: 12px; background: var(--fill-color, #f5f5f5); border-radius: 6px; font-family: monospace; font-size: 13px; word-break: break-all; margin-bottom: 8px; }
</style>
