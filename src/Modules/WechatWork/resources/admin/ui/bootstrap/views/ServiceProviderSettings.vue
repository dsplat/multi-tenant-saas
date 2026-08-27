<template>
  <div class="settings-page">
    <div class="page-header"><h2>企微服务商配置</h2></div>

    <div class="tabs">
      <button v-for="t in tabs" :key="t.key" :class="['tab-btn', { active: activeTab === t.key }]" @click="activeTab = t.key">{{ t.label }}</button>
    </div>

    <div class="panel">
      <!-- 服务商凭证 -->
      <div v-if="activeTab === 'providers'">
        <p class="hint">
          平台级服务商凭证（系统级配置，参照 ai_providers）。服务商注册 + 认证（300 元/年，主体须与平台备案主体一致）在企微服务商后台完成；
          创建代开发模板后把凭证录入下表，租户即可扫码授权（代开发模式）。服务商后台「开发配置 → 测试企业」可绑测试企业 0 元联调。
        </p>

        <div class="callback-box">
          <div style="display: flex; align-items: center; gap: 8px">
            <strong>模板回调 URL（填入企微代开发模板）：</strong>
            <code style="flex: 1">{{ callbackUrl }}</code>
            <button class="link-btn" @click="copyCallbackUrl">复制</button>
          </div>
          <p class="hint" style="margin-top: 6px">
            可信域名填 <code>auth.neihang.com</code> 并完成 WW_verify 归属认证（走平台 VerificationFile 接口）；模板上线审核约 15 分钟生效。
          </p>
        </div>

        <table class="data-table">
          <thead><tr><th>名称</th><th>Suite ID</th><th>服务商企业 ID</th><th>回调 URL</th><th>状态</th><th>操作</th></tr></thead>
          <tbody>
            <tr v-for="p in providers" :key="p.service_provider_id">
              <td><strong>{{ p.name }}</strong></td>
              <td>{{ p.suite_id }}</td>
              <td>{{ p.provider_corp_id || '—' }}</td>
              <td>{{ p.callback_url || '—' }}</td>
              <td><span :class="['badge', p.status === 'active' ? 'badge-success' : 'badge-danger']">{{ p.status === 'active' ? '启用' : '停用' }}</span></td>
              <td>
                <button class="link-btn" @click="editProvider(p)">编辑</button>
                <button class="link-btn" :disabled="testingId === p.service_provider_id" @click="runTest(p)">{{ testingId === p.service_provider_id ? '测试中...' : '测试' }}</button>
                <button class="link-btn" @click="removeProvider(p)">删除</button>
              </td>
            </tr>
            <tr v-if="providers.length === 0"><td colspan="6" class="empty-row">暂无服务商凭证，请在下方录入</td></tr>
          </tbody>
        </table>

        <p v-if="testResult" :style="{ margin: '10px 0', color: testResult.ok ? '#2e7d32' : '#c62828' }">{{ testResult.msg }}</p>
        <p class="hint">连接测试用 suite_id + suite_secret 实测 get_suite_token，需服务商后台已推送 suite_ticket（每 10 分钟一次）才可能成功。</p>

        <form style="margin-top: 16px; border-top: 1px solid #eee; padding-top: 12px" @submit.prevent="saveProvider">
          <h4>{{ providerForm.service_provider_id ? '编辑服务商' : '新增服务商' }}</h4>
          <div class="form-row">
            <div class="form-group"><label>名称（必填）</label><input v-model="providerForm.name" placeholder="如 蓝眼兔服务商" /></div>
            <div class="form-group"><label>Suite ID（模板创建后补录）</label><input v-model="providerForm.suite_id" placeholder="模板创建成功后填写，URL 验证阶段可留空" /></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label>服务商企业 ID</label><input v-model="providerForm.provider_corp_id" placeholder="provider corp id" /></div>
            <div class="form-group"><label>Suite Secret（掩码表示未修改）</label><input v-model="providerForm.suite_secret" type="password" /></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label>回调 Token</label><input v-model="providerForm.callback_token" placeholder="模板回调 Token" /></div>
            <div class="form-group"><label>EncodingAESKey（掩码表示未修改）</label><input v-model="providerForm.encoding_aes_key" type="password" /></div>
          </div>
          <div class="form-row">
            <div class="form-group"><label>模板回调 URL</label><input v-model="providerForm.callback_url" placeholder="如 https://auth.neihang.com/api/v1/wechat-work/suite/callback" /></div>
            <div class="form-group">
              <label>状态</label>
              <select v-model="providerForm.status"><option value="active">启用 (active)</option><option value="inactive">停用 (inactive)</option></select>
            </div>
          </div>
          <button type="submit" class="primary-btn" :disabled="saving">保存</button>
          <button v-if="providerForm.service_provider_id" type="button" class="link-btn" style="margin-left: 8px" @click="resetProviderForm">取消编辑</button>
        </form>
      </div>

      <!-- 已授权租户 -->
      <div v-if="activeTab === 'authorizations'">
        <div class="callback-box">
          <strong>「开始代开发应用」接入步骤</strong>
          <p class="hint" style="margin-top: 4px">
            租户扫码授权后，在企微<b>服务商后台</b>「代开发应用」列表为该企业「开始代开发应用」：应用信息（名称/logo 默认带出模板信息）、应用主页（终端站点如 club.lanyantu.com）、可信域名与 IP 白名单填 <code>auth.neihang.com</code>，回调 URL 填下方「回调配置」中的地址（保存时企微会发起 URL 验证）。验证通过后把企微生成的 Token / EncodingAESKey 回填此处，即完成该租户应用接入。
          </p>
        </div>
        <table class="data-table">
          <thead><tr><th>租户</th><th>租户 ID</th><th>Corp ID</th><th>Agent ID</th><th>状态</th><th>应用回调</th><th>授权时间</th><th>解除时间</th><th>操作</th></tr></thead>
          <tbody>
            <tr v-for="a in authorizations" :key="a.authorization_id">
              <td>
                <strong>{{ a.tenant_name }}</strong>
                <span v-if="a.tenant_domain" class="hint" style="display: block">{{ a.tenant_domain }}</span>
              </td>
              <td>{{ a.tenant_id }}</td>
              <td>{{ a.corp_id }}</td>
              <td>{{ a.agent_id }}</td>
              <td>
                <span :class="['badge', a.status === 'authorized' ? 'badge-success' : a.status === 'revoked' ? 'badge-danger' : 'badge-danger']">
                  {{ statusLabel(a.status) }}
                </span>
              </td>
              <td>
                <span v-if="a.status !== 'authorized'" class="hint">—</span>
                <span v-else :class="['badge', a.app_callback_configured ? 'badge-success' : 'badge-danger']">{{ a.app_callback_configured ? '已配置' : '未配置' }}</span>
              </td>
              <td>{{ a.authorized_at || '—' }}</td>
              <td>{{ a.revoked_at || '—' }}</td>
              <td>
                <button class="link-btn" :disabled="a.status !== 'authorized'" @click="openCallbackForm(a)">回调配置</button>
              </td>
            </tr>
            <tr v-if="authorizations.length === 0"><td colspan="9" class="empty-row">暂无租户授权</td></tr>
          </tbody>
        </table>

        <!-- 应用回调配置表单（「开始代开发应用」凭证回填） -->
        <form v-if="callbackForm.authorization_id" style="margin-top: 16px; border-top: 1px solid #eee; padding-top: 12px" @submit.prevent="saveAppCallback">
          <h4>应用回调配置：{{ callbackForm.tenant_name }}（租户 ID {{ callbackForm.tenant_id }}）</h4>
          <div class="form-row">
            <div class="form-group" style="flex: 1">
              <label>回调 URL（填入企微「开始代开发应用」）</label>
              <div style="display: flex; gap: 6px">
                <input :value="callbackForm.app_callback_url" readonly style="flex: 1" />
                <button type="button" class="link-btn" @click="copyAppCallbackUrl">复制</button>
              </div>
            </div>
          </div>
          <div class="form-row">
            <div class="form-group"><label>Token（企微「回调 URL 验证」处生成）</label><input v-model="callbackForm.app_callback_token" /></div>
            <div class="form-group"><label>EncodingAESKey（企微「回调 URL 验证」处生成）</label><input v-model="callbackForm.app_encoding_aes_key" type="password" /></div>
          </div>
          <p class="hint">保存时企微会对回调 URL 发起验证（需地址可达且 Token/AESKey 与企微侧一致）；验证通过后回填保存，应用事件推送即生效。</p>
          <button type="submit" class="primary-btn" :disabled="callbackSaving">保存</button>
          <button type="button" class="link-btn" style="margin-left: 8px" @click="callbackForm.authorization_id = null">取消</button>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'

