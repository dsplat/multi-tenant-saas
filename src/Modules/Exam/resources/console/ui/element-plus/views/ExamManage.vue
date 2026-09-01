<template>
  <div class="page-container">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>考试管理</span>
          <div class="header-actions">
            <el-button type="primary" @click="handleCreateExam"> 新建考试 </el-button>
          </div>
        </div>
      </template>

      <el-tabs v-model="activeTab">
        <el-tab-pane label="考试列表" name="exams">
          <el-table :data="exams" v-loading="loading" stripe>
            <el-table-column prop="exam_id" label="ID" width="80" />
            <el-table-column prop="title" label="考试标题" min-width="200" show-overflow-tooltip />
            <el-table-column label="组卷" width="90">
              <template #default="{ row }">{{ row.compose_rule?.mode === 'fixed' ? '固定' : '随机' }}</template>
            </el-table-column>
            <el-table-column label="总分/及格" width="110">
              <template #default="{ row }">{{ row.total_score }} / {{ row.pass_score }}</template>
            </el-table-column>
            <el-table-column prop="time_limit_minutes" label="限时(分)" width="90" />
            <el-table-column label="状态" width="90">
              <template #default="{ row }">
                <el-tag :type="statusTag(row.status)">{{ statusLabel(row.status) }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="230">
              <template #default="{ row }">
                <el-button v-if="row.status === 'draft'" link type="success" size="small" @click="handlePublish(row)">发布</el-button>
                <el-button v-if="row.status === 'published'" link type="warning" size="small" @click="handleClose(row)">关闭</el-button>
                <el-button link type="primary" size="small" @click="openRecords(row)">成绩</el-button>
                <el-button v-if="row.status !== 'closed'" link type="primary" size="small" @click="handleEditExam(row)">编辑</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="待批改" name="grading">
          <el-table :data="pendingRecords" v-loading="pendingLoading" stripe>
            <el-table-column prop="record_id" label="答卷ID" width="100" />
            <el-table-column prop="exam_id" label="考试ID" width="90" />
            <el-table-column prop="user_id" label="学员ID" width="90" />
            <el-table-column prop="attempt" label="次序" width="70" />
            <el-table-column prop="objective_score" label="客观分" width="90" />
            <el-table-column prop="submitted_at" label="提交时间" width="170" />
            <el-table-column label="操作" width="110">
              <template #default="{ row }">
                <el-button link type="primary" size="small" @click="openGrading(row)">批改</el-button>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>
      </el-tabs>
    </el-card>

    <!-- 考试编辑 -->
    <el-dialog v-model="examDialogVisible" :title="editingId ? '编辑考试' : '新建考试'" width="640px" :close-on-click-modal="false">
      <el-form ref="examFormRef" :model="examForm" :rules="examRules" label-width="110px">
        <el-form-item label="考试标题" prop="title">
          <el-input v-model="examForm.title" maxlength="255" />
        </el-form-item>
        <el-form-item label="组卷方式" prop="mode">
          <el-radio-group v-model="examForm.mode">
            <el-radio value="fixed">固定题目</el-radio>
            <el-radio value="random">题库随机</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="examForm.mode === 'fixed'" label="题目ID列表">
          <el-input v-model="examForm.questionIds" type="textarea" :rows="2" placeholder="逗号分隔的题目ID，按序组卷，如：101,102,103" />
        </el-form-item>
        <template v-else>
          <el-form-item v-for="(rule, i) in examForm.rules" :key="i" :label="`规则 ${i + 1}`">
            <div style="display: flex; gap: 8px; width: 100%">
              <el-input-number v-model="rule.bank_id" placeholder="题库ID" :min="1" :controls="false" style="width: 120px" />
              <el-select v-model="rule.type" style="width: 110px">
                <el-option label="单选" value="single" />
                <el-option label="多选" value="multi" />
                <el-option label="判断" value="judge" />
              </el-select>
              <el-input-number v-model="rule.count" :min="1" style="width: 100px" />
              <el-button @click="examForm.rules.splice(i, 1)">-</el-button>
            </div>
          </el-form-item>
          <el-form-item label="">
            <el-button size="small" @click="examForm.rules.push({ bank_id: 1, type: 'single', count: 5 })">+ 添加规则</el-button>
          </el-form-item>
        </template>
        <el-form-item label="总分" prop="total_score">
          <el-input-number v-model="examForm.total_score" :min="0" :precision="1" />
        </el-form-item>
        <el-form-item label="及格分" prop="pass_score">
          <el-input-number v-model="examForm.pass_score" :min="0" :precision="1" />
        </el-form-item>
        <el-form-item label="限时(分钟)">
          <el-input-number v-model="examForm.time_limit_minutes" :min="0" :step="5" />
          <span class="form-tip">0 = 不限时</span>
        </el-form-item>
        <el-form-item label="重考次数">
          <el-input-number v-model="examForm.retry_limit" :min="1" :step="1" />
          <span class="form-tip">允许参加总次数</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="examDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submitExam">确定</el-button>
      </template>
    </el-dialog>

    <!-- 成绩列表 -->
    <el-dialog v-model="recordsVisible" :title="`成绩列表 - ${recordsExam?.title ?? ''}`" width="860px">
      <el-table :data="records" v-loading="recordsLoading" size="small" max-height="420">
        <el-table-column prop="record_id" label="答卷ID" width="100" />
        <el-table-column prop="user_id" label="学员ID" width="90" />
        <el-table-column prop="attempt" label="次序" width="70" />
        <el-table-column prop="objective_score" label="客观分" width="90" />
        <el-table-column prop="subjective_score" label="主观分" width="90" />
        <el-table-column prop="total_score" label="总分" width="90" />
        <el-table-column label="结果" width="90">
          <template #default="{ row }">
            <el-tag :type="row.passed ? 'success' : 'danger'">{{ row.passed ? '通过' : '未过' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template #default="{ row }">{{ statusLabel(row.status) }}</template>
        </el-table-column>
        <el-table-column prop="submitted_at" label="提交时间" width="170" />
      </el-table>
    </el-dialog>

    <!-- 批改 -->
    <el-dialog v-model="gradingVisible" :title="`主观题批改 - 答卷 #${gradingDetail?.record_id ?? ''}`" width="760px" top="6vh" :close-on-click-modal="false">
      <div v-if="gradingDetail" v-loading="gradingLoading">
        <el-alert :title="`客观分 ${gradingDetail.objective_score}，对以下简答题逐题打分（满分见各题）`" type="info" :closable="false" style="margin-bottom: 12px" />
        <el-card v-for="(item, i) in gradingDetail.items" :key="item.question_id" shadow="never" style="margin-bottom: 12px">
          <div style="margin-bottom: 8px">
            <el-tag size="small">题目 #{{ item.question_id }}（满分 {{ item.max_score }}）</el-tag>
            <span style="margin-left: 8px">{{ item.content }}</span>
          </div>
          <div style="margin-bottom: 8px; color: var(--el-text-color-regular); white-space: pre-wrap">{{ item.answer_text || '（无文本作答）' }}</div>
          <div v-if="item.media.length" style="margin-bottom: 8px">
            <el-link v-for="(m, j) in item.media" :key="j" :href="m.url" target="_blank" type="primary" style="margin-right: 12px">附件{{ j + 1 }}（{{ m.type }}）</el-link>
          </div>
          <div style="display: flex; gap: 8px; align-items: center">
            <el-input-number v-model="gradeScores[item.question_id]" :min="0" :max="item.max_score" :precision="1" style="width: 120px" />
            <el-input v-model="gradeComments[item.question_id]" placeholder="评语（可选）" style="flex: 1" />
          </div>
        </el-card>
      </div>
      <template #footer>
        <el-button @click="gradingVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submitGrading">提交批改</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import {
  getExams, createExam, updateExam, publishExam, closeExam,
  getRecords, getPendingGrading, getGradingDetail, gradeRecord,
  type Exam, type ExamRecord, type GradingDetail,
} from '@modules/Exam/api/exam'

defineOptions({ name: 'ExamManage' })

const STATUS: Record<string, { label: string; tag: 'info' | 'success' | 'warning' }> = {
  draft: { label: '草稿', tag: 'info' },
  published: { label: '已发布', tag: 'success' },
  closed: { label: '已关闭', tag: 'warning' },
}

function statusLabel(v: string) {
  return STATUS[v]?.label ?? v
}

function statusTag(v: string) {
  return STATUS[v]?.tag ?? 'info'
}

// ========== 考试 ==========

const activeTab = ref('exams')
const exams = ref<Exam[]>([])
const loading = ref(false)
const submitting = ref(false)
const examDialogVisible = ref(false)
const examFormRef = ref<FormInstance>()
const editingId = ref<number | null>(null)

const examForm = reactive({
  title: '',
  mode: 'fixed' as 'fixed' | 'random',
  questionIds: '',
  rules: [{ bank_id: 1, type: 'single', count: 5 }] as Array<{ bank_id: number; type: string; count: number }>,
  total_score: 100,
  pass_score: 60,
  time_limit_minutes: 60,
  retry_limit: 3,
})

const examRules: FormRules = {
  title: [{ required: true, message: '请输入考试标题', trigger: 'blur' }],
}

async function loadExams() {
  loading.value = true
  try {
    exams.value = await getExams()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '获取考试列表失败')
  } finally {
    loading.value = false
  }
}

function handleCreateExam() {
  editingId.value = null
  Object.assign(examForm, {
    title: '', mode: 'fixed', questionIds: '',
    rules: [{ bank_id: 1, type: 'single', count: 5 }],
    total_score: 100, pass_score: 60, time_limit_minutes: 60, retry_limit: 3,
  })
  examDialogVisible.value = true
}

function handleEditExam(row: Exam) {
  editingId.value = row.exam_id
  const rule = row.compose_rule ?? {}
  Object.assign(examForm, {
    title: row.title,
    mode: rule.mode ?? 'fixed',
    questionIds: (rule.question_ids ?? []).join(','),
    rules: rule.rules?.length ? [...rule.rules] : [{ bank_id: 1, type: 'single', count: 5 }],
    total_score: Number(row.total_score),
    pass_score: Number(row.pass_score),
    time_limit_minutes: Number(row.time_limit_minutes) || 0,
    retry_limit: Number(row.retry_limit) || 1,
  })
  examDialogVisible.value = true
}

async function submitExam() {
  if (!examFormRef.value) return
  try { await examFormRef.value.validate() } catch { return }

  const compose: Exam['compose_rule'] = examForm.mode === 'fixed'
    ? { mode: 'fixed', question_ids: examForm.questionIds.split(/[,，\s]+/).filter(Boolean).map(Number) }
    : { mode: 'random', rules: examForm.rules }

  const payload = {
    title: examForm.title,
    compose_rule: compose,
    total_score: examForm.total_score,
    pass_score: examForm.pass_score,
    time_limit_minutes: examForm.time_limit_minutes,
    retry_limit: examForm.retry_limit,
  }

  submitting.value = true
  try {
    if (editingId.value !== null) {
      await updateExam(editingId.value, payload)
    } else {
      await createExam(payload as any)
    }
    ElMessage.success('操作成功')
    examDialogVisible.value = false
    await loadExams()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '操作失败')
  } finally {
    submitting.value = false
  }
}

async function handlePublish(row: Exam) {
  try {
    await ElMessageBox.confirm('发布后学员即可参加，确定发布？', '提示')
    await publishExam(row.exam_id)
    ElMessage.success('已发布')
    await loadExams()
  } catch (e: any) {
    if (e !== 'cancel') ElMessage.error(e?.response?.data?.message || '发布失败')
  }
}

async function handleClose(row: Exam) {
  try {
    await ElMessageBox.confirm('关闭后不可再参加，确定？', '提示', { type: 'warning' })
    await closeExam(row.exam_id)
    ElMessage.success('已关闭')
    await loadExams()
  } catch (e: any) {
    if (e !== 'cancel') ElMessage.error(e?.response?.data?.message || '关闭失败')
  }
}

// ========== 成绩 ==========

const recordsVisible = ref(false)
const recordsLoading = ref(false)
const recordsExam = ref<Exam | null>(null)
const records = ref<ExamRecord[]>([])

async function openRecords(row: Exam) {
  recordsExam.value = row
  recordsVisible.value = true
  recordsLoading.value = true
  try {
    records.value = await getRecords({ exam_id: row.exam_id })
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '获取成绩失败')
  } finally {
    recordsLoading.value = false
  }
}

