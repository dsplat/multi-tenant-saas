<template>
  <div class="page-container">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>题库管理</span>
          <div class="header-actions">
            <el-button type="primary" @click="handleCreateBank"> 新建题库 </el-button>
          </div>
        </div>
      </template>

      <el-table :data="banks" v-loading="loading" stripe>
        <el-table-column prop="bank_id" label="ID" width="90" />
        <el-table-column prop="name" label="题库名称" min-width="180" />
        <el-table-column prop="description" label="描述" min-width="220" show-overflow-tooltip />
        <el-table-column label="操作" width="220">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openQuestions(row)">题目管理</el-button>
            <el-button link type="success" size="small" @click="openImport(row)">批量导入</el-button>
            <el-button link type="danger" size="small" @click="handleDeleteBank(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 题库编辑 -->
    <el-dialog v-model="bankDialogVisible" title="新建题库" width="480px" :close-on-click-modal="false">
      <el-form ref="bankFormRef" :model="bankForm" :rules="bankRules" label-width="90px">
        <el-form-item label="名称" prop="name">
          <el-input v-model="bankForm.name" placeholder="请输入题库名称" maxlength="255" />
        </el-form-item>
        <el-form-item label="描述">
          <el-input v-model="bankForm.description" type="textarea" :rows="2" placeholder="题库描述（可选）" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="bankDialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submitBank">确定</el-button>
      </template>
    </el-dialog>

    <!-- 题目管理 -->
    <el-dialog v-model="questionVisible" :title="`题目管理 - ${currentBank?.name ?? ''}`" width="860px" top="6vh">
      <div style="margin-bottom: 12px; display: flex; gap: 8px">
        <el-select v-model="questionTypeFilter" placeholder="全部题型" clearable style="width: 140px" @change="loadQuestions">
          <el-option v-for="t in TYPE_OPTIONS" :key="t.value" :label="t.label" :value="t.value" />
        </el-select>
        <el-button type="primary" size="small" @click="handleAddQuestion">添加题目</el-button>
      </div>
      <el-table :data="questions" v-loading="questionLoading" size="small" max-height="420">
        <el-table-column prop="question_id" label="ID" width="80" />
        <el-table-column label="题型" width="80">
          <template #default="{ row }">{{ typeLabel(row.type) }}</template>
        </el-table-column>
        <el-table-column prop="content" label="题干" min-width="240" show-overflow-tooltip />
        <el-table-column prop="score" label="分值" width="70" />
        <el-table-column label="操作" width="80">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="viewQuestion(row)">详情</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-dialog>

    <!-- 题目编辑 -->
    <el-dialog v-model="questionFormVisible" title="添加题目" width="640px" :close-on-click-modal="false">
      <el-form ref="questionFormRef" :model="questionForm" :rules="questionRules" label-width="90px">
        <el-form-item label="题型" prop="type">
          <el-radio-group v-model="questionForm.type" @change="questionForm.answer = questionForm.type === 'judge' ? true : null">
            <el-radio v-for="t in TYPE_OPTIONS" :key="t.value" :value="t.value">{{ t.label }}</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="题干" prop="content">
          <el-input v-model="questionForm.content" type="textarea" :rows="3" placeholder="请输入题干" />
        </el-form-item>
        <template v-if="questionForm.type === 'single' || questionForm.type === 'multi'">
          <el-form-item label="选项">
            <div style="width: 100%">
              <el-input v-for="(_, i) in questionForm.options" :key="i" v-model="questionForm.options[i]" style="margin-bottom: 6px">
                <template #prepend>{{ String.fromCharCode(65 + i) }}</template>
                <template #append>
                  <el-button :disabled="questionForm.options.length <= 2" @click="questionForm.options.splice(i, 1)">-</el-button>
                </template>
              </el-input>
              <el-button size="small" @click="questionForm.options.push('')">+ 添加选项</el-button>
            </div>
          </el-form-item>
          <el-form-item label="答案" prop="answer">
            <el-select v-if="questionForm.type === 'single'" v-model="questionForm.answer" placeholder="正确选项">
              <el-option v-for="(opt, i) in questionForm.options" :key="i" :label="String.fromCharCode(65 + i)" :value="i" />
            </el-select>
            <el-select v-else v-model="questionForm.answer" multiple placeholder="正确选项（可多选）">
              <el-option v-for="(opt, i) in questionForm.options" :key="i" :label="String.fromCharCode(65 + i)" :value="i" />
            </el-select>
          </el-form-item>
        </template>
        <el-form-item v-if="questionForm.type === 'judge'" label="答案" prop="answer">
          <el-radio-group v-model="questionForm.answer">
            <el-radio :value="true">正确</el-radio>
            <el-radio :value="false">错误</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="questionForm.type === 'essay'" label="答案说明">
          <el-input v-model="questionForm.analysis" type="textarea" :rows="2" placeholder="评分要点/参考答案（可选，靠人工批改）" />
        </el-form-item>
        <el-form-item label="解析">
          <el-input v-model="questionForm.analysis" type="textarea" :rows="2" placeholder="答案解析（可选）" />
        </el-form-item>
        <el-form-item label="分值" prop="score">
          <el-input-number v-model="questionForm.score" :min="0" :precision="1" :step="1" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="questionFormVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submitQuestion">确定</el-button>
      </template>
    </el-dialog>

    <!-- 批量导入 -->
    <el-dialog v-model="importVisible" title="批量导入题目（JSON 数组）" width="640px" :close-on-click-modal="false">
      <el-input v-model="importText" type="textarea" :rows="12" placeholder='[{"type":"single","content":"...","options":["A","B"],"answer":0,"score":10}]' />
      <template #footer>
        <el-button @click="importVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submitImport">导入</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import {
  getBanks, createBank, deleteBank,
  getQuestions, createQuestion, importQuestions,
  type ExamBank, type ExamQuestion,
} from '@modules/Exam/api/exam'

