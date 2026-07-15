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
            <td><span v-for="e in (w.events || []).slice(0,3)" :key="e" class="badge badge-info">{{ e }}</span><span v-if="(w.events||[]).length > 3" class="badge badge-info">+{{ (w.events||[]).length - 3 }}</span></td>
            <td><span :class="['badge', w.is_active !== false ? 'badge-success' : 'badge-danger']">{{ w.is_active !== false ? '活跃' : '禁用' }}</span></td>
            <td>
              <button class="link-btn" @click="testWebhook(w)">测试</button>
              <button class="link-btn" @click="openEdit(w)">编辑</button>
              <button class="link-btn danger" @click="handleDelete(w)">删除</button>
            </td>
          </tr>
          <tr v-if="webhooks.length === 0"><td colspan="5" class="empty-row">暂无 Webhooks</td></tr>
        </tbody>
      </table>
    </div>

    <div class="modal-backdrop" v-if="dialogVisible" @click="dialogVisible = false">
      <div class="modal-content" @click.stop>
        <h3>{{ isEdit ? '编辑 Webhook' : '创建 Webhook' }}</h3>
        <form @submit.prevent="handleSubmit">
          <div class="form-group"><label>URL</label><input v-model="form.url" type="url" required placeholder="https://example.com/webhook" /></div>
          <div class="form-group"><label>事件（逗号分隔）</label><input v-model="eventsInput" required placeholder="tenant.created,user.registered" /></div>
          <div class="form-group"><label>描述</label><input v-model="form.description" /></div>
          <div class="form-actions"><button type="button" @click="dialogVisible = false">取消</button><button type="submit" class="primary-btn">确定</button></div>
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

const API = '/v1/admin/webhooks'
const webhooks = ref<any[]>([])
const dialogVisible = ref(false)
const isEdit = ref(false)
const editId = ref<string|number>('')
const form = ref({ url: '', events: [] as string[], description: '' })
const eventsInput = ref('')
const testResult = ref<any>(null)

const fetchWebhooks = async () => { try { const r = await axios.get(API); webhooks.value = r.data.data || [] } catch {} }

const openCreate = () => { isEdit.value = false; form.value = { url: '', events: [], description: '' }; eventsInput.value = ''; dialogVisible.value = true }
const openEdit = (w: any) => { isEdit.value = true; editId.value = w.webhook_id ?? w.id; form.value = { url: w.url, events: w.events || [], description: w.description || '' }; eventsInput.value = (w.events || []).join(','); dialogVisible.value = true }

const handleSubmit = async () => {
  const payload = { ...form.value, events: eventsInput.value.split(',').map(s => s.trim()).filter(Boolean) }
  try {
    if (isEdit.value) await axios.put(`${API}/${editId.value}`, payload)
    else await axios.post(API, payload)
    dialogVisible.value = false; await fetchWebhooks()
  } catch {}
}

const handleDelete = async (w: any) => {
  if (!confirm('确定删除该 Webhook？')) return
  try { await axios.delete(`${API}/${w.webhook_id ?? w.id}`); await fetchWebhooks() } catch {}
}

const testWebhook = async (w: any) => {
  try { const r = await axios.post(`${API}/${w.webhook_id ?? w.id}/test`); testResult.value = r.data } catch (e: any) { testResult.value = { error: e.response?.data?.message || e.message } }
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
.badge-info { background: #eee; color: #666; }
.badge-success { background: #e6ffed; color: #52c41a; }
.badge-danger { background: #fff1f0; color: #f5222d; }
.link-btn { background: none; border: none; color: var(--primary-color, #409eff); cursor: pointer; font-size: 13px; padding: 0 4px; }
.link-btn.danger { color: #f5222d; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-content { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; min-width: 420px; max-width: 600px; }
.modal-content h3 { margin: 0 0 20px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; box-sizing: border-box; }
.form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
.form-actions button { padding: 8px 16px; border-radius: 6px; border: 1px solid var(--border-color, #ddd); background: #fff; cursor: pointer; }
.test-output { background: #f5f5f5; padding: 12px; border-radius: 6px; font-size: 12px; overflow-x: auto; max-height: 300px; }
</style>