// ========== 批改 ==========

const pendingRecords = ref<ExamRecord[]>([])
const pendingLoading = ref(false)
const gradingVisible = ref(false)
const gradingLoading = ref(false)
const gradingDetail = ref<GradingDetail | null>(null)
const gradeScores = reactive<Record<number, number | undefined>>({})
const gradeComments = reactive<Record<number, string>>({})

async function loadPending() {
  pendingLoading.value = true
  try {
    pendingRecords.value = await getPendingGrading()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '获取待批改列表失败')
  } finally {
    pendingLoading.value = false
  }
}

async function openGrading(row: ExamRecord) {
  gradingVisible.value = true
  gradingLoading.value = true
  try {
    gradingDetail.value = await getGradingDetail(row.record_id)
    for (const item of gradingDetail.value.items) {
      gradeScores[item.question_id] = item.max_score
      gradeComments[item.question_id] = ''
    }
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '获取批改详情失败')
    gradingVisible.value = false
  } finally {
    gradingLoading.value = false
  }
}

async function submitGrading() {
  if (!gradingDetail.value) return
  const scores = gradingDetail.value.items.map((item) => ({
    question_id: item.question_id,
    score: gradeScores[item.question_id] ?? 0,
    comment: gradeComments[item.question_id] || undefined,
  }))

  submitting.value = true
  try {
    await gradeRecord(gradingDetail.value.record_id, scores)
    ElMessage.success('批改完成')
    gradingVisible.value = false
    await loadPending()
    await loadExams()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '批改失败')
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  loadExams()
  loadPending()
})
</script>

<style scoped>
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.header-actions {
  display: flex;
  gap: 8px;
}

.form-tip {
  margin-left: 8px;
  color: var(--el-text-color-secondary);
  font-size: 12px;
  white-space: nowrap;
}
</style>
