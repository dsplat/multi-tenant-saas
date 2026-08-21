<template>
  <div class="page">
    <div class="page-header">
      <h2>活动日历</h2>
      <div class="toolbar">
        <el-button @click="goPlans">管理活动</el-button>
        <el-button type="primary" @click="openCreate()">＋ 新建事项</el-button>
      </div>
    </div>

    <div class="plan-cards">
      <span class="plan-card" :class="{ active: planFilter === '' }" @click="setFilter('')">
        <span class="plan-swatch all" />全部活动
      </span>
      <span
        v-for="p in plans"
        :key="p.plan_id"
        class="plan-card"
        :class="{ active: planFilter === p.plan_id }"
        @click="setFilter(p.plan_id)"
      >
        <span class="plan-swatch" :style="{ background: planColor(p.plan_id) }" />
        {{ planTitle(p) }}
      </span>
    </div>

    <div class="legend">
      <span class="legend-item"><i class="dot dot-pending" />待办</span>
      <span class="legend-item"><i class="dot dot-await" />待确认</span>
      <span class="legend-item"><i class="dot dot-running" />进行中</span>
      <span class="legend-item"><i class="dot dot-done" />已完成</span>
      <span class="legend-item">🔔 = 到点提醒</span>
    </div>

    <el-card shadow="never" v-loading="loading">
      <el-calendar v-model="current" @change="onMonthChange">
        <template #date-cell="{ data }">
          <div class="cell" @click="openCreate(data.date)">
            <span class="day-num">{{ data.date.getDate() }}</span>
            <div class="chips">
              <el-popover
                v-for="t in tasksByDate[fmtDay(data.date)] || []"
                :key="t.task_id"
                trigger="click"
                :width="240"
                @click.stop
              >
                <template #reference>
                  <span
                    class="chip"
                    :style="{
                      background: planColor(t.plan_id) + '1A',
                      borderColor: planColor(t.plan_id) + '66',
                      borderLeftColor: statusColor(t.status),
                    }"
                    @click.stop
                  >
                    <span class="chip-time">{{ fmtTime(t.scheduled_at) }}</span>
                    {{ t.title }}<template v-if="t.remind"> 🔔</template>
                  </span>
                </template>
                <div class="pop">
                  <div class="pop-title">{{ t.title }}</div>
                  <div class="pop-meta">{{ fmtFull(t.scheduled_at) }}</div>
                  <div class="pop-meta">
                    状态：{{ statusText(t.status) }}
                    <template v-if="t.remind"> · 到点提醒</template>
                  </div>
                  <div class="pop-meta">活动：{{ t.plan_name || '—' }}</div>
                  <div class="pop-actions">
                    <el-button v-if="t.status !== 'done'" size="small" type="primary" @click="markDone(t)">标记完成</el-button>
                    <el-button size="small" type="danger" plain @click="removeTask(t)">删除</el-button>
                  </div>
                </div>
              </el-popover>
            </div>
          </div>
        </template>
      </el-calendar>
    </el-card>

    <!-- 新建事项 -->
    <el-dialog v-model="dialogVisible" title="新建事项" width="440px">
      <el-form label-width="80px">
        <el-form-item label="标题" required>
          <el-input v-model="form.title" placeholder="要做的事，如：发布招生海报" maxlength="200" />
        </el-form-item>
        <el-form-item label="日期" required>
          <el-date-picker v-model="form.date" type="date" style="width: 100%" />
        </el-form-item>
        <el-form-item label="时间">
          <el-time-picker v-model="form.time" format="HH:mm" style="width: 100%" placeholder="默认 09:00" />
        </el-form-item>
        <el-form-item label="所属活动" required>
          <el-select v-model="form.plan_id" placeholder="选择活动" style="width: 100%">
            <el-option v-for="p in plans" :key="p.plan_id" :label="planTitle(p)" :value="p.plan_id" />
          </el-select>
        </el-form-item>
        <el-form-item label="到点提醒">
          <el-switch v-model="form.remind" />
          <span class="form-tip">开启后，到点会推送待办提醒（需确认后才算完成）</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="submitCreate">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, onMounted } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'

