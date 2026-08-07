<template>
  <div class="page">
    <div class="page-header"><h2>AI 配置</h2></div>

    <el-card shadow="never">
      <el-tabs v-model="activeTab">
        <!-- 默认模型 -->
        <el-tab-pane label="默认模型" name="defaults">
          <el-form label-width="160px" style="max-width: 640px; margin-top: 12px">
            <el-form-item label="默认对话模型"><el-input v-model="defaults.default_chat_model" placeholder="如 qwen3.7-plus" /></el-form-item>
            <el-form-item label="默认补全模型"><el-input v-model="defaults.default_completion_model" placeholder="如 qwen3.7-plus" /></el-form-item>
            <el-form-item label="默认向量模型"><el-input v-model="defaults.default_embedding_model" placeholder="如 qwen3.7-text-embedding" /></el-form-item>
            <el-form-item label="默认提供商"><el-input v-model="defaults.default_provider" placeholder="如 bailian" /></el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="saving" @click="saveDefaults">保存</el-button>
              <span class="form-hint">保存后立即生效（60 秒缓存）；留空则回退 .env 引导配置</span>
            </el-form-item>
          </el-form>
        </el-tab-pane>

        <!-- 模型别名 -->
        <el-tab-pane label="模型别名" name="aliases">
          <div style="display: flex; gap: 8px; margin: 12px 0">
            <el-input v-model="aliasKeyword" placeholder="搜索别名/模型名" style="width: 240px" clearable @keyup.enter="fetchAliases" />
            <el-button @click="fetchAliases">搜索</el-button>
            <el-button type="primary" @click="openAliasDialog()">新增别名</el-button>
          </div>
          <el-table :data="aliases" stripe empty-text="暂无别名">
            <el-table-column prop="alias" label="别名" min-width="180" />
            <el-table-column prop="actual_model" label="实际模型" min-width="200" />
            <el-table-column prop="provider" label="提供商" width="120">
              <template #default="{ row }">{{ row.provider || '(默认)' }}</template>
            </el-table-column>
            <el-table-column prop="type" label="类型" width="80" />
            <el-table-column label="状态" width="80">
              <template #default="{ row }">
                <el-tag :type="row.is_active ? 'success' : 'info'" size="small">{{ row.is_active ? '启用' : '停用' }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="130">
              <template #default="{ row }">
                <el-button link type="primary" size="small" @click="openAliasDialog(row)">编辑</el-button>
                <el-button link type="danger" size="small" @click="removeAlias(row)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <!-- 模型目录 -->
        <el-tab-pane label="模型目录" name="catalog">
          <div style="margin: 12px 0">
            <el-button type="primary" :loading="syncing" @click="syncCatalog">同步全部目录</el-button>
            <span class="form-hint">从各 provider 的 /models 端点拉取真实模型清单（缓存 1 天）</span>
          </div>
          <el-collapse v-if="catalogLoaded">
            <el-collapse-item v-for="(info, provider) in catalog" :key="provider" :name="provider">
              <template #title>
                <strong>{{ provider }}</strong>
                <el-tag :type="info.cached ? 'success' : 'info'" size="small" style="margin-left: 8px">
                  {{ info.cached ? `${info.count} 个模型` : '无缓存' }}
                </el-tag>
                <el-button link type="primary" size="small" style="margin-left: 12px" :loading="syncing" @click.stop="syncCatalog(String(provider))">
                  刷新
                </el-button>
              </template>
              <div style="display: flex; flex-wrap: wrap; gap: 6px">
                <el-tag v-for="m in info.models" :key="m" size="small" type="info">{{ m }}</el-tag>
                <span v-if="info.models.length === 0" class="form-hint">暂无缓存，点击刷新同步</span>
              </div>
            </el-collapse-item>
          </el-collapse>
        </el-tab-pane>

        <!-- 租户 AI 配置 -->
        <el-tab-pane label="租户配置" name="tenant">
          <div style="margin: 12px 0">
            <span style="font-size: 14px; color: #666">选择租户：</span>
            <el-select v-model="selectedTenantId" filterable placeholder="请选择" style="width: 260px" @change="loadTenantConfig">
              <el-option v-for="t in tenants" :key="t.tenant_id" :label="`${t.name} (${t.tenant_id})`" :value="t.tenant_id" />
            </el-select>
          </div>
          <template v-if="selectedTenantId">
            <el-form label-width="160px" style="max-width: 640px">
              <el-form-item label="文本能力"><el-switch v-model="tenantConfig.text_enabled" /></el-form-item>
              <el-form-item label="图像能力"><el-switch v-model="tenantConfig.image_enabled" /></el-form-item>
              <el-form-item label="视频能力"><el-switch v-model="tenantConfig.video_enabled" /></el-form-item>
              <el-form-item label="月度预算上限"><el-input-number v-model="tenantConfig.monthly_budget_limit" :min="0" :step="100" /><span class="form-hint" style="margin-left: 8px">0 = 不限</span></el-form-item>
              <el-form-item label="超额策略">
                <el-select v-model="tenantConfig.overage_action" style="width: 200px">
                  <el-option label="阻断 (block)" value="block" />
                  <el-option label="告警 (warn)" value="warn" />
                  <el-option label="放行 (allow)" value="allow" />
                </el-select>
              </el-form-item>
              <el-form-item>
                <el-button type="primary" :loading="saving" @click="saveTenantConfig">保存</el-button>
              </el-form-item>
            </el-form>
          </template>
        </el-tab-pane>

        <!-- 连接测试 -->
        <el-tab-pane label="连接测试" name="test">
          <el-form label-width="160px" style="max-width: 640px; margin-top: 12px">
            <el-form-item label="Provider 标识">
              <el-input v-model="testProvider" placeholder="如 bailian" style="width: 240px" />
            </el-form-item>
            <el-form-item>
              <el-button type="primary" :loading="testing" :disabled="!testProvider" @click="runTest">测试连接</el-button>
            </el-form-item>
            <el-alert
              v-if="testResult"
              :title="testResult.msg"
              :type="testResult.ok ? 'success' : 'error'"
              :closable="false"
              show-icon
            />
          </el-form>
          <p class="form-hint">读取 DB 覆盖层（system_settings）+ .env 引导配置，实时请求 /models 端点，不落库。</p>
        </el-tab-pane>
      </el-tabs>
    </el-card>

    <!-- 别名编辑弹窗 -->
    <el-dialog v-model="aliasDialogVisible" :title="aliasForm.alias_id ? '编辑别名' : '新增别名'" width="520px">
      <el-form label-width="100px">
        <el-form-item label="别名"><el-input v-model="aliasForm.alias" placeholder="如 text-embedding-3-small" /></el-form-item>
        <el-form-item label="实际模型"><el-input v-model="aliasForm.actual_model" placeholder="如 qwen3.7-text-embedding" /></el-form-item>
        <el-form-item label="提供商"><el-input v-model="aliasForm.provider" placeholder="留空 = 默认提供商" /></el-form-item>
        <el-form-item label="类型">
          <el-select v-model="aliasForm.type" style="width: 100%">
            <el-option label="text" value="text" />
            <el-option label="image" value="image" />
            <el-option label="video" value="video" />
          </el-select>
        </el-form-item>
        <el-form-item label="启用"><el-switch v-model="aliasForm.is_active" /></el-form-item>
        <el-form-item label="说明"><el-input v-model="aliasForm.description" type="textarea" :rows="2" /></el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="aliasDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="saveAlias">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'

const API = '/api/v1/admin/ai'
const activeTab = ref('defaults')
const saving = ref(false)

// ---- 默认模型 ----
const defaults = reactive({
  default_chat_model: '',
  default_completion_model: '',
  default_embedding_model: '',
  default_provider: '',
})

const fetchDefaults = async () => {
  try {
    const res = await axios.get(`${API}/defaults`)
    Object.assign(defaults, res.data.data || {})
  } catch {}
}

const saveDefaults = async () => {
  saving.value = true
  try {
    await axios.put(`${API}/defaults`, defaults)
    ElMessage.success('保存成功，稍后生效')
    await fetchDefaults()
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

// ---- 模型别名 ----
const aliases = ref<any[]>([])
const aliasKeyword = ref('')
const aliasDialogVisible = ref(false)
const aliasForm = reactive<any>({ alias_id: null, alias: '', actual_model: '', provider: '', type: 'text', is_active: true, description: '' })

const fetchAliases = async () => {
  try {
    const res = await axios.get(`${API}/aliases`, { params: { keyword: aliasKeyword.value || undefined } })
    aliases.value = res.data.data || []
  } catch {}
}

const openAliasDialog = (row?: any) => {
  Object.assign(aliasForm, { alias_id: null, alias: '', actual_model: '', provider: '', type: 'text', is_active: true, description: '' })
  if (row) Object.assign(aliasForm, row)
  aliasDialogVisible.value = true
}

const saveAlias = async () => {
  saving.value = true
  try {
    const payload = { alias: aliasForm.alias, actual_model: aliasForm.actual_model, provider: aliasForm.provider || null, type: aliasForm.type, is_active: aliasForm.is_active, description: aliasForm.description || null }
    if (aliasForm.alias_id) {
      await axios.put(`${API}/aliases/${aliasForm.alias_id}`, payload)
    } else {
      await axios.post(`${API}/aliases`, payload)
    }
    ElMessage.success('保存成功')
    aliasDialogVisible.value = false
    await fetchAliases()
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

const removeAlias = async (row: any) => {
  try {
    await ElMessageBox.confirm(`确认删除别名「${row.alias}」？`, '提示', { type: 'warning' })
    await axios.delete(`${API}/aliases/${row.alias_id}`)
    ElMessage.success('已删除')
    await fetchAliases()
  } catch {}
}

// ---- 模型目录 ----
const catalog = ref<Record<string, any>>({})
const catalogLoaded = ref(false)
const syncing = ref(false)

const fetchCatalog = async () => {
  try {
    const res = await axios.get(`${API}/catalog`)
    catalog.value = res.data.data || {}
    catalogLoaded.value = true
  } catch {}
}

const syncCatalog = async (provider?: string) => {
  syncing.value = true
  try {
    const res = await axios.post(`${API}/catalog/sync`, provider ? { provider } : {})
    ElMessage.success('同步完成')
    console.info('[AiSettings] sync output:', res.data?.data?.output)
    await fetchCatalog()
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '同步失败')
  } finally {
    syncing.value = false
  }
}

// ---- 租户配置 ----
const tenants = ref<any[]>([])
const selectedTenantId = ref('')
const tenantConfig = reactive<any>({ text_enabled: true, image_enabled: true, video_enabled: true, monthly_budget_limit: 0, overage_action: 'block' })

const fetchTenants = async () => {
  try {
    const res = await axios.get(`${API}/tenants`)
    tenants.value = res.data.data || []
  } catch {}
}

const loadTenantConfig = async () => {
  if (!selectedTenantId.value) return
  try {
    const res = await axios.get(`${API}/tenants/${selectedTenantId.value}/config`)
    const data = res.data.data || {}
    if (data.configured && data.config) {
      Object.assign(tenantConfig, data.config)
    } else {
      Object.assign(tenantConfig, { text_enabled: true, image_enabled: true, video_enabled: true, monthly_budget_limit: 0, overage_action: 'block' })
    }
  } catch {}
}

const saveTenantConfig = async () => {
  saving.value = true
  try {
    await axios.put(`${API}/tenants/${selectedTenantId.value}/config`, tenantConfig)
    ElMessage.success('保存成功')
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

// ---- 连接测试 ----
const testProvider = ref('bailian')
const testing = ref(false)
const testResult = ref<{ ok: boolean; msg: string } | null>(null)

const runTest = async () => {
  testing.value = true
  testResult.value = null
  try {
    const res = await axios.post(`${API}/providers/${testProvider.value}/test`)
    const d = res.data.data || {}
    testResult.value = { ok: true, msg: `连接成功：${d.model_count} 个模型，延迟 ${d.latency_ms}ms（配置来源：${d.source === 'db' ? '后台 DB' : 'env'}）` }
  } catch (e: any) {
    testResult.value = { ok: false, msg: e.response?.data?.message || '连接失败' }
  } finally {
    testing.value = false
  }
}

onMounted(() => {
  fetchDefaults()
  fetchAliases()
  fetchCatalog()
  fetchTenants()
})
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.form-hint { font-size: 12px; color: #999; margin-left: 8px; }
</style>
