<script setup lang="ts">
/**
 * ActionConfirmCard — L2 低风险写操作确认卡片
 *
 * AI 代操作的人类确认点：AI 想执行写操作（L2）时，后端不直接执行，
 * 而是签发一次性 confirm_token 并下发本卡片。用户核对参数后：
 *  - 点「确认执行」→ 携带 token+args_hash 请求 confirm-action 端点，服务端执行并让 LLM 续答
 *  - 点「取消」→ 同样消费令牌使其作废，回传取消结果
 *
 * 铁律落地：
 *  - 可控制：必须用户确认才执行，AI 无法跳过
 *  - 可理解：卡片明列即将执行的操作与参数
 *  - AI 产出必标注：明确标记「AI 代操作 · 需你确认」
 *  - 令牌过期（expires_in）后卡片自动失效
 */
import { ref, computed, watch, onMounted, onUnmounted } from 'vue'
import axios from 'axios'
import type { ActionConfirmData, ActionConfirmStatus } from '../types'
import { CONFIRM_ACTION_ENDPOINT } from '../composables/useAssistantStream'
import { toolLabel } from '../utils/toolLabels'

const props = defineProps<{
  data: ActionConfirmData
  status?: ActionConfirmStatus
  feedback?: string | null
}>()

const emit = defineEmits<{
  (e: 'resolved', payload: { status: ActionConfirmStatus; feedback: string | null; assistantMessage: string }): void
}>()

/**
 * 交互态以本地 localStatus 为准：点击后立即置 confirming 展示等待动画。
 * 不能用 props.status || localStatus：父级 store 下发的 confirmStatus 恒为
 * 非空（如 'pending'），会永久覆盖本地态，导致等待动画永不出现。
 * 父级终态（执行后 store 更新）经 watch 同步回本地，历史恢复/外部更新不受影响。
 */
const localStatus = ref<ActionConfirmStatus>(props.status || 'pending')
const status = computed<ActionConfirmStatus>(() => localStatus.value)
watch(() => props.status, (v) => {
  if (v && v !== localStatus.value) localStatus.value = v
})

/** 剩余有效秒数倒计时 */
const remaining = ref<number>(props.data.expires_in ?? 300)
let timer: ReturnType<typeof setInterval> | null = null

const fieldEntries = computed(() =>
  Object.entries(props.data.arguments || {}).map(([key, value]) => ({
    key,
    value: typeof value === 'object' ? JSON.stringify(value) : String(value ?? ''),
  })),
)

const isPending = computed(() => status.value === 'pending' && remaining.value > 0)

/** 等待执行中（已点确认/取消，请求未回）：展示加载动画，防止操作员以为没反应而二次点击 */
const isConfirming = computed(() => status.value === 'confirming')

const statusLabel = computed(() => {
  switch (status.value) {
    case 'confirming': return '执行中…'
    case 'executed': return '✓ 已执行'
    case 'cancelled': return '已取消'
    case 'expired': return '已过期，请重新发起'
    case 'error': return '执行失败'
    default: return remaining.value > 0 ? `${remaining.value}s 后失效` : '已过期，请重新发起'
  }
})

async function submit(confirmed: boolean) {
  if (!isPending.value && confirmed) return
  if (status.value === 'confirming' || status.value === 'executed') return

  localStatus.value = 'confirming'

  try {
    const headers: Record<string, string> = { 'Content-Type': 'application/json' }
    const auth = axios.defaults.headers.common['Authorization']
    if (auth) headers['Authorization'] = String(auth)
    const tenant = axios.defaults.headers.common['X-Tenant-ID']
    if (tenant) headers['X-Tenant-ID'] = String(tenant)

    const { data: resp } = await axios.post(CONFIRM_ACTION_ENDPOINT, {
      token: props.data.token,
      conversation_id: props.data.conversation_id,
      args_hash: props.data.args_hash,
      confirmed,
    }, { headers })

    const assistantMessage = String(resp?.data?.assistant_message || '')

    if (!confirmed) {
      localStatus.value = 'cancelled'
      emit('resolved', { status: 'cancelled', feedback: '已取消该操作', assistantMessage })
      return
    }

    if (resp?.data?.executed) {
      localStatus.value = 'executed'
      emit('resolved', { status: 'executed', feedback: '操作已执行', assistantMessage })
    } else {
      localStatus.value = 'error'
      emit('resolved', { status: 'error', feedback: String(resp?.data?.error || '执行失败'), assistantMessage })
    }
  } catch (e: any) {
    const msg = e?.response?.data?.message || '确认请求失败，请重试。'
    localStatus.value = 'error'
    emit('resolved', { status: 'error', feedback: String(msg), assistantMessage: '' })
  }
}

