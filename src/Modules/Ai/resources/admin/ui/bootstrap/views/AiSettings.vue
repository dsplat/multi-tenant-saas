<template>
  <div class="settings-page">
    <div class="page-header"><h2>AI 配置</h2></div>

    <div class="tabs">
      <button v-for="t in tabs" :key="t.key" :class="['tab-btn', { active: activeTab === t.key }]" @click="activeTab = t.key">{{ t.label }}</button>
    </div>

    <div class="panel">
      <!-- 默认模型 -->
      <form v-if="activeTab === 'defaults'" @submit.prevent="saveDefaults">
        <div class="form-group"><label>默认对话模型</label><input v-model="defaults.default_chat_model" placeholder="如 qwen3.7-plus" /></div>
        <div class="form-group"><label>默认补全模型</label><input v-model="defaults.default_completion_model" /></div>
        <div class="form-group"><label>默认向量模型</label><input v-model="defaults.default_embedding_model" /></div>
        <div class="form-group"><label>默认提供商</label><input v-model="defaults.default_provider" placeholder="如 bailian" /></div>
        <p class="hint">保存后立即生效（60 秒缓存）；留空则回退 .env 引导配置</p>
        <button type="submit" class="primary-btn" :disabled="saving">保存</button>
      </form>

      <!-- 模型别名 -->
      <div v-if="activeTab === 'aliases'">
        <div class="form-row" style="margin-bottom: 12px">
          <input v-model="aliasKeyword" placeholder="搜索别名/模型名" style="width: 220px" @keyup.enter="fetchAliases" />
          <button class="primary-btn" type="button" @click="fetchAliases">搜索</button>
        </div>
        <table class="data-table">
          <thead><tr><th>别名</th><th>实际模型</th><th>提供商</th><th>类型</th><th>状态</th><th>操作</th></tr></thead>
          <tbody>
            <tr v-for="a in aliases" :key="a.alias_id">
              <td><strong>{{ a.alias }}</strong></td>
              <td>{{ a.actual_model }}</td>
              <td>{{ a.provider || '(默认)' }}</td>
              <td>{{ a.type }}</td>
              <td><span :class="['badge', a.is_active ? 'badge-success' : 'badge-danger']">{{ a.is_active ? '启用' : '停用' }}</span></td>
              <td><button class="link-btn" @click="removeAlias(a)">删除</button></td>
            </tr>
            <tr v-if="aliases.length === 0"><td colspan="6" class="empty-row">暂无别名</td></tr>
          </tbody>
        </table>
        <form style="margin-top: 16px; border-top: 1px solid #eee; padding-top: 12px" @submit.prevent="saveAlias">
          <h4>新增别名</h4>
          <div class="form-row">
            <div class="form-group"><label>别名</label><input v-model="newAlias.alias" /></div>
            <div class="form-group"><label>实际模型</label><input v-model="newAlias.actual_model" /></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label>提供商（留空=默认）</label><input v-model="newAlias.provider" /></div>
            <div class="form-group">
              <label>类型</label>
              <select v-model="newAlias.type"><option value="text">text</option><option value="image">image</option><option value="video">video</option></select>
            </div>
          </div>
          <button type="submit" class="primary-btn" :disabled="saving">新增</button>
        </form>
      </div>

      <!-- 模型目录 -->
      <div v-if="activeTab === 'catalog'">
        <button class="primary-btn" :disabled="syncing" @click="syncCatalog()">{{ syncing ? '同步中...' : '同步全部目录' }}</button>
        <p class="hint">从各 provider 的 /models 端点拉取真实模型清单（缓存 1 天）</p>
        <div v-for="(info, provider) in catalog" :key="provider" style="margin-top: 12px">
          <strong>{{ provider }}</strong>
          <span :class="['badge', info.cached ? 'badge-success' : 'badge-danger']" style="margin-left: 8px">
            {{ info.cached ? `${info.count} 个模型` : '无缓存' }}
          </span>
          <button class="link-btn" style="margin-left: 8px" :disabled="syncing" @click="syncCatalog(String(provider))">刷新</button>
          <p v-if="info.cached" class="hint">{{ info.models.slice(0, 20).join(', ') }}{{ info.models.length > 20 ? ` …（共 ${info.models.length} 个）` : '' }}</p>
        </div>
      </div>

      <!-- 租户配置 -->
      <div v-if="activeTab === 'tenant'">
        <div class="form-group">
          <label>选择租户</label>
          <select v-model="selectedTenantId" style="width: 280px" @change="loadTenantConfig">
            <option v-for="t in tenants" :key="t.tenant_id" :value="t.tenant_id">{{ t.name }} ({{ t.tenant_id }})</option>
          </select>
        </div>
        <form v-if="selectedTenantId" @submit.prevent="saveTenantConfig">
          <div class="form-group"><label><input v-model="tenantConfig.text_enabled" type="checkbox" /> 文本能力</label></div>
          <div class="form-group"><label><input v-model="tenantConfig.image_enabled" type="checkbox" /> 图像能力</label></div>
          <div class="form-group"><label><input v-model="tenantConfig.video_enabled" type="checkbox" /> 视频能力</label></div>
          <div class="form-group"><label>月度预算上限（0=不限）</label><input v-model.number="tenantConfig.monthly_budget_limit" type="number" min="0" /></div>
          <div class="form-group">
            <label>超额策略</label>
            <select v-model="tenantConfig.overage_action">
              <option value="block">阻断 (block)</option>
              <option value="warn">告警 (warn)</option>
              <option value="allow">放行 (allow)</option>
            </select>
          </div>
          <button type="submit" class="primary-btn" :disabled="saving">保存</button>
        </form>
      </div>

      <!-- 连接测试 -->
      <form v-if="activeTab === 'test'" @submit.prevent="runTest">
        <div class="form-group"><label>Provider 标识</label><input v-model="testProvider" placeholder="如 bailian" style="width: 220px" /></div>
        <button type="submit" class="primary-btn" :disabled="testing || !testProvider">{{ testing ? '测试中...' : '测试连接' }}</button>
        <p v-if="testResult" :style="{ color: testResult.ok ? '#2e7d32' : '#c62828' }">{{ testResult.msg }}</p>
        <p class="hint">读取 DB 覆盖层（system_settings）+ .env 引导配置，实时请求 /models 端点，不落库。</p>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'

