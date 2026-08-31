<template>
  <div class="page">
    <div class="page-header"><h2>微信服务商配置</h2></div>

    <el-card shadow="never">
      <el-tabs v-model="activeTab">
        <!-- 组件凭证 -->
        <el-tab-pane label="组件凭证" name="providers">
          <el-alert type="info" :closable="false" show-icon style="margin-bottom: 12px">
            <template #title>
              平台级微信第三方平台组件凭证（系统级配置）。注册与认证在<b>微信开放平台 → 第三方平台</b>完成
              （平台主体须与开放平台主体一致）；创建平台后把组件 AppID / Secret / Token / EncodingAESKey 录入下表，
              租户即可在控制台发起授权（服务商模式）。测试期最多 10 个测试公众号（填原始 ID），正式服务号/小程序不占用测试名额。
            </template>
          </el-alert>

          <div style="background: #f5f7fa; border: 1px solid #ebeef5; border-radius: 4px; padding: 10px 12px; margin-bottom: 12px">
            <div style="display: flex; align-items: center; gap: 8px">
              <strong style="white-space: nowrap">消息与事件接收 URL（填入开放平台第三方平台）：</strong>
              <code style="flex: 1">{{ callbackUrl }}</code>
              <el-button link type="primary" @click="copyCallbackUrl">复制</el-button>
            </div>
            <p class="form-hint" style="margin: 6px 0 0; padding: 0">
              授权回调域名同样为平台级配置（本系统统一回调域，如 auth.neihang.com）；
              保存后平台会推送 component_verify_ticket（每 10 分钟一次，12 小时有效），
              缺失时点「测试」会提示，需在平台后台「重置推送」。
            </p>
          </div>

          <div style="margin: 12px 0">
            <el-button type="primary" @click="openProviderDialog()">新增组件</el-button>
            <span class="form-hint">连接测试用 component_appid + component_secret 实测 api_component_token，需平台已推送 verify_ticket 且服务器出口 IP 在平台「IP 白名单」内（否则报 61004）</span>
          </div>

          <el-table :data="providers" stripe empty-text="暂无组件凭证">
            <el-table-column label="名称" min-width="140">
              <template #default="{ row }"><strong>{{ row.name }}</strong></template>
            </el-table-column>
            <el-table-column prop="component_appid" label="Component AppID" min-width="200" show-overflow-tooltip />
            <el-table-column label="回调 URL" min-width="240" show-overflow-tooltip>
              <template #default="{ row }">{{ row.callback_url || '—' }}</template>
            </el-table-column>
            <el-table-column label="状态" width="80">
              <template #default="{ row }">
                <el-tag :type="row.status === 'active' ? 'success' : 'info'" size="small">{{ row.status === 'active' ? '启用' : '停用' }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="平台权限" min-width="220">
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
                <el-button link type="primary" size="small" :loading="testingId === row.component_provider_id" @click="runTest(row)">测试</el-button>
                <el-button link type="danger" size="small" @click="removeProvider(row)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <!-- 已授权租户 -->
        <el-tab-pane label="已授权租户" name="authorizations">
          <el-alert type="info" :closable="false" show-icon style="margin-bottom: 12px">
            <template #title>
              租户在控制台「微信配置」发起授权后，在微信开放平台第三方平台「授权管理」可查看与解除。
              <b>服务商不能主动解除授权</b>：租户侧「解除授权」仅解除本地映射，微信侧彻底取消需公众号/小程序管理员在
              「公众平台-设置与开发-第三方平台-我的授权」中操作，unauthorized 事件到达后本地自动同步为已解除。
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
            <el-table-column prop="authorizer_appid" label="Authorizer AppID" min-width="180" show-overflow-tooltip />
            <el-table-column label="账号类型" width="90">
              <template #default="{ row }">
                <el-tag size="small" :type="row.authorizer_type === 'mini_program' ? 'warning' : 'success'">{{ row.authorizer_type_label }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="状态" width="90">
              <template #default="{ row }">
                <el-tag :type="row.status === 'authorized' ? 'success' : row.status === 'revoked' ? 'info' : 'warning'" size="small">
                  {{ statusLabel(row.status) }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="授权时间" width="170">
              <template #default="{ row }">{{ row.authorized_at || '—' }}</template>
            </el-table-column>
            <el-table-column label="解除时间" width="170">
              <template #default="{ row }">{{ row.revoked_at || '—' }}</template>
            </el-table-column>
          </el-table>
        </el-tab-pane>
      </el-tabs>
    </el-card>

    <!-- 组件编辑弹窗 -->
    <el-dialog v-model="providerDialogVisible" :title="providerForm.component_provider_id ? '编辑组件' : '新增组件'" width="560px">
      <el-form label-width="160px">
        <el-form-item label="名称（必填）"><el-input v-model="providerForm.name" placeholder="如 蓝眼兔微信服务商" /></el-form-item>
        <el-form-item label="Component AppID"><el-input v-model="providerForm.component_appid" placeholder="开放平台第三方平台获取（wx 开头）" /></el-form-item>
        <el-form-item label="Component Secret"><el-input v-model="providerForm.component_secret" type="password" show-password placeholder="掩码表示未修改" /></el-form-item>
        <el-form-item label="消息校验 Token"><el-input v-model="providerForm.component_token" placeholder="平台「消息与事件接收URL」配置时填写" /></el-form-item>
        <el-form-item label="EncodingAESKey"><el-input v-model="providerForm.encoding_aes_key" type="password" show-password placeholder="掩码表示未修改" /></el-form-item>
        <el-form-item label="消息接收 URL"><el-input v-model="providerForm.callback_url" placeholder="留空自动带出平台统一地址" /></el-form-item>
        <el-form-item label="状态">
          <el-select v-model="providerForm.status" style="width: 100%">
            <el-option label="启用 (active)" value="active" />
            <el-option label="停用 (inactive)" value="inactive" />
          </el-select>
        </el-form-item>
        <el-form-item label="平台权限集">
          <el-checkbox-group v-model="providerForm.permissions" style="width: 100%">
            <el-checkbox v-for="(label, key) in PERMISSION_OPTIONS" :key="key" :value="key" style="display: flex; margin-bottom: 6px">{{ label }}</el-checkbox>
          </el-checkbox-group>
          <p class="form-hint" style="margin: 6px 0 0; padding: 0; width: 100%">
            需与开放平台第三方平台「权限集」勾选一致；租户授权即一次性获得全部权限，
            其中「网页授权」是服务商模式登录的前提（授权后公众号网页授权由第三方平台代替实现）
          </p>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="providerDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="saveProvider">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'

const API = '/api/v1/admin/wechat'
const activeTab = ref('providers')
const saving = ref(false)

// 第三方平台权限集字典（key => 展示名），与后端 ComponentProvider::TEMPLATE_PERMISSIONS 同步
const PERMISSION_OPTIONS: Record<string, string> = {
  'authorize:userinfo': '网页授权（snsapi_userinfo 用户信息）',
  'message:receive': '消息与事件接收',
  'material:manage': '素材管理',
  'user:manage': '用户管理（标签/备注）',
  'menu:manage': '自定义菜单',
  'template:manage': '模板消息',
}

// admin SPA 与平台域同源，直接取当前 origin 拼消息与事件接收地址（微信第三方平台回调）
const callbackUrl = window.location.origin + '/api/v1/wechat/message/callback'
const copyCallbackUrl = async () => {
  try { await navigator.clipboard.writeText(callbackUrl); ElMessage.success('已复制回调 URL') } catch { ElMessage.error('复制失败，请手动复制') }
}

// ---- 组件凭证 ----
const providers = ref<any[]>([])
const providerDialogVisible = ref(false)
const providerForm = reactive<any>({ component_provider_id: null, name: '', component_appid: '', component_secret: '', component_token: '', encoding_aes_key: '', callback_url: '', status: 'active', permissions: [] })

const fetchProviders = async () => {
  try {
    const res = await axios.get(`${API}/providers`)
    providers.value = res.data.data || []
  } catch {}
}

const openProviderDialog = (row?: any) => {
  Object.assign(providerForm, { component_provider_id: null, name: '', component_appid: '', component_secret: '', component_token: '', encoding_aes_key: '', callback_url: '', status: 'active', permissions: [] })
  if (row) Object.assign(providerForm, row)
  providerDialogVisible.value = true
}

const saveProvider = async () => {
  saving.value = true
  try {
    const payload = {
      name: providerForm.name,
      component_appid: providerForm.component_appid,
      component_secret: providerForm.component_secret || null,
      component_token: providerForm.component_token || null,
      encoding_aes_key: providerForm.encoding_aes_key || null,
      callback_url: providerForm.callback_url || null,
      status: providerForm.status,
      permissions: providerForm.permissions,
    }
    if (providerForm.component_provider_id) {
      await axios.put(`${API}/providers/${providerForm.component_provider_id}`, payload)
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
    await ElMessageBox.confirm(`确认删除组件「${row.name}」？删除后租户将无法通过该组件授权。`, '提示', { type: 'warning' })
    await axios.delete(`${API}/providers/${row.component_provider_id}`)
    ElMessage.success('已删除')
    await fetchProviders()
  } catch (e: any) {
    if (e?.response) ElMessage.error(e.response.data?.message || '删除失败')
  }
}

// ---- 连接测试 ----
const testingId = ref<number | null>(null)
const runTest = async (row: any) => {
  testingId.value = row.component_provider_id
  try {
    const res = await axios.post(`${API}/providers/${row.component_provider_id}/test`)
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

onMounted(() => {
  fetchProviders()
  fetchAuthorizations()
})
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.form-hint { font-size: 12px; color: #999; margin-left: 8px; }
</style>