defineOptions({ name: 'ExamBanks' })

const TYPE_OPTIONS = [
  { label: '单选', value: 'single' },
  { label: '多选', value: 'multi' },
  { label: '判断', value: 'judge' },
  { label: '简答', value: 'essay' },
]

function typeLabel(value: string) {
  return TYPE_OPTIONS.find((t) => t.value === value)?.label ?? value
}

// ========== 题库 ==========

const banks = ref<ExamBank[]>([])
const loading = ref(false)
const submitting = ref(false)
const bankDialogVisible = ref(false)
const bankFormRef = ref<FormInstance>()
const bankForm = reactive({ name: '', description: '' })
const bankRules: FormRules = { name: [{ required: true, message: '请输入题库名称', trigger: 'blur' }] }

async function loadBanks() {
  loading.value = true
  try {
    banks.value = await getBanks()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '获取题库失败')
  } finally {
    loading.value = false
  }
}

function handleCreateBank() {
  bankForm.name = ''
  bankForm.description = ''
  bankDialogVisible.value = true
}

async function submitBank() {
  if (!bankFormRef.value) return
  try { await bankFormRef.value.validate() } catch { return }
  submitting.value = true
  try {
    await createBank({ name: bankForm.name, description: bankForm.description || undefined })
    ElMessage.success('创建成功')
    bankDialogVisible.value = false
    await loadBanks()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '创建失败')
  } finally {
    submitting.value = false
  }
}

async function handleDeleteBank(row: ExamBank) {
  try {
    await ElMessageBox.confirm('删除题库将影响其下题目，确定删除？', '提示', { type: 'warning' })
    await deleteBank(row.bank_id)
    ElMessage.success('删除成功')
    await loadBanks()
  } catch (e: any) {
    if (e !== 'cancel') ElMessage.error(e?.response?.data?.message || '删除失败')
  }
}

// ========== 题目 ==========

const questionVisible = ref(false)
const questionLoading = ref(false)
const currentBank = ref<ExamBank | null>(null)
const questions = ref<ExamQuestion[]>([])
const questionTypeFilter = ref('')

const questionFormVisible = ref(false)
const questionFormRef = ref<FormInstance>()
const questionForm = reactive({
  type: 'single',
  content: '',
  options: [''] as string[],
  answer: null as unknown,
  analysis: '',
  score: 10,
})
const questionRules: FormRules = {
  content: [{ required: true, message: '请输入题干', trigger: 'blur' }],
}

async function openQuestions(row: ExamBank) {
  currentBank.value = row
  questionVisible.value = true
  await loadQuestions()
}

async function loadQuestions() {
  if (!currentBank.value) return
  questionLoading.value = true
  try {
    questions.value = await getQuestions({
      bank_id: currentBank.value.bank_id,
      type: questionTypeFilter.value || undefined,
    })
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '获取题目失败')
  } finally {
    questionLoading.value = false
  }
}

function handleAddQuestion() {
  Object.assign(questionForm, { type: 'single', content: '', options: ['', '', '', ''], answer: null, analysis: '', score: 10 })
  questionFormVisible.value = true
}

async function submitQuestion() {
  if (!questionFormRef.value || !currentBank.value) return
  try { await questionFormRef.value.validate() } catch { return }

  const payload: Record<string, unknown> = {
    bank_id: currentBank.value.bank_id,
    type: questionForm.type,
    content: questionForm.content,
    analysis: questionForm.analysis || undefined,
    score: questionForm.score,
  }
  if (questionForm.type === 'single' || questionForm.type === 'multi') {
    payload.options = questionForm.options.filter((o) => o.trim() !== '')
    payload.answer = questionForm.answer
  } else if (questionForm.type === 'judge') {
    payload.answer = questionForm.answer
  } else {
    payload.answer = null
  }

  submitting.value = true
  try {
    await createQuestion(payload as any)
    ElMessage.success('添加成功')
    questionFormVisible.value = false
    await loadQuestions()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '添加失败')
  } finally {
    submitting.value = false
  }
}

function viewQuestion(row: ExamQuestion) {
  ElMessageBox.alert(
    `<b>题干：</b>${row.content}<br/><br/><b>答案：</b>${JSON.stringify(row.answer)}<br/><br/><b>解析：</b>${row.analysis ?? '—'}`,
    `题目 #${row.question_id}（${typeLabel(row.type)}）`,
    { dangerouslyUseHTMLString: true },
  )
}

// ========== 批量导入 ==========

const importVisible = ref(false)
const importText = ref('')

function openImport(row: ExamBank) {
  currentBank.value = row
  importText.value = ''
  importVisible.value = true
}

async function submitImport() {
  if (!currentBank.value) return
  let parsed: unknown
  try {
    parsed = JSON.parse(importText.value)
  } catch {
    ElMessage.error('JSON 格式错误，请检查')
    return
  }
  if (!Array.isArray(parsed) || parsed.length === 0) {
    ElMessage.error('请粘贴题目 JSON 数组')
    return
  }
  submitting.value = true
  try {
    const { imported } = await importQuestions(currentBank.value.bank_id, parsed)
    ElMessage.success(`导入成功：${imported} 题`)
    importVisible.value = false
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '导入失败')
  } finally {
    submitting.value = false
  }
}

onMounted(loadBanks)
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
</style>
