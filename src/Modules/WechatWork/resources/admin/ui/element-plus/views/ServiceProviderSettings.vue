<template>
  <div class="page">
    <div class="page-header"><h2>企微服务商配置</h2></div>

    <el-card shadow="never">
      <el-tabs v-model="activeTab">
        <!-- 服务商凭证 -->
        <el-tab-pane label="服务商凭证" name="providers">
          <el-alert type="info" :closable="false" show-icon style="margin-bottom: 12px">
            <template #title>
              平台级服务商凭证（系统级配置）。服务商注册 + 认证（300 元/年，主体须与平台备案主体一致）在企微服务商后台完成；
              创建代开发模板后把凭证录入下表，租户即可扫码授权（代开发模式）。测试企业 0 元联调。
            </template>
          </el-alert>

          <div style="background: #f5f7fa; border: 1px solid #ebeef5; border-radius: 4px; padding: 10px 12px; margin-bottom: 12px">
            <div style="display: flex; align-items: center; gap: 8px">
              <strong style="white-space: nowrap">模板回调 URL（填入企微代开发模板）：</strong>
              <code style="flex: 1">{{ callbackUrl }}</code>
              <el-button link type="primary" @click="copyCallbackUrl">复制</el-button>
            </div>
            <p class="form-hint" style="margin: 6px 0 0; padding: 0">
              可信域名填 auth.neihang.com 并完成 WW_verify 归属认证（走平台 VerificationFile 接口）；模板上线审核约 15 分钟生效。
            </p>
          </div>

          <div style="margin: 12px 0">
            <el-button type="primary" @click="openProviderDialog()">新增服务商</el-button>
            <span class="form-hint">连接测试用 suite_id + suite_secret 实测 get_suite_token，需服务商后台已推送 suite_ticket（每 10 分钟一次）才可能成功</span>
          </div>

          <el-table :data="providers" stripe empty-text="暂无服务商凭证">
            <el-table-column label="名称" min-width="140">
              <template #default="{ row }"><strong>{{ row.name }}</strong></template>
            </el-table-column>
            <el-table-column prop="suite_id" label="Suite ID" min-width="200" show-overflow-tooltip />
            <el-table-column label="服务商企业 ID" min-width="160">
              <template #default="{ row }">{{ row.provider_corp_id || '—' }}</template>
            </el-table-column>
            <el-table-column label="回调 URL" min-width="240" show-overflow-tooltip>
              <template #default="{ row }">{{ row.callback_url || '—' }}</template>
            </el-table-column>
            <el-table-column label="状态" width="80">
              <template #default="{ row }">
                <el-tag :type="row.status === 'active' ? 'success' : 'info'" size="small">{{ row.status === 'active' ? '启用' : '停用' }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="模板权限" min-width="220">
              <template #default="{ row }">
                <template v-if="row.permissions?.length">
                  <el-tag v-for="k in row.permissions" :key="k" size="small" style="margin-right: 4px">{{ PERMISSION_OPTIONS[k] || k }}</el-tag>
                </template>
                <span v-else class="form-hint" style="margin: 0">未声明</span>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="170">
              <template #default="{ row }">
                <el-button link type="primary" size="small" @click="openProviderDialog(row)">编辑</el-button>
                <el-button link type="primary" size="small" :loading="testingId === row.service_provider_id" @click="runTest(row)">测试</el-button>
                <el-button link type="danger" size="small" @click="removeProvider(row)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <!-- 已授权租户 -->
        <el-tab-pane label="已授权租户" name="authorizations">
          <el-alert type="info" :closable="false" show-icon style="margin-bottom: 12px">
            <template #title>
              租户扫码授权后，在企微<b>服务商后台</b>「代开发应用」列表为该企业「开始代开发应用」：应用信息（名称/logo 默认带出模板信息）、应用主页（终端站点如 club.lanyantu.com）、可信域名与 IP 白名单填 <b>auth.neihang.com</b>，回调 URL 填该行「回调配置」中的地址（保存时企微会发起 URL 验证）。验证通过后把企微生成的 Token / EncodingAESKey 回填「回调配置」，即完成该租户应用接入。
            </template>
          </el-alert>
          <el-table :data="authorizations" stripe empty-text="暂无租户授权">
            <el-table-column label="租户" min-width="160">
              <template #default="{ row }">
                <strong>{{ row.tenant_name }}</strong>
                <span v-if="row.tenant_domain" class="form-hint">({{ row.tenant_domain }})</span>
              </template>
            </el-table-column>
            <el-table-column prop="tenant_id" label="租户 ID" width="150" />
            <el-table-column prop="corp_id" label="Corp ID" min-width="180" show-overflow-tooltip />
            <el-table-column prop="agent_id" label="Agent ID" min-width="100" />
            <el-table-column label="状态" width="90">
              <template #default="{ row }">
                <el-tag :type="row.status === 'authorized' ? 'success' : row.status === 'revoked' ? 'info' : 'warning'" size="small">
                  {{ statusLabel(row.status) }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="应用回调" min-width="110">
              <template #default="{ row }">
                <el-tag v-if="row.status !== 'authorized'" type="info" size="small">—</el-tag>
                <el-tag v-else :type="row.app_callback_configured ? 'success' : 'warning'" size="small">
                  {{ row.app_callback_configured ? '已配置' : '未配置' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="授权时间" width="170">
              <template #default="{ row }">{{ row.authorized_at || '—' }}</template>
            </el-table-column>
            <el-table-column label="解除时间" width="170">
              <template #default="{ row }">{{ row.revoked_at || '—' }}</template>
            </el-table-column>
            <el-table-column label="操作" width="110" fixed="right">
              <template #default="{ row }">
                <el-button link type="primary" size="small" :disabled="row.status !== 'authorized'" @click="openCallbackDialog(row)">回调配置</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>
      </el-tabs>
    </el-card>

    <!-- 服务商编辑弹窗 -->
    <el-dialog v-model="providerDialogVisible" :title="providerForm.service_provider_id ? '编辑服务商' : '新增服务商'" width="560px">
      <el-form label-width="140px">
        <el-form-item label="名称（必填）"><el-input v-model="providerForm.name" placeholder="如 蓝眼兔服务商" /></el-form-item>
        <el-form-item label="Suite ID（模板创建后补录）"><el-input v-model="providerForm.suite_id" placeholder="模板创建成功后填写，URL 验证阶段可留空" /></el-form-item>
        <el-form-item label="服务商企业 ID"><el-input v-model="providerForm.provider_corp_id" placeholder="服务商后台可查，URL 验证解密需要" /></el-form-item>
        <el-form-item label="Suite Secret"><el-input v-model="providerForm.suite_secret" type="password" show-password placeholder="掩码表示未修改" /></el-form-item>
        <el-form-item label="回调 Token"><el-input v-model="providerForm.callback_token" placeholder="模板回调 Token" /></el-form-item>
        <el-form-item label="EncodingAESKey"><el-input v-model="providerForm.encoding_aes_key" type="password" show-password placeholder="掩码表示未修改" /></el-form-item>
        <el-form-item label="模板回调 URL"><el-input v-model="providerForm.callback_url" placeholder="https://auth.neihang.com/api/v1/wechat-work/suite/callback" /></el-form-item>
        <el-form-item label="状态">
          <el-select v-model="providerForm.status" style="width: 100%">
            <el-option label="启用 (active)" value="active" />
            <el-option label="停用 (inactive)" value="inactive" />
          </el-select>
        </el-form-item>
        <el-form-item label="模板权限集">
          <el-checkbox-group v-model="providerForm.permissions" style="width: 100%">
            <el-checkbox v-for="(label, key) in PERMISSION_OPTIONS" :key="key" :value="key" style="display: flex; margin-bottom: 6px">{{ label }}</el-checkbox>
          </el-checkbox-group>
          <p class="form-hint" style="margin: 6px 0 0; padding: 0; width: 100%">
            需与企微服务商后台模板勾选的权限一致；租户扫码授权即一次性获得全部权限，无需逐项配置白名单/授权域名
          </p>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="providerDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="saveProvider">保存</el-button>
      </template>
    </el-dialog>

    <!-- 应用回调配置弹窗（「开始代开发应用」凭证回填） -->
    <el-dialog v-model="callbackDialogVisible" title="应用回调配置" width="600px">
      <el-alert type="info" :closable="false" show-icon style="margin-bottom: 12px">
        <template #title>
          在企微<b>服务商后台</b>「开始代开发应用」时填入下方回调 URL（保存时企微发起 URL 验证，需本地址可达且凭证一致）；验证通过后把企微「回调 URL 验证」处生成的 <b>Token</b> / <b>EncodingAESKey</b> 回填此处保存。
        </template>
      </el-alert>
      <el-form label-width="130px">
        <el-form-item label="租户"><strong>{{ callbackForm.tenant_name }}</strong>（租户 ID {{ callbackForm.tenant_id }}）</el-form-item>
        <el-form-item label="回调 URL">
          <div style="display: flex; align-items: center; gap: 8px; width: 100%">
            <el-input :model-value="callbackForm.app_callback_url" readonly />
            <el-button @click="copyAppCallbackUrl">复制</el-button>
          </div>
          <p class="form-hint" style="margin: 4px 0 0; padding: 0">已带租户标识，须与企微侧实际填写的回调 URL 一致</p>
        </el-form-item>
        <el-form-item label="Token"><el-input v-model="callbackForm.app_callback_token" placeholder="企微「回调 URL 验证」处生成" /></el-form-item>
        <el-form-item label="EncodingAESKey"><el-input v-model="callbackForm.app_encoding_aes_key" type="password" show-password placeholder="企微「回调 URL 验证」处生成" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="callbackDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="callbackSaving" @click="saveAppCallback">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'

const API = '/api/v1/admin/wechat-work'
const activeTab = ref('providers')
const saving = ref(false)

// 代开发模板权限字典（key => 展示名），与后端 ServiceProvider::TEMPLATE_PERMISSIONS 同步
const PERMISSION_OPTIONS: Record<string, string> = {
  'contact:read': '通讯录读取（成员/部门）',
  'contact:write': '通讯录写（组织架构同步）',
  'message:send': '应用消息发送',
  'external_contact:read': '客户联系-客户信息读取',
  'external_contact:write': '客户联系-客户群/标签管理',
  'media:upload': '素材上传（图片/文件/视频）',
  'userinfo:read': '成员身份信息读取',
}

// admin SPA 与平台域同源，直接取当前 origin 拼套件回调地址
const callbackUrl = window.location.origin + '/api/v1/wechat-work/suite/callback'
const copyCallbackUrl = async () => {
  try { await navigator.clipboard.writeText(callbackUrl); ElMessage.success('已复制回调 URL') } catch { ElMessage.error('复制失败，请手动复制') }
}

// ---- 服务商凭证 ----
const providers = ref<any[]>([])
const providerDialogVisible = ref(false)
const providerForm = reactive<any>({ service_provider_id: null, name: '', provider_corp_id: '', suite_id: '', suite_secret: '', callback_token: '', encoding_aes_key: '', callback_url: '', status: 'active', permissions: [] })

const fetchProviders = async () => {
  try {
    const res = await axios.get(`${API}/providers`)
    providers.value = res.data.data || []
  } catch {}
}

const openProviderDialog = (row?: any) => {
  Object.assign(providerForm, { service_provider_id: null, name: '', provider_corp_id: '', suite_id: '', suite_secret: '', callback_token: '', encoding_aes_key: '', callback_url: '', status: 'active', permissions: [] })
  if (row) Object.assign(providerForm, row)
  providerDialogVisible.value = true
}

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
      permissions: providerForm.permissions,
    }
    if (providerForm.service_provider_id) {
      await axios.put(`${API}/providers/${providerForm.service_provider_id}`, payload)
    } else {
      await axios.post(`${API}/providers`, payload)
    }
    ElMessage.success('保存成功')
    providerDialogVisible.value = false
    await fetchProviders()
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

const removeProvider = async (row: any) => {
  try {
    await ElMessageBox.confirm(`确认删除服务商「${row.name}」？删除后租户将无法通过该服务商代开发授权。`, '提示', { type: 'warning' })
    await axios.delete(`${API}/providers/${row.service_provider_id}`)
    ElMessage.success('已删除')
    await fetchProviders()
  } catch (e: any) {
    if (e?.response) ElMessage.error(e.response.data?.message || '删除失败')
  }
}

// ---- 连接测试 ----
const testingId = ref<number | null>(null)
const runTest = async (row: any) => {
  testingId.value = row.service_provider_id
  try {
    const res = await axios.post(`${API}/providers/${row.service_provider_id}/test`)
    const d = res.data.data || {}
    ElMessage.success(`「${row.name}」连接成功：access_token ${d.access_token_prefix}...，有效期 ${d.expires_in}s`)
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '连接失败')
  } finally {
    testingId.value = null
  }
}

// ---- 已授权租户 ----
const authorizations = ref<any[]>([])
const fetchAuthorizations = async () => {
  try {
    const res = await axios.get(`${API}/authorizations`)
    authorizations.value = res.data.data || []
  } catch {}
}
const statusLabel = (s: string) => ({ pending: '待授权', authorized: '已授权', revoked: '已解除' } as Record<string, string>)[s] || s

// ---- 应用回调配置（「开始代开发应用」凭证回填） ----
const callbackDialogVisible = ref(false)
const callbackSaving = ref(false)
const callbackForm = reactive<any>({ authorization_id: null, tenant_id: null, tenant_name: '', app_callback_url: '', app_callback_token: '', app_encoding_aes_key: '' })

const openCallbackDialog = (row: any) => {
  Object.assign(callbackForm, {
    authorization_id: row.authorization_id,
    tenant_id: row.tenant_id,
    tenant_name: row.tenant_name || row.tenant_id,
    app_callback_url: row.app_callback_url,
    app_callback_token: '',
    app_encoding_aes_key: '',
  })
  callbackDialogVisible.value = true
}

const copyAppCallbackUrl = async () => {
  try { await navigator.clipboard.writeText(callbackForm.app_callback_url); ElMessage.success('已复制回调 URL') } catch { ElMessage.error('复制失败，请手动复制') }
}

const saveAppCallback = async () => {
  if (!callbackForm.app_callback_token || !callbackForm.app_encoding_aes_key) {
    ElMessage.warning('请填写 Token 与 EncodingAESKey')
    return
  }
  callbackSaving.value = true
  try {
    await axios.put(`${API}/authorizations/${callbackForm.authorization_id}/app-callback`, {
      app_callback_url: callbackForm.app_callback_url,
      app_callback_token: callbackForm.app_callback_token,
      app_encoding_aes_key: callbackForm.app_encoding_aes_key,
    })
    ElMessage.success('应用回调配置已保存')
    callbackDialogVisible.value = false
    await fetchAuthorizations()
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '保存失败')
  } finally {
    callbackSaving.value = false
  }
}

onMounted(() => {
  fetchProviders()
  fetchAuthorizations()
})
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.form-hint { font-size: 12px; color: #999; margin-left: 8px; }
</style>
