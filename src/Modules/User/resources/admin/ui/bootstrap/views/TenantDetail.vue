<template>
  <div class="detail-page">
    <div class="page-header">
      <h2>租户详情</h2>
      <button @click="router.push('/tenants')">返回列表</button>
    </div>

    <div class="panel" v-if="tenant">
      <div class="info-grid">
        <div class="info-item"><span>租户ID</span><span>{{ tenant.tenant_id }}</span></div>
        <div class="info-item"><span>名称</span><span>{{ tenant.name }}</span></div>
        <div class="info-item"><span>标识</span><span>{{ tenant.slug }}</span></div>
        <div class="info-item"><span>自定义域名</span><span>{{ tenant.domain || '-' }}</span></div>
        <div class="info-item"><span>状态</span><span :class="['badge', tenant.status === 'active' ? 'badge-success' : 'badge-info']">{{ tenant.status === 'active' ? '活跃' : '未激活' }}</span></div>
        <div class="info-item"><span>套餐</span><span>{{ tenant.subscription_plan }}</span></div>
        <div class="info-item"><span>总积分</span><span>{{ tenant.total_credits }}</span></div>
        <div class="info-item"><span>可用积分</span><span>{{ tenant.available_credits }}</span></div>
      </div>
    </div>

    <!-- 企微接入（阶段 C，11.4：能力包/许可台账/套餐切换/出口代理） -->
    <div class="panel" style="margin-top: 16px;" v-if="cap">
      <div class="panel-header">
        <h3>企微接入</h3>
        <span :class="['badge', modeBadgeClass]">{{ modeText }}</span>
      </div>

      <div class="info-grid">
        <div class="info-item"><span>接入模式</span><span>{{ modeText }}</span></div>
        <div class="info-item"><span>授权状态</span><span :class="['badge', cap.authorized ? 'badge-success' : 'badge-info']">{{ cap.authorized ? '已授权' : '未授权' }}</span></div>
        <div class="info-item"><span>当前套餐</span><span>{{ planName }}</span></div>
        <div class="info-item"><span>许可免费窗口</span><span>{{ trialText }}</span></div>
      </div>

      <h4>能力包</h4>
      <table class="data-table">
        <thead><tr><th>能力包</th><th>说明</th><th>状态</th></tr></thead>
        <tbody>
          <tr v-for="row in capabilityRows" :key="row.key">
            <td>{{ row.label }}</td>
            <td>{{ row.desc }}</td>
            <td><span :class="['badge', row.enabled ? 'badge-success' : 'badge-info']">{{ row.enabled ? '已开通' : '未开通' }}</span></td>
          </tr>
        </tbody>
      </table>

      <h4>许可台账</h4>
      <table class="data-table">
        <thead><tr><th>许可</th><th>配额</th><th>已用</th><th>状态</th></tr></thead>
        <tbody>
          <tr v-for="row in licenseRows" :key="row.label">
            <td>{{ row.label }}</td>
            <td>{{ row.limit === null || row.limit === undefined ? '不限' : row.limit }}</td>
            <td :class="{ 'over': row.over }">{{ row.used }}</td>
            <td><span :class="['badge', row.over ? 'badge-danger' : 'badge-success']">{{ row.over ? '超量' : '正常' }}</span></td>
          </tr>
        </tbody>
      </table>

      <div class="toolbar">
        <select v-model="changePlanId" class="form-control">
          <option value="" disabled>选择目标套餐</option>
          <option v-for="p in plans" :key="p.subscription_plan_id ?? p.id ?? p.plan_id" :value="p.subscription_plan_id ?? p.id ?? p.plan_id">{{ p.display_name || p.name }}</option>
        </select>
        <label class="cycle-radio"><input type="radio" value="monthly" v-model="changeCycle" /> 月付</label>
        <label class="cycle-radio"><input type="radio" value="yearly" v-model="changeCycle" /> 年付</label>
        <button class="primary-btn" :disabled="changing" @click="changePlan">{{ changing ? '切换中…' : '切换套餐' }}</button>
      </div>

      <h4>出口代理配置</h4>
      <div class="form-grid">
        <div class="form-group"><label>启用</label><input type="checkbox" v-model="proxy.enabled" /></div>
        <div class="form-group"><label>协议</label><select v-model="proxy.scheme" class="form-control"><option value="http">http</option><option value="https">https</option><option value="socks5">socks5</option></select></div>
        <div class="form-group"><label>Host</label><input v-model="proxy.host" /></div>
        <div class="form-group"><label>Port</label><input v-model="proxy.port" /></div>
        <div class="form-group"><label>用户名</label><input v-model="proxy.username" /></div>
        <div class="form-group"><label>密码</label><input v-model="proxy.password" type="password" :placeholder="proxy.has_password ? '已设置，留空不修改' : ''" /></div>
        <div class="form-group"><label>出口IP（客户需加入可信 IP）</label><input v-model="proxy.exit_ip" /></div>
      </div>
      <div class="panel-actions"><button class="primary-btn" :disabled="savingProxy" @click="saveProxy">{{ savingProxy ? '保存中…' : '保存代理配置' }}</button></div>
    </div>

    <div class="panel" style="margin-top: 16px;">
      <h3>成员列表</h3>
      <table class="data-table">
        <thead>
          <tr><th>用户ID</th><th>姓名</th><th>邮箱</th><th>角色</th><th>状态</th></tr>
        </thead>
        <tbody>
          <tr v-for="m in members" :key="m.user_id">
            <td>{{ m.user_id }}</td>
            <td>{{ m.name }}</td>
            <td>{{ m.email }}</td>
            <td><span :class="['badge', m.pivot?.role === 'tenant_admin' ? 'badge-warning' : 'badge-info']">{{ m.pivot?.role === 'tenant_admin' ? '管理员' : '普通用户' }}</span></td>
            <td><span :class="['badge', m.pivot?.is_active ? 'badge-success' : 'badge-danger']">{{ m.pivot?.is_active ? '激活' : '未激活' }}</span></td>
          </tr>
        </tbody>
      </table>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import axios from 'axios'

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
const modeBadgeClass = computed(() => ({ suite: 'badge-success', self: 'badge-warning', none: 'badge-info' } as any)[cap.value?.mode] || 'badge-info')
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
  if (!changePlanId.value) return alert('请选择目标套餐')
  changing.value = true
  try {
    const r = await axios.post(`/api/v1/admin/billing/subscriptions/${tenantId.value}/change-plan`, { plan_id: changePlanId.value, billing_cycle: changeCycle.value })
    alert(r.data.message || '切换成功')
    await fetchCapability()
  } catch (e: any) {
    alert(e.response?.data?.message || '切换失败')
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
    alert(r.data.message || '保存成功')
    await fetchProxy()
  } catch (e: any) {
    alert(e.response?.data?.message || '保存失败')
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
.page-header h2 { margin: 0; }
.page-header button { padding: 6px 14px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; background: var(--bg-color, #fff); cursor: pointer; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 20px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.panel h3 { margin: 0 0 16px; }
.panel h4 { margin: 18px 0 8px; font-size: 14px; }
.panel-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; }
.panel-header h3 { margin: 0; }
.info-grid { display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px; }
.info-item { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.info-item span:first-child { color: var(--text-color-secondary, #999); }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-success { background: var(--badge-success-bg); color: var(--badge-success-fg); }
.badge-info { background: var(--badge-info-bg); color: var(--badge-info-fg); }
.badge-warning { background: var(--badge-warning-bg); color: var(--badge-warning-fg); }
.badge-danger { background: var(--badge-danger-bg); color: var(--badge-danger-fg); }
.toolbar { display: flex; gap: 10px; align-items: center; margin: 14px 0 6px; flex-wrap: wrap; }
.toolbar select { padding: 6px 10px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; }
.cycle-radio { font-size: 13px; color: var(--text-color-secondary, #666); display: flex; align-items: center; gap: 4px; }
.primary-btn { padding: 8px 16px; background: var(--primary-color, #409eff); color: #fff; border: none; border-radius: 6px; cursor: pointer; }
.primary-btn:disabled { opacity: 0.6; cursor: not-allowed; }
.form-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 0 16px; }
.form-group { margin-bottom: 12px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input, .form-group select { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; box-sizing: border-box; }
.form-group input[type="checkbox"] { width: auto; }
.over { color: var(--link-danger, #f56c6c); font-weight: 600; }
.panel-actions { display: flex; justify-content: flex-end; margin-top: 4px; }
</style>