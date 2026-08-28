<template>
  <div class="detail-page">
    <div class="page-header">
      <h2>租户详情</h2>
      <el-button @click="router.push('/tenants')">返回列表</el-button>
    </div>

    <el-card v-if="tenant" shadow="never" style="margin-bottom: 16px">
      <template #header>租户信息</template>
      <el-descriptions :column="2" border>
        <el-descriptions-item label="租户ID">{{ tenant.tenant_id }}</el-descriptions-item>
        <el-descriptions-item label="名称">{{ tenant.name }}</el-descriptions-item>
        <el-descriptions-item label="标识">{{ tenant.slug }}</el-descriptions-item>
        <el-descriptions-item label="自定义域名">{{ tenant.domain || '-' }}</el-descriptions-item>
        <el-descriptions-item label="状态">
          <el-tag :type="tenant.status === 'active' ? 'success' : 'info'" size="small">
            {{ tenant.status === 'active' ? '活跃' : '未激活' }}
          </el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="套餐">{{ tenant.subscription_plan }}</el-descriptions-item>
        <el-descriptions-item label="总积分">{{ tenant.total_credits }}</el-descriptions-item>
        <el-descriptions-item label="可用积分">{{ tenant.available_credits }}</el-descriptions-item>
      </el-descriptions>
    </el-card>

    <!-- 企微接入（阶段 C，11.4：能力包/许可台账/套餐切换/出口代理） -->
    <el-card v-if="cap" shadow="never" style="margin-bottom: 16px">
      <template #header>
        <div style="display: flex; justify-content: space-between; align-items: center">
          <span>企微接入</span>
          <el-tag :type="modeTag" size="small">{{ modeText }}</el-tag>
        </div>
      </template>

      <el-descriptions :column="3" border size="small" style="margin-bottom: 16px">
        <el-descriptions-item label="接入模式">{{ modeText }}</el-descriptions-item>
        <el-descriptions-item label="授权状态">
          <el-tag :type="cap.authorized ? 'success' : 'info'" size="small">{{ cap.authorized ? '已授权' : '未授权' }}</el-tag>
        </el-descriptions-item>
        <el-descriptions-item label="当前套餐">{{ planName }}</el-descriptions-item>
        <el-descriptions-item label="许可免费窗口">{{ trialText }}</el-descriptions-item>
      </el-descriptions>

      <el-table :data="capabilityRows" size="small" style="margin-bottom: 16px">
        <el-table-column prop="label" label="能力包" width="110" />
        <el-table-column prop="desc" label="说明" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.enabled ? 'success' : 'info'" size="small">{{ row.enabled ? '已开通' : '未开通' }}</el-tag>
          </template>
        </el-table-column>
      </el-table>

      <el-table :data="licenseRows" size="small" style="margin-bottom: 16px">
        <el-table-column prop="label" label="许可台账" width="110" />
        <el-table-column label="配额" width="120">
          <template #default="{ row }">{{ limitText(row.limit) }}</template>
        </el-table-column>
        <el-table-column label="已用" width="120">
          <template #default="{ row }">
            <span :style="{ color: row.over ? '#f56c6c' : 'inherit' }">{{ row.used }}</span>
          </template>
        </el-table-column>
        <el-table-column label="状态">
          <template #default="{ row }">
            <el-tag v-if="row.over" type="danger" size="small">超量</el-tag>
            <el-tag v-else type="success" size="small">正常</el-tag>
          </template>
        </el-table-column>
      </el-table>

      <div style="display: flex; gap: 8px; align-items: center; margin-bottom: 16px">
        <el-select v-model="changePlanId" placeholder="选择目标套餐" style="width: 200px">
          <el-option
            v-for="p in plans"
            :key="p.subscription_plan_id ?? p.id ?? p.plan_id"
            :label="p.display_name || p.name"
            :value="p.subscription_plan_id ?? p.id ?? p.plan_id"
          />
        </el-select>
        <el-radio-group v-model="changeCycle">
          <el-radio value="monthly">月付</el-radio>
          <el-radio value="yearly">年付</el-radio>
        </el-radio-group>
        <el-button type="primary" :loading="changing" @click="changePlan">切换套餐</el-button>
      </div>

      <el-divider content-position="left">出口代理配置</el-divider>
      <el-form :model="proxy" label-width="90px" style="max-width: 720px">
        <el-row :gutter="12">
          <el-col :span="6"><el-form-item label="启用"><el-switch v-model="proxy.enabled" /></el-form-item></el-col>
          <el-col :span="6"><el-form-item label="协议"><el-select v-model="proxy.scheme" style="width: 100%"><el-option value="http">http</el-option><el-option value="https">https</el-option><el-option value="socks5">socks5</el-option></el-select></el-form-item></el-col>
          <el-col :span="6"><el-form-item label="Host"><el-input v-model="proxy.host" /></el-form-item></el-col>
          <el-col :span="6"><el-form-item label="Port"><el-input v-model="proxy.port" /></el-form-item></el-col>
        </el-row>
        <el-row :gutter="12">
          <el-col :span="8"><el-form-item label="用户名"><el-input v-model="proxy.username" /></el-form-item></el-col>
          <el-col :span="8"><el-form-item label="密码"><el-input v-model="proxy.password" :placeholder="proxy.has_password ? '已设置，留空不修改' : ''" show-password /></el-form-item></el-col>
          <el-col :span="8"><el-form-item label="出口IP"><el-input v-model="proxy.exit_ip" placeholder="客户需加入企业可信 IP" /></el-form-item></el-col>
        </el-row>
        <el-form-item>
          <el-button type="primary" :loading="savingProxy" @click="saveProxy">保存代理配置</el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never">
      <template #header>成员列表</template>
      <el-table :data="members" stripe style="width: 100%" empty-text="暂无成员">
        <el-table-column prop="user_id" label="用户ID" width="80" />
        <el-table-column prop="name" label="姓名" />
        <el-table-column prop="email" label="邮箱" />
        <el-table-column label="角色" width="120">
          <template #default="{ row }">
            <el-tag :type="row.pivot?.role === 'tenant_admin' ? 'warning' : 'info'" size="small">
              {{ row.pivot?.role === 'tenant_admin' ? '管理员' : '普通用户' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="row.pivot?.is_active ? 'success' : 'danger'" size="small">
              {{ row.pivot?.is_active ? '激活' : '未激活' }}
            </el-tag>
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'
import { ElMessage } from 'element-plus'

const route = useRoute()
const router = useRouter()
const tenant = ref<any>(null)
const members = ref<any[]>([])
const cap = ref<any>(null)
const plans = ref<any[]>([])
const changePlanId = ref<number | string>('')
const changeCycle = ref('monthly')
const changing = ref(false)
const savingProxy = ref(false)
const proxy = ref<any>({ enabled: false, scheme: 'http', host: '', port: '', username: '', password: '', exit_ip: '', has_password: false })

const tenantId = computed(() => route.params.id as string)

const modeText = computed(() => ({ suite: '服务商代开发', self: '自建应用', none: '未接入' } as any)[cap.value?.mode] || '未接入')
const modeTag = computed(() => ({ suite: 'success', self: 'warning', none: 'info' } as any)[cap.value?.mode] || 'info')
const planName = computed(() => cap.value?.plan ? (cap.value.plan.display_name || cap.value.plan.name) : '-')
const trialText = computed(() => cap.value?.free_trial_ends_at ? `至 ${cap.value.free_trial_ends_at.slice(0, 10)}` : '-')

const capabilityRows = computed(() => {
  if (!cap.value) return []
  const defs = [
    { key: 'base', label: '基础能力', desc: '登录 / 应用消息 / ibot / 内部群' },
    { key: 'intercom', label: '互通能力', desc: '客户 / 客户群 / 群发 / 客服' },
    { key: 'self', label: '自建应用', desc: '出口 IP 独享 / 完整权限' },
    { key: 'archive', label: '会话存档', desc: '仅自建模式可用' },
  ]
  return defs.map(d => ({ ...d, enabled: !!cap.value.features?.[d.key] }))
})

const limitText = (v: any) => (v === null || v === undefined ? '不限' : v)

const licenseRows = computed(() => {
  if (!cap.value) return []
  const l = cap.value.limits || {}
  const u = cap.value.usage || {}
  const rows = [
    { label: '基础许可', limit: l.wechat_work_license_basic, used: u.license_basic_used ?? 0 },
    { label: '互通许可', limit: l.wechat_work_license_intercom, used: u.license_intercom_used ?? 0 },
    { label: '出口 IP', limit: l.wechat_work_proxy_ips, used: u.proxy_ip ? 1 : 0 },
  ]
  return rows.map(r => ({ ...r, over: r.limit !== null && r.limit !== undefined && r.used > r.limit }))
})

const fetchCapability = async () => {
  try {
    const r = await axios.get(`/api/v1/admin/wechat-work/capabilities/${tenantId.value}`)
    cap.value = r.data.data
  } catch { /* 无 setting.view 权限或模块未装则不渲染区块 */ }
}

const fetchPlans = async () => {
  try {
    const r = await axios.get('/api/v1/admin/billing/plans')
    plans.value = r.data.data || []
  } catch { /* 忽略 */ }
}

const fetchProxy = async () => {
  try {
    const r = await axios.get(`/api/v1/admin/wechat-work/proxy/${tenantId.value}`)
    const d = r.data.data || {}
    proxy.value = { enabled: !!d.enabled, scheme: d.scheme || 'http', host: d.host || '', port: d.port || '', username: d.username || '', password: '', exit_ip: d.exit_ip || '', has_password: !!d.has_password }
  } catch { /* 忽略 */ }
}

const changePlan = async () => {
  if (!changePlanId.value) return ElMessage.warning('请选择目标套餐')
  changing.value = true
  try {
    const r = await axios.post(`/api/v1/admin/billing/subscriptions/${tenantId.value}/change-plan`, { plan_id: changePlanId.value, billing_cycle: changeCycle.value })
    ElMessage.success(r.data.message || '切换成功')
    await fetchCapability()
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '切换失败')
  } finally {
    changing.value = false
  }
}

const saveProxy = async () => {
  savingProxy.value = true
  try {
    const r = await axios.put(`/api/v1/admin/wechat-work/proxy/${tenantId.value}`, {
      enabled: proxy.value.enabled,
      scheme: proxy.value.scheme,
      host: proxy.value.host,
      port: Number(proxy.value.port) || null,
      username: proxy.value.username,
      password: proxy.value.password,
      exit_ip: proxy.value.exit_ip,
    })
    ElMessage.success(r.data.message || '保存成功')
    await fetchProxy()
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '保存失败')
  } finally {
    savingProxy.value = false
  }
}

onMounted(async () => {
  try {
    const res = await axios.get(`/api/v1/tenants/${tenantId.value}`)
    tenant.value = res.data.data
  } catch {}
  try {
    const res = await axios.get(`/api/v1/tenants/${tenantId.value}/members`)
    members.value = res.data.data || []
  } catch {}
  fetchCapability()
  fetchPlans()
  fetchProxy()
})
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
</style>