onMounted(() => {
  timer = setInterval(() => {
    if (remaining.value > 0) remaining.value -= 1
    if (remaining.value <= 0 && timer) {
      clearInterval(timer)
      timer = null
    }
  }, 1000)
})

onUnmounted(() => {
  if (timer) clearInterval(timer)
})
</script>

<template>
  <div class="action-confirm-card" :class="{ resolved: status !== 'pending' && status !== 'confirming', danger: status === 'error' }">
    <div class="card-badge">🤖 AI 代操作 · 需你确认</div>

    <div class="card-title">即将执行：<b>{{ toolLabel(data.tool_slug, data.tool_name) }}</b></div>

    <!-- 参数摘要 -->
    <div v-if="fieldEntries.length" class="card-fields">
      <div v-for="field in fieldEntries" :key="field.key" class="field-row">
        <span class="field-key">{{ field.key }}</span>
        <span class="field-value">{{ field.value }}</span>
      </div>
    </div>

    <!-- 操作按钮 / 等待态 / 结果态 -->
    <div class="card-actions">
      <template v-if="isConfirming">
        <button class="btn-confirm loading" disabled><span class="spinner"></span>执行中，请稍候…</button>
      </template>
      <template v-else-if="isPending">
        <button class="btn-confirm" @click="submit(true)">✓ 确认执行</button>
        <button class="btn-cancel" @click="submit(false)">取消</button>
        <span class="countdown">{{ statusLabel }}</span>
      </template>
      <template v-else>
        <span class="status-label" :class="status">{{ feedback || statusLabel }}</span>
      </template>
    </div>
  </div>
</template>

<style scoped>
.action-confirm-card {
  border: 1px solid color-mix(in srgb, var(--badge-warning-fg, #fa8c16) 40%, transparent);
  border-radius: 10px;
  padding: 12px;
  margin: 8px 0;
  background: color-mix(in srgb, var(--badge-warning-fg, #fa8c16) 6%, var(--bg-color, #fff));
}
.action-confirm-card.resolved {
  opacity: 0.75;
  border-color: var(--border-color, #e2e8f0);
  background: var(--bg-color, #fff);
}
.action-confirm-card.danger {
  border-color: color-mix(in srgb, var(--badge-danger-fg, #f5222d) 40%, transparent);
}

.card-badge {
  font-size: 10px;
  font-weight: 600;
  color: var(--badge-warning-fg, #fa8c16);
  margin-bottom: 6px;
}
.card-title {
  font-size: 13px;
  color: var(--text-color-primary, #0f172a);
  margin-bottom: 10px;
}

.card-fields { display: flex; flex-direction: column; gap: 4px; }
.field-row {
  display: flex; align-items: center; gap: 8px;
  font-size: 12px; padding: 3px 6px;
  border-radius: 4px; background: var(--fill-color, #f8fafc);
}
.field-key {
  font-weight: 500; color: var(--text-color-primary, #0f172a);
  min-width: 72px; flex-shrink: 0;
}
.field-value {
  color: var(--text-color-secondary, #64748b);
  overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
}

.card-actions {
  display: flex; align-items: center; gap: 10px;
  margin-top: 10px;
}
.btn-confirm {
  padding: 6px 14px; border-radius: 6px; font-size: 12px;
  border: none; cursor: pointer; font-weight: 500;
  background: var(--ac, #10b981); color: #fff;
  transition: opacity 0.15s;
}
.btn-confirm:hover { opacity: 0.85; }
.btn-confirm.loading {
  display: inline-flex; align-items: center; gap: 6px;
  opacity: 0.8; cursor: wait;
}
.spinner {
  width: 12px; height: 12px; flex-shrink: 0;
  border: 2px solid rgba(255, 255, 255, 0.35);
  border-top-color: #fff;
  border-radius: 50%;
  animation: card-spin 0.7s linear infinite;
}
@keyframes card-spin { to { transform: rotate(360deg); } }
.btn-cancel {
  padding: 6px 12px; border-radius: 6px; font-size: 12px;
  border: 1px solid var(--border-color, #e2e8f0); cursor: pointer;
  background: transparent; color: var(--text-color-secondary, #64748b);
}
.btn-cancel:hover { background: var(--fill-color, #f8fafc); }
.countdown { font-size: 10px; color: var(--text-color-secondary, #64748b); }
.status-label { font-size: 12px; font-weight: 500; color: var(--text-color-secondary, #64748b); }
.status-label.executed { color: #52c41a; }
.status-label.error { color: var(--badge-danger-fg, #f5222d); }
.status-label.cancelled { color: var(--text-color-secondary, #64748b); }
</style>