const API = '/api/v1/admin/wechat-work'
const tabs = [
  { key: 'providers', label: '服务商凭证' },
  { key: 'authorizations', label: '已授权租户' },
]
const activeTab = ref('providers')
const saving = ref(false)

// admin SPA 与平台域同源，直接取当前 origin 拼套件回调地址
const callbackUrl = window.location.origin + '/api/v1/wechat-work/suite/callback'
const copyCallbackUrl = async () => {
  try { await navigator.clipboard.writeText(callbackUrl); alert('已复制回调 URL') } catch { alert('复制失败，请手动复制') }
}

const providers = ref<any[]>([])
const providerForm = reactive<any>({ service_provider_id: null, name: '', provider_corp_id: '', suite_id: '', suite_secret: '', callback_token: '', encoding_aes_key: '', callback_url: '', status: 'active' })
const fetchProviders = async () => { try { const r = await axios.get(`${API}/providers`); providers.value = r.data.data || [] } catch {} }
const resetProviderForm = () => Object.assign(providerForm, { service_provider_id: null, name: '', provider_corp_id: '', suite_id: '', suite_secret: '', callback_token: '', encoding_aes_key: '', callback_url: '', status: 'active' })
const editProvider = (row: any) => Object.assign(providerForm, row)

const saveProvider = async () => {
  saving.value = true
  try {
    const payload = {
      name: providerForm.name,
      provider_corp_id: providerForm.provider_corp_id || null,
      suite_id: providerForm.suite_id,
      suite_secret: providerForm.suite_secret || null,
      callback_token: providerForm.callback_token || null,
      encoding_aes_key: providerForm.encoding_aes_key || null,
      callback_url: providerForm.callback_url || null,
      status: providerForm.status,
    }
    if (providerForm.service_provider_id) {
      await axios.put(`${API}/providers/${providerForm.service_provider_id}`, payload)
    } else {
      await axios.post(`${API}/providers`, payload)
    }
    alert('保存成功')
    resetProviderForm()
    await fetchProviders()
  } catch (e: any) { alert(e.response?.data?.message || '保存失败') } finally { saving.value = false }
}

