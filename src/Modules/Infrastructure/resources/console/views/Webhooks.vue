<template>
  <div class="page">
    <div class="page-header"><h2>Webhooks</h2><button class="primary-btn" @click="openCreate">+ 创建 Webhook</button></div>
    <div class="panel">
      <table class="data-table">
        <thead><tr><th>ID</th><th>URL</th><th>事件</th><th>状态</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="w in webhooks" :key="w.webhook_id ?? w.id">
            <td>{{ w.webhook_id ?? w.id }}</td>
            <td class="url-cell">{{ w.url }}</td>
            <td><span v-for="e in (w.events || []).slice(0,3)" :key="e" class="badge badge-info">{{ e }}</span></td>
            <td><span :class="['badge', w.is_active !== false ? 'badge-success' : 'badge-danger']">{{ w.is_active !== false ? '活跃' : '禁用' }}</span></td>
            <td>
              <button class="link-btn" @click="testWebhook(w)">测试</button>
              <button class="link-btn danger" @click="handleDelete(w)">删除</button>
            </td>
          </tr>
          <tr v-if="webhooks.length === 0"><td colspan="5" class="empty-row">暂无 Webhooks</td></tr>
        </tbody>
      </table>
    </div>

    <div class="modal-backdrop" v-if="dialogVisible" @click="dialogVisible = false">
      <div class="modal-content" @click.stop>
        <h3>创建 Webhook</h3>
        <form @submit.prevent="handleSubmit">
          <div class="form-group"><label>URL</label><input v-model="form.url" type="url" required placeholder="https://example.com/webhook" /></div>
          <div class="form-group"><label>事件（逗号分隔）</label><input v-model="eventsInput" required placeholder="tenant.created,user.registered" /></div>
          <div class="form-group"><label>描述</label><input v-model="form.description" /></div>
          <div class="form-actions"><button type="button" @click="dialogVisible = false">取消</button><button type="submit" class="primary-btn">创建</button></div>
        </form>
      </div>
    </div>

    <div class="modal-backdrop" v-if="testResult" @click="testResult = null">
      <div class="modal-content" @click.stop>
        <h3>测试结果</h3>
        <pre class="test-output">{{ JSON.stringify(testResult, null, 2) }}</pre>
        <div class="form-actions"><button @click="testResult = null">关闭</button></div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { useUserStore } from '@stores/user'

const userStore = useUserStore()
const apiBase = () => `/api/v1/tenants/${userStore.tenantId}/webhooks`
const webhooks = ref<any[]>([])
const dialogVisible = ref(false)
const form = ref({ url: '', events: [] as string[], description: '' })
const eventsInput = ref('')
const testResult = ref<any>(null)

const fetchWebhooks = async () => { try { const r = await axios.get(apiBase()); webhooks.value = r.data.data || [] } catch {} }

const openCreate = () => { form.value = { url: '', events: [], description: '' }; eventsInput.value = ''; dialogVisible.value = true }

const handleSubmit = async () => {
  const payload = { ...form.value, events: eventsInput.value.split(',').map(s => s.trim()).filter(Boolean) }
  try { await axios.post(apiBase(), payload); dialogVisible.value = false; await fetchWebhooks() } catch {}
}

const handleDelete = async (w: any) => {
  if (!confirm('确定删除该 Webhook？')) return
  try { await axios.delete(`${apiBase()}/${w.webhook_id ?? w.id}`); await fetchWebhooks() } catch {}
}

const testWebhook = async (w: any) => {
  try { const r = await axios.post(`${apiBase()}/${w.webhook_id ?? w.id}/test`); testResult.value = r.data } catch (e: any) { testResult.value = { error: e.response?.data?.message || e.message } }
}

onMounted(fetchWebhooks)
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.primary-btn { padding: 8px 16px; background: var(--primary-color, #409eff); color: #fff; border: none; border-radius: 6px; cursor: pointer; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.url-cell { max-width: 300px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.empty-row { text-align: center; color: var(--text-color-secondary, #999); padding: 24px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; margin-right: 4px; }
.badge-info { background: var(--badge-info-bg); color: var(--badge-info-fg); }
.badge-success { background: var(--badge-success-bg); color: var(--badge-success-fg); }
.badge-danger { background: var(--badge-danger-bg); color: var(--badge-danger-fg); }
.link-btn { background: none; border: none; color: var(--link-color); cursor: pointer; font-size: 13px; padding: 0 4px; }
.link-btn.danger { color: var(--link-danger); }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-content { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; min-width: 420px; }
.modal-content h3 { margin: 0 0 20px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; box-sizing: border-box; }
.form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
.form-actions button { padding: 8px 16px; border-radius: 6px; border: 1px solid var(--border-color, #ddd); background: #fff; cursor: pointer; }
.test-output { background: var(--fill-color, #f5f5f5); padding: 12px; border-radius: 6px; font-size: 12px; overflow-x: auto; max-height: 300px; }
</style>