const router = useRouter()
const route = useRoute()
const API = '/api/v1/tenant/activity-plan'

// ---- 日期工具（无外部依赖） ----
const pad2 = (n: number) => String(n).padStart(2, '0')
const fmtDay = (d: Date) => `${d.getFullYear()}-${pad2(d.getMonth() + 1)}-${pad2(d.getDate())}`
const fmtDateStr = (s: string) => s.slice(0, 10)
const fmtTime = (s: string) => s.slice(11, 16)

interface Task {
  task_id: number
  plan_id: number
  plan_name: string
  title: string
  scheduled_at: string
  status: string
  remind: boolean
}

const loading = ref(false)
const saving = ref(false)
const current = ref(new Date())
const tasks = ref<Task[]>([])
const plans = ref<any[]>([])
const planFilter = ref<number | string>('')

const planTitle = (p: any) => p.plan_doc?.title || p.plan_doc?.name || `活动 ${p.plan_id}`

// 活动独立色：10 色调色板按 plan_id 取模稳定映射，同一活动各处颜色一致
const PLAN_COLORS = [
  '#409EFF', '#67C23A', '#E6A23C', '#F56C6C', '#8E44AD',
  '#16A085', '#D35400', '#2C3E50', '#C0392B', '#2980B9',
]
const planColor = (planId: number) => PLAN_COLORS[planId % PLAN_COLORS.length]

// 状态色条（chip 左侧），在活动浅色底上区分任务状态
const STATUS_COLORS: Record<string, string> = {
  pending: '#409EFF',
  awaiting_confirm: '#E6A23C',
  running: '#f59e0b',
  done: '#67C23A',
  failed: '#909399',
  cancelled: '#909399',
  skipped: '#909399',
}
const statusColor = (s: string) => STATUS_COLORS[s] || '#909399'

// 第一行活动卡片过滤：点击切换，重复点击取消回到全部
const setFilter = (id: number | string) => {
  planFilter.value = planFilter.value === id ? '' : id
  loadTasks()
}

const tasksByDate = computed(() => {
  const map: Record<string, Task[]> = {}
  for (const t of tasks.value) {
    if (!t.scheduled_at) continue
    const key = fmtDateStr(t.scheduled_at)
    ;(map[key] ||= []).push(t)
  }
  return map
})

const fmtFull = (s: string) => s.replace('T', ' ').slice(0, 16)

const statusText = (s: string) =>
  ({ pending: '待办', awaiting_confirm: '待确认', running: '进行中', done: '已完成', failed: '失败', skipped: '已跳过', cancelled: '已取消' }[s] || s)

const loadPlans = async () => {
  try {
    const res = await axios.get(`${API}/plans`)
    plans.value = res.data.data || []
  } catch {}
}

const loadTasks = async () => {
  loading.value = true
  try {
    const d = current.value
    const start = new Date(d.getFullYear(), d.getMonth(), 1 - 7)
    const end = new Date(d.getFullYear(), d.getMonth() + 1, 0 + 7)
    const params: Record<string, any> = { from: fmtDay(start), to: fmtDay(end) }
    if (planFilter.value) params.plan_id = planFilter.value
    const res = await axios.get(`${API}/tasks`, { params })
    tasks.value = res.data.data || []
  } catch {
    ElMessage.error('加载日历失败')
  } finally {
    loading.value = false
  }
}

const onMonthChange = () => loadTasks()

// ---- 新建事项 ----
const dialogVisible = ref(false)
const form = reactive<{ title: string; date: Date; time: Date; remind: boolean; plan_id: number | string }>({
  title: '',
  date: new Date(),
  time: new Date(new Date().setHours(9, 0, 0, 0)),
  remind: false,
  plan_id: '',
})

const openCreate = (date?: Date) => {
  form.title = ''
  form.date = date ? new Date(date) : new Date()
  form.time = new Date(new Date().setHours(9, 0, 0, 0))
  form.remind = false
  form.plan_id = planFilter.value || plans.value[0]?.plan_id || ''
  dialogVisible.value = true
}