const API = '/api/v1/admin/ai'
const tabs = [
  { key: 'defaults', label: '默认模型' },
  { key: 'aliases', label: '模型别名' },
  { key: 'catalog', label: '模型目录' },
  { key: 'tenant', label: '租户配置' },
  { key: 'test', label: '连接测试' },
]
const activeTab = ref('defaults')
const saving = ref(false)

const defaults = reactive({ default_chat_model: '', default_completion_model: '', default_embedding_model: '', default_provider: '' })
const fetchDefaults = async () => { try { const r = await axios.get(`${API}/defaults`); Object.assign(defaults, r.data.data || {}) } catch {} }
const saveDefaults = async () => {
  saving.value = true
  try { await axios.put(`${API}/defaults`, defaults); alert('保存成功') } catch { alert('保存失败') } finally { saving.value = false }
}

const aliases = ref<any[]>([])
const aliasKeyword = ref('')
const newAlias = reactive({ alias: '', actual_model: '', provider: '', type: 'text' })
const fetchAliases = async () => { try { const r = await axios.get(`${API}/aliases`, { params: { keyword: aliasKeyword.value || undefined } }); aliases.value = r.data.data || [] } catch {} }
const saveAlias = async () => {
  saving.value = true
  try {
    await axios.post(`${API}/aliases`, { ...newAlias, provider: newAlias.provider || null, is_active: true })
    Object.assign(newAlias, { alias: '', actual_model: '', provider: '', type: 'text' })
    await fetchAliases()
  } catch (e: any) { alert(e.response?.data?.message || '保存失败') } finally { saving.value = false }
}
const removeAlias = async (row: any) => {
  if (!confirm(`确认删除别名「${row.alias}」？`)) return
  try { await axios.delete(`${API}/aliases/${row.alias_id}`); await fetchAliases() } catch {}
}

const catalog = ref<Record<string, any>>({})
const syncing = ref(false)
const fetchCatalog = async () => { try { const r = await axios.get(`${API}/catalog`); catalog.value = r.data.data || {} } catch {} }
const syncCatalog = async (provider?: string) => {
  syncing.value = true
  try { await axios.post(`${API}/catalog/sync`, provider ? { provider } : {}); await fetchCatalog() } catch (e: any) { alert(e.response?.data?.message || '同步失败') } finally { syncing.value = false }
}

const tenants = ref<any[]>([])
const selectedTenantId = ref('')
const tenantConfig = reactive<any>({ text_enabled: true, image_enabled: true, video_enabled: true, monthly_budget_limit: 0, overage_action: 'block' })
const fetchTenants = async () => { try { const r = await axios.get(`${API}/tenants`); tenants.value = r.data.data || [] } catch {} }
const loadTenantConfig = async () => {
  if (!selectedTenantId.value) return
  try {
    const r = await axios.get(`${API}/tenants/${selectedTenantId.value}/config`)
    const data = r.data.data || {}
    Object.assign(tenantConfig, data.configured && data.config ? data.config : { text_enabled: true, image_enabled: true, video_enabled: true, monthly_budget_limit: 0, overage_action: 'block' })
  } catch {}
}
const saveTenantConfig = async () => {
  saving.value = true
  try { await axios.put(`${API}/tenants/${selectedTenantId.value}/config`, tenantConfig); alert('保存成功') } catch (e: any) { alert(e.response?.data?.message || '保存失败') } finally { saving.value = false }
}

const testProvider = ref('bailian')
const testing = ref(false)
const testResult = ref<{ ok: boolean; msg: string } | null>(null)
const runTest = async () => {
  testing.value = true
  testResult.value = null
  try {
    const r = await axios.post(`${API}/providers/${testProvider.value}/test`)
    const d = r.data.data || {}
    testResult.value = { ok: true, msg: `连接成功：${d.model_count} 个模型，延迟 ${d.latency_ms}ms（配置来源：${d.source === 'db' ? '后台 DB' : 'env'}）` }
  } catch (e: any) {
    testResult.value = { ok: false, msg: e.response?.data?.message || '连接失败' }
  } finally {
    testing.value = false
  }
}

onMounted(() => { fetchDefaults(); fetchAliases(); fetchCatalog(); fetchTenants() })
</script>
