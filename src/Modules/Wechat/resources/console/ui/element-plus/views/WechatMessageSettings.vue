<template>
  <div class="page">
    <div class="page-header"><h2>微信消息</h2></div>

    <!-- 消息能力状态（凭证双轨，只读） -->
    <el-card shadow="never" style="max-width: 860px; margin-bottom: 16px">
      <div class="mode-overview">
        <span class="mode-overview-label">消息凭证：</span>
        <el-tag v-if="status.credential_mode === 'component'" type="success" size="small">服务商模式（授权公众号）</el-tag>
        <el-tag v-else-if="status.credential_mode === 'self'" type="primary" size="small">自建应用</el-tag>
        <el-tag v-else type="info" size="small">未配置</el-tag>
        <span class="form-tip" style="margin-left: 8px">{{ modeTip }}</span>
      </div>
      <p class="form-tip" style="margin: 6px 0 0">
        模板消息与客服消息均仅<b>认证服务号</b>可用（订阅号会被微信拒绝）；客服消息需用户 48 小时内与公众号有互动；模板消息日调用上限 10 万次。
      </p>
    </el-card>

    <!-- 模板登记：业务 key → 微信模板 ID -->
    <el-card shadow="never" style="max-width: 860px; margin-bottom: 16px">
      <template #header>
        <div class="card-header-row">
          <span>模板登记（公众号后台「功能 → 模板消息」选用后，将模板 ID 填到此处）</span>
          <el-button type="primary" size="small" @click="dialogVisible = true">新增模板</el-button>
        </div>
      </template>

      <el-table :data="templates" size="small" empty-text="暂无模板登记">
        <el-table-column prop="template_key" label="业务标识" width="140" />
        <el-table-column prop="template_id" label="微信模板 ID" min-width="180" />
        <el-table-column prop="title" label="标题" min-width="140" show-overflow-tooltip />
        <el-table-column prop="content_example" label="参数示例" min-width="160" show-overflow-tooltip>
          <template #default="{ row }">{{ formatExample(row.content_example) }}</template>
        </el-table-column>
        <el-table-column label="操作" width="80" align="center">
          <template #default="{ row }">
            <el-button link type="danger" size="small" @click="destroyTemplate(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>

      <!-- 新增模板 -->
      <el-dialog v-model="dialogVisible" title="新增模板登记" width="480px">
        <el-form label-width="96px">
          <el-form-item label="业务标识" required>
            <el-input v-model="form.template_key" placeholder="如 order_paid（小写字母/数字/下划线）" />
          </el-form-item>
          <el-form-item label="微信模板 ID" required>
            <el-input v-model="form.template_id" placeholder="公众号后台复制的模板 ID（字母数字）" />
          </el-form-item>
          <el-form-item label="标题">
            <el-input v-model="form.title" placeholder="可选，备注用途" />
          </el-form-item>
          <el-form-item label="参数示例">
            <el-input v-model="form.content_example" type="textarea" :rows="3" placeholder='JSON，如 {"orderId":"订单号","amount":"金额"}' />
          </el-form-item>
        </el-form>
        <template #footer>
          <el-button @click="dialogVisible = false">取消</el-button>
          <el-button type="primary" :loading="savingTemplate" @click="saveTemplate">保存</el-button>
        </template>
      </el-dialog>
    </el-card>

    <!-- 测试发送 -->
    <el-card shadow="never" style="max-width: 860px; margin-bottom: 16px">
      <template #header><span>测试发送（直接调用微信，结果落发送记录）</span></template>

      <el-row :gutter="16">
        <el-col :span="12">
          <div class="send-block">
            <div class="send-block-title">模板消息</div>
            <el-form label-width="96px" size="small">
              <el-form-item label="OpenID">
                <el-input v-model="tplSend.openid" placeholder="接收用户的 openid" />
              </el-form-item>
              <el-form-item label="模板">
                <el-select v-model="tplSend.template_key" placeholder="选择业务标识" style="width: 100%">
                  <el-option v-for="t in templates" :key="t.template_key" :label="t.template_key + (t.title ? '（' + t.title + '）' : '')" :value="t.template_key" />
                </el-select>
              </el-form-item>
              <el-form-item label="参数">
                <el-input v-model="tplSend.data" type="textarea" :rows="3" placeholder='JSON，如 {"orderId":"NO20260901","amount":"99.00"}' />
              </el-form-item>
              <el-form-item label="跳转链接">
                <el-input v-model="tplSend.url" placeholder="可选，https:// 开头" />
              </el-form-item>
              <el-form-item>
                <el-button type="primary" size="small" :loading="tplSending" @click="sendTemplate">发送模板消息</el-button>
              </el-form-item>
            </el-form>
          </div>
        </el-col>
        <el-col :span="12">
          <div class="send-block">
            <div class="send-block-title">客服文本消息</div>
            <el-form label-width="96px" size="small">
              <el-form-item label="OpenID">
                <el-input v-model="custSend.openid" placeholder="接收用户的 openid" />
              </el-form-item>
              <el-form-item label="内容">
                <el-input v-model="custSend.content" type="textarea" :rows="5" placeholder="文本内容（微信要求用户 48 小时内与公众号有互动）" />
              </el-form-item>
              <el-form-item>
                <el-button type="primary" size="small" :loading="custSending" @click="sendCustom">发送客服消息</el-button>
              </el-form-item>
            </el-form>
          </div>
        </el-col>
      </el-row>
    </el-card>

    <!-- 发送记录 -->
    <el-card shadow="never" style="max-width: 860px">
      <template #header>
        <div class="card-header-row">
          <span>发送记录（最近 20 条）</span>
          <el-button size="small" @click="loadLogs">刷新</el-button>
        </div>
      </template>

      <el-table :data="logs" size="small" empty-text="暂无发送记录">
        <el-table-column label="类型" width="80">
          <template #default="{ row }">
            <el-tag size="small" :type="row.message_type === 'template' ? 'primary' : 'warning'">{{ row.message_type === 'template' ? '模板' : '客服' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag size="small" :type="row.status === 'success' ? 'success' : row.status === 'failed' ? 'danger' : 'info'">{{ statusLabel(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="template_key" label="业务标识" width="120" show-overflow-tooltip />
        <el-table-column prop="openid" label="OpenID" min-width="150" show-overflow-tooltip />
        <el-table-column prop="msg_id" label="微信 MsgID" min-width="120" show-overflow-tooltip />
        <el-table-column label="结果" min-width="180" show-overflow-tooltip>
          <template #default="{ row }">
            <span v-if="row.status === 'failed' && row.error_code" class="error-text">[{{ row.error_code }}] {{ row.error_message }}</span>
            <span v-else-if="row.status === 'failed'">发送失败</span>
            <span v-else>{{ row.created_at }}</span>
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'

// ─── 消息能力状态 ───────────────────
const status = reactive({ credential_mode: 'none', template_count: 0 })

const modeTip = computed(() => {
  switch (status.credential_mode) {
    case 'component':
      return '使用平台公众号授权记录换取 token，发送方为授权公众号。'
    case 'self':
      return '使用自建应用凭证换取 token，发送方为自建服务号。'
    default:
      return '请在「微信配置」页完成服务商授权或自建应用凭证配置。'
  }
})

const loadStatus = async () => {
  try {
    const res = await axios.get('/api/v1/tenant/wechat/messages/status')
    Object.assign(status, res.data.data || {})
  } catch {}
}

// ─── 模板登记 ───────────────────
const templates = ref<any[]>([])
const dialogVisible = ref(false)
const savingTemplate = ref(false)
const form = reactive({ template_key: '', template_id: '', title: '', content_example: '' })

const formatExample = (example: any) => {
  if (!example) return '—'
  return typeof example === 'string' ? example : JSON.stringify(example)
}

const loadTemplates = async () => {
  try {
    const res = await axios.get('/api/v1/tenant/wechat/messages/templates')
    templates.value = res.data.data || []
  } catch {}
}

const saveTemplate = async () => {
  if (!form.template_key || !form.template_id) {
    ElMessage.warning('请填写业务标识与微信模板 ID')
    return
  }
  savingTemplate.value = true
  try {
    let contentExample: any = null
    if (form.content_example) {
      try {
        contentExample = JSON.parse(form.content_example)
      } catch {
        ElMessage.warning('参数示例不是合法 JSON，已按文本保存')
        contentExample = form.content_example
      }
    }
    await axios.post('/api/v1/tenant/wechat/messages/templates', {
      template_key: form.template_key,
      template_id: form.template_id,
      title: form.title || null,
      content_example: contentExample,
    })
    ElMessage.success('模板已登记')
    dialogVisible.value = false
    Object.assign(form, { template_key: '', template_id: '', title: '', content_example: '' })
    await loadTemplates()
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '保存失败')
  } finally {
    savingTemplate.value = false
  }
}

const destroyTemplate = async (row: any) => {
  try {
    await ElMessageBox.confirm(`确认删除模板登记「${row.template_key}」？`, '提示', { type: 'warning' })
  } catch { return }
  try {
    await axios.delete(`/api/v1/tenant/wechat/messages/templates/${row.message_template_id}`)
    ElMessage.success('已删除')
    await loadTemplates()
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '删除失败')
  }
}

// ─── 测试发送 ───────────────────
const tplSend = reactive({ openid: '', template_key: '', data: '', url: '' })
const tplSending = ref(false)
const custSend = reactive({ openid: '', content: '' })
const custSending = ref(false)

const parseJson = (text: string, label: string): any => {
  if (!text.trim()) return {}
  try {
    return JSON.parse(text)
  } catch {
    ElMessage.warning(`${label}不是合法 JSON`)
    return null
  }
}

const sendTemplate = async () => {
  if (!tplSend.openid || !tplSend.template_key) {
    ElMessage.warning('请填写 OpenID 并选择模板')
    return
  }
  const data = parseJson(tplSend.data, '参数')
  if (data === null) return
  tplSending.value = true
  try {
    await axios.post('/api/v1/tenant/wechat/messages/templates/test', {
      openid: tplSend.openid,
      template_key: tplSend.template_key,
      data,
      url: tplSend.url || null,
    })
    ElMessage.success('模板消息发送成功')
    await loadLogs()
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '发送失败')
  } finally {
    tplSending.value = false
  }
}

const sendCustom = async () => {
  if (!custSend.openid || !custSend.content) {
    ElMessage.warning('请填写 OpenID 与内容')
    return
  }
  custSending.value = true
  try {
    await axios.post('/api/v1/tenant/wechat/messages/custom/send', {
      openid: custSend.openid,
      content: custSend.content,
    })
    ElMessage.success('客服消息发送成功')
    await loadLogs()
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '发送失败')
  } finally {
    custSending.value = false
  }
}

// ─── 发送记录 ───────────────────
const logs = ref<any[]>([])

const statusLabel = (s: string) => (s === 'success' ? '成功' : s === 'failed' ? '失败' : '发送中')

const loadLogs = async () => {
  try {
    const res = await axios.get('/api/v1/tenant/wechat/messages/logs')
    logs.value = res.data.data || []
  } catch {}
}

onMounted(() => {
  loadStatus()
  loadTemplates()
  loadLogs()
})
</script>

<style scoped>
.card-header-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
}
.send-block {
  padding: 0 8px;
}
.send-block-title {
  font-weight: 600;
  margin-bottom: 10px;
}
.error-text {
  color: var(--el-color-danger);
}
</style>