const submitCreate = async () => {
  if (!form.title.trim()) return ElMessage.warning('请填写标题')
  if (!form.plan_id) return ElMessage.warning('请选择所属活动')
  const dd = form.date
  const tt = form.time
  const scheduled_at = `${dd.getFullYear()}-${pad2(dd.getMonth() + 1)}-${pad2(dd.getDate())} ${pad2(tt.getHours())}:${pad2(tt.getMinutes())}:00`
  saving.value = true
  try {
    await axios.post(`${API}/plans/${form.plan_id}/tasks`, {
      title: form.title.trim(),
      scheduled_at,
      remind: form.remind,
    })
    ElMessage.success('已添加')
    dialogVisible.value = false
    await loadTasks()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '添加失败')
  } finally {
    saving.value = false
  }
}

// ---- 任务操作 ----
const markDone = async (t: Task) => {
  try {
    await axios.patch(`${API}/tasks/${t.task_id}`, { status: 'done' })
    ElMessage.success('已完成')
    await loadTasks()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '操作失败')
  }
}

const removeTask = async (t: Task) => {
  try {
    await ElMessageBox.confirm(`删除事项「${t.title}」？`, '提示', { type: 'warning' })
  } catch {
    return
  }
  try {
    await axios.delete(`${API}/tasks/${t.task_id}`)
    ElMessage.success('已删除')
    await loadTasks()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '删除失败')
  }
}

const goPlans = () => router.push('/activity/plans')

onMounted(async () => {
  if (route.query.plan_id) planFilter.value = Number(route.query.plan_id)
  await loadPlans()
  await loadTasks()
})
</script>

<style scoped>
.page-header { display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; }
.page-header h2 { margin: 0; }
.toolbar { display: flex; gap: 8px; align-items: center; }
.plan-cards { display: flex; gap: 8px; align-items: center; margin-bottom: 12px; overflow-x: auto; padding-bottom: 2px; }
.plan-card { display: inline-flex; align-items: center; gap: 6px; padding: 4px 12px; border: 1px solid var(--el-border-color); border-radius: 16px; font-size: 13px; color: var(--el-text-color-regular); background: #fff; cursor: pointer; white-space: nowrap; transition: all .15s; }
.plan-card:hover { border-color: var(--el-color-primary); color: var(--el-color-primary); }
.plan-card.active { border-color: var(--el-color-primary); color: var(--el-color-primary); background: var(--el-color-primary-light-9); font-weight: 600; }
.plan-swatch { width: 10px; height: 10px; border-radius: 3px; display: inline-block; flex-shrink: 0; }
.plan-swatch.all { background: var(--el-text-color-secondary); }
.legend { display: flex; gap: 16px; align-items: center; margin-bottom: 12px; font-size: 13px; color: var(--el-text-color-regular); }
.legend-item { display: inline-flex; align-items: center; gap: 4px; }
.dot { width: 10px; height: 10px; border-radius: 50%; display: inline-block; }
.dot-pending { background: var(--el-color-primary); }
.dot-await { background: var(--el-color-warning); }
.dot-running { background: #f59e0b; }
.dot-done { background: var(--el-color-success); }
.cell { min-height: 78px; cursor: pointer; }
.day-num { font-size: 13px; color: var(--el-text-color-secondary); }
.chips { display: flex; flex-direction: column; gap: 2px; margin-top: 2px; }
.chip { font-size: 12px; line-height: 1.4; padding: 1px 5px 1px 7px; border-radius: 4px; color: #303133; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; cursor: pointer; border: 1px solid #dcdfe6; border-left-width: 3px; }
.chip-time { opacity: .85; margin-right: 2px; }
.pop-title { font-weight: 600; margin-bottom: 6px; }
.pop-meta { font-size: 12px; color: var(--el-text-color-secondary); margin-bottom: 2px; }
.pop-actions { margin-top: 10px; display: flex; gap: 8px; }
.form-tip { font-size: 12px; color: var(--el-text-color-secondary); margin-left: 8px; }
:deep(.el-calendar-day) { height: auto; min-height: 84px; padding: 6px 8px; }
</style>
