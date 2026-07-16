<template>
  <div class="tokens-page">
    <div class="page-header">
      <h2>API Token</h2>
      <button class="primary-btn" @click="showCreate = true">+ 创建 Token</button>
    </div>

    <div class="panel">
      <p class="hint">API Token 用于第三方系统调用接口，请妥善保管。</p>

      <table class="data-table">
        <thead>
          <tr><th>名称</th><th>创建时间</th><th>最后使用</th><th>操作</th></tr>
        </thead>
        <tbody>
          <tr v-for="t in tokens" :key="t.id">
            <td>{{ t.name }}</td>
            <td>{{ t.created_at }}</td>
            <td>{{ t.last_used_at || '-' }}</td>
            <td>
              <button class="link-btn danger" @click="handleDelete(t)">删除</button>
            </td>
          </tr>
          <tr v-if="tokens.length === 0">
            <td colspan="4" class="empty-row">暂无 Token</td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- 创建对话框 -->
    <div class="modal-backdrop" v-if="showCreate" @click="showCreate = false">
      <div class="modal-content" @click.stop>
        <h3>创建 API Token</h3>
        <form @submit.prevent="handleCreate">
          <div class="form-group">
            <label>名称</label>
            <input v-model="createForm.name" placeholder="如：订单系统对接" required />
          </div>
          <div class="form-actions">
            <button type="button" @click="showCreate = false">取消</button>
            <button type="submit" class="primary-btn" :disabled="creating">
              {{ creating ? '创建中...' : '创建' }}
            </button>
          </div>
        </form>
      </div>
    </div>

    <!-- Token 显示对话框 -->
    <div class="modal-backdrop" v-if="showToken" @click="showToken = false">
      <div class="modal-content" @click.stop>
        <h3>Token 已创建</h3>
        <div class="token-display">
          <p class="warn">请立即复制保存，关闭后无法再次查看！</p>
          <div class="token-value">
            <code>{{ newToken }}</code>
            <button class="copy-btn" @click="handleCopy">复制</button>
          </div>
        </div>
        <div class="form-actions">
          <button class="primary-btn" @click="showToken = false">我已保存</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'

const tokens = ref<any[]>([])
const showCreate = ref(false)
const showToken = ref(false)
const creating = ref(false)
const newToken = ref('')
const createForm = reactive({ name: '' })

const getTenantId = () => {
  try { return JSON.parse(localStorage.getItem('console_user') || '{}').tenant_id } catch { return null }
}

const fetchTokens = async () => {
  const tenantId = getTenantId()
  if (!tenantId) return
  try {
    const res = await axios.get(`/api/v1/tenants/${tenantId}/api-tokens`)
    tokens.value = res.data.data || []
  } catch {}
}

const handleCreate = async () => {
  const tenantId = getTenantId()
  if (!tenantId) return
  creating.value = true
  try {
    const res = await axios.post(`/api/v1/tenants/${tenantId}/api-tokens`, createForm)
    newToken.value = res.data.data?.token || ''
    showCreate.value = false
    showToken.value = true
    createForm.name = ''
    fetchTokens()
  } catch (e: any) {
    alert(e.response?.data?.message || '创建失败')
  } finally {
    creating.value = false
  }
}

const handleDelete = async (t: any) => {
  if (!confirm(`确定删除 Token "${t.name}"？`)) return
  const tenantId = getTenantId()
  if (!tenantId) return
  try {
    await axios.delete(`/api/v1/tenants/${tenantId}/api-tokens/${t.id}`)
    fetchTokens()
  } catch {
    alert('删除失败')
  }
}

const handleCopy = () => {
  navigator.clipboard?.writeText(newToken.value)
  alert('已复制')
}

onMounted(fetchTokens)
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.primary-btn { padding: 8px 16px; border: none; border-radius: 6px; background: var(--primary-color, #409eff); color: #fff; cursor: pointer; font-size: 13px; }
.primary-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.hint { color: var(--text-color-secondary, #999); font-size: 13px; margin: 0 0 16px; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.empty-row { text-align: center; color: var(--text-color-secondary, #999); padding: 24px; }
.link-btn { background: none; border: none; cursor: pointer; font-size: 13px; color: var(--link-color); }
.link-btn.danger { color: var(--link-danger); }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 3000; }
.modal-content { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; width: 460px; }
.modal-content h3 { margin: 0 0 16px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 13px; box-sizing: border-box; }
.form-actions { display: flex; justify-content: flex-end; gap: 12px; margin-top: 16px; }
.form-actions button { padding: 8px 16px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; cursor: pointer; }
.token-display { margin: 16px 0; }
.warn { color: #e65100; font-size: 13px; margin: 0 0 12px; }
.token-value { display: flex; gap: 8px; align-items: center; }
.token-value code { flex: 1; padding: 10px; background: var(--fill-color, #f5f5f5); border-radius: 6px; font-size: 12px; word-break: break-all; }
.copy-btn { padding: 6px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; background: var(--bg-color, #fff); cursor: pointer; font-size: 12px; white-space: nowrap; }
</style>
