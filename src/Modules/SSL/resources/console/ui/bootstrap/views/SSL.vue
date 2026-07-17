<template>
  <div class="page">
    <div class="page-header"><h2>SSL 证书</h2></div>
    <div class="panel">
      <div v-if="certInfo" class="cert-info">
        <div class="info-row"><span class="label">证书状态：</span><span :class="['badge', certInfo.has_certificate ? (certInfo.is_expired ? 'badge-danger' : 'badge-success') : 'badge-info']">{{ certInfo.has_certificate ? (certInfo.is_expired ? '已过期' : '有效') : '未上传' }}</span></div>
        <div class="info-row"><span class="label">上传时间：</span>{{ certInfo.uploaded_at || '-' }}</div>
        <div class="info-row"><span class="label">过期时间：</span>{{ certInfo.expires_at || '-' }}</div>
        <div v-if="certInfo.expires_soon" class="info-row warning">⚠ 证书即将过期（30天内）</div>
      </div>
      <div v-else class="empty-row">加载中...</div>

      <div class="actions-bar">
        <button class="primary-btn" @click="showUpload = true">{{ certInfo?.has_certificate ? '更换证书' : '上传证书' }}</button>
        <button v-if="certInfo?.has_certificate" class="danger-btn" @click="handleDelete">删除证书</button>
      </div>
    </div>

    <div class="modal-backdrop" v-if="showUpload" @click="showUpload = false">
      <div class="modal-content" @click.stop>
        <h3>上传 SSL 证书</h3>
        <form @submit.prevent="handleUpload">
          <div class="form-group"><label>证书内容（PEM）</label><textarea v-model="uploadForm.certificate" rows="6" required placeholder="-----BEGIN CERTIFICATE-----"></textarea></div>
          <div class="form-group"><label>私钥（PEM）</label><textarea v-model="uploadForm.private_key" rows="6" required placeholder="-----BEGIN PRIVATE KEY-----"></textarea></div>
          <div class="form-actions"><button type="button" @click="showUpload = false">取消</button><button type="submit" class="primary-btn">上传</button></div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import axios from 'axios'
import { useUserStore } from '@stores/user'

const userStore = useUserStore()
const apiBase = computed(() => `/api/v1/tenants/${userStore.tenantId}/ssl`)
const certInfo = ref<any>(null)
const showUpload = ref(false)
const uploadForm = ref({ certificate: '', private_key: '' })

const fetchCert = async () => { try { const r = await axios.get(apiBase.value); certInfo.value = r.data.data || r.data } catch { certInfo.value = null } }

const handleUpload = async () => {
  try { await axios.post(apiBase.value, uploadForm.value); showUpload.value = false; uploadForm.value = { certificate: '', private_key: '' }; await fetchCert() } catch {}
}

const handleDelete = async () => {
  if (!confirm('确定删除 SSL 证书？')) return
  try { await axios.delete(apiBase.value); await fetchCert() } catch {}
}

onMounted(fetchCert)
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.cert-info { margin-bottom: 20px; }
.info-row { padding: 8px 0; font-size: 14px; border-bottom: 1px solid var(--border-color, #eee); }
.info-row .label { color: var(--text-color-secondary, #666); margin-right: 8px; }
.info-row.warning { color: #fa8c16; }
.empty-row { text-align: center; color: var(--text-color-secondary, #999); padding: 24px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-info { background: var(--badge-info-bg); color: var(--badge-info-fg); }
.badge-success { background: var(--badge-success-bg); color: var(--badge-success-fg); }
.badge-danger { background: var(--badge-danger-bg); color: var(--badge-danger-fg); }
.actions-bar { display: flex; gap: 8px; }
.primary-btn { padding: 8px 16px; background: var(--primary-color, #409eff); color: #fff; border: none; border-radius: 6px; cursor: pointer; }
.danger-btn { padding: 8px 16px; background: #f5222d; color: #fff; border: none; border-radius: 6px; cursor: pointer; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-content { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; min-width: 500px; }
.modal-content h3 { margin: 0 0 20px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group textarea { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; box-sizing: border-box; font-family: monospace; font-size: 12px; resize: vertical; }
.form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
.form-actions button { padding: 8px 16px; border-radius: 6px; border: 1px solid var(--border-color, #ddd); background: #fff; cursor: pointer; }
</style>