const removeProvider = async (row: any) => {
  if (!confirm(`确认删除服务商「${row.name}」？删除后租户将无法通过该服务商代开发授权。`)) return
  try { await axios.delete(`${API}/providers/${row.service_provider_id}`); await fetchProviders() } catch (e: any) { alert(e.response?.data?.message || '删除失败') }
}

const testingId = ref<number | null>(null)
const testResult = ref<{ ok: boolean; msg: string } | null>(null)
const runTest = async (row: any) => {
  testingId.value = row.service_provider_id
  testResult.value = null
  try {
    const r = await axios.post(`${API}/providers/${row.service_provider_id}/test`)
    const d = r.data.data || {}
    testResult.value = { ok: true, msg: `「${row.name}」连接成功：access_token ${d.access_token_prefix}...，有效期 ${d.expires_in}s` }
  } catch (e: any) {
    testResult.value = { ok: false, msg: e.response?.data?.message || '连接失败' }
  } finally { testingId.value = null }
}

const authorizations = ref<any[]>([])
const fetchAuthorizations = async () => { try { const r = await axios.get(`${API}/authorizations`); authorizations.value = r.data.data || [] } catch {} }
const statusLabel = (s: string) => ({ pending: '待授权', authorized: '已授权', revoked: '已解除' } as Record<string, string>)[s] || s

// ---- 应用回调配置（「开始代开发应用」凭证回填） ----
const callbackSaving = ref(false)
const callbackForm = reactive<any>({ authorization_id: null, tenant_id: null, tenant_name: '', app_callback_url: '', app_callback_token: '', app_encoding_aes_key: '' })

const openCallbackForm = (row: any) => {
  Object.assign(callbackForm, {
    authorization_id: row.authorization_id,
    tenant_id: row.tenant_id,
    tenant_name: row.tenant_name || row.tenant_id,
    app_callback_url: row.app_callback_url,
    app_callback_token: '',
    app_encoding_aes_key: '',
  })
}

const copyAppCallbackUrl = async () => {
  try { await navigator.clipboard.writeText(callbackForm.app_callback_url); alert('已复制回调 URL') } catch { alert('复制失败，请手动复制') }
}

const saveAppCallback = async () => {
  if (!callbackForm.app_callback_token || !callbackForm.app_encoding_aes_key) {
    alert('请填写 Token 与 EncodingAESKey')
    return
  }
  callbackSaving.value = true
  try {
    await axios.put(`${API}/authorizations/${callbackForm.authorization_id}/app-callback`, {
      app_callback_url: callbackForm.app_callback_url,
      app_callback_token: callbackForm.app_callback_token,
      app_encoding_aes_key: callbackForm.app_encoding_aes_key,
    })
    alert('应用回调配置已保存')
    callbackForm.authorization_id = null
    await fetchAuthorizations()
  } catch (e: any) { alert(e.response?.data?.message || '保存失败') } finally { callbackSaving.value = false }
}

onMounted(() => { fetchProviders(); fetchAuthorizations() })
</script>

<style scoped>
.callback-box {
  background: #f8f9fa;
  border: 1px solid #eee;
  border-radius: 4px;
  padding: 10px 12px;
  margin: 12px 0;
}
</style>
