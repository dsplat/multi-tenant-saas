<template>
  <div class="page-container">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>课程管理</span>
          <div class="header-actions">
            <el-button type="primary" @click="handleCreate"> 新建课程 </el-button>
          </div>
        </div>
      </template>

      <ProTable
        ref="tableRef"
        :columns="columns"
        :request="handleRequest"
        :search-config="searchConfig"
        :actions="actions"
      />
    </el-card>

    <!-- 课程编辑 -->
    <el-dialog
      v-model="dialogVisible"
      :title="dialogTitle"
      width="640px"
      :close-on-click-modal="false"
    >
      <el-form ref="formRef" :model="formData" :rules="formRules" label-width="120px">
        <el-form-item label="课程标题" prop="title">
          <el-input v-model="formData.title" placeholder="请输入课程标题" maxlength="255" show-word-limit />
        </el-form-item>
        <el-form-item label="封面URL" prop="cover">
          <el-input v-model="formData.cover" placeholder="请输入封面图片URL（可选）" />
        </el-form-item>
        <el-form-item label="课程简介" prop="description">
          <el-input v-model="formData.description" type="textarea" :rows="3" placeholder="请输入课程简介" />
        </el-form-item>
        <el-form-item label="售卖方式" prop="sale_mode">
          <el-radio-group v-model="formData.sale_mode">
            <el-radio value="cash">现金</el-radio>
            <el-radio value="points">积分</el-radio>
            <el-radio value="mixed">混合</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item label="现金价格" prop="price">
          <el-input-number v-model="formData.price" :min="0" :precision="2" :step="1" style="width: 100%" />
          <span class="form-tip">元（0 表示免费）</span>
        </el-form-item>
        <el-form-item label="积分价格" prop="points_price">
          <el-input-number v-model="formData.points_price" :min="0" :step="10" style="width: 100%" />
          <span class="form-tip">积分（sale_mode 含积分时生效）</span>
        </el-form-item>
        <el-form-item label="完成奖励积分" prop="completion_reward_points">
          <el-input-number v-model="formData.completion_reward_points" :min="0" :step="5" style="width: 100%" />
          <span class="form-tip">学完全部章节奖励的积分（0 表示不奖励）</span>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false"> 取消 </el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit"> 确定 </el-button>
      </template>
    </el-dialog>

    <!-- 章节管理 -->
    <el-dialog v-model="chapterVisible" :title="`章节管理 - ${chapterCourse?.title ?? ''}`" width="760px">
      <div style="margin-bottom: 12px">
        <el-button type="primary" size="small" @click="handleAddChapter"> 添加章节 </el-button>
      </div>
      <el-table :data="chapters" v-loading="chapterLoading" size="small">
        <el-table-column prop="sort_order" label="序号" width="70" />
        <el-table-column prop="title" label="章节标题" min-width="160" />
        <el-table-column label="类型" width="90">
          <template #default="{ row }">{{ chapterTypeLabel(row.type) }}</template>
        </el-table-column>
        <el-table-column label="内容" min-width="160">
          <template #default="{ row }">
            <span v-if="row.type === 'text'">{{ (row.content || '').slice(0, 40) }}{{ (row.content || '').length > 40 ? '...' : '' }}</span>
            <a v-else :href="row.file_url || '#'" target="_blank" style="color: var(--el-color-primary)">
              {{ row.file_url || '未设置' }}
            </a>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="140">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="handleEditChapter(row)">编辑</el-button>
            <el-button link type="danger" size="small" @click="handleDeleteChapter(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-dialog>

    <!-- 章节编辑 -->
    <el-dialog v-model="chapterFormVisible" :title="chapterFormTitle" width="560px" :close-on-click-modal="false">
      <el-form ref="chapterFormRef" :model="chapterFormData" :rules="chapterFormRules" label-width="100px">
        <el-form-item label="章节标题" prop="title">
          <el-input v-model="chapterFormData.title" placeholder="请输入章节标题" />
        </el-form-item>
        <el-form-item label="排序" prop="sort_order">
          <el-input-number v-model="chapterFormData.sort_order" :min="0" :step="1" style="width: 100%" />
        </el-form-item>
        <el-form-item label="内容类型" prop="type">
          <el-radio-group v-model="chapterFormData.type">
            <el-radio value="text">图文</el-radio>
            <el-radio value="video">视频</el-radio>
            <el-radio value="file">文件</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-form-item v-if="chapterFormData.type === 'text'" label="正文内容" prop="content">
          <el-input v-model="chapterFormData.content" type="textarea" :rows="6" placeholder="请输入章节正文" />
        </el-form-item>
        <el-form-item v-else label="资源URL" prop="file_url">
          <el-input v-model="chapterFormData.file_url" placeholder="请输入视频/文件URL" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="chapterFormVisible = false"> 取消 </el-button>
        <el-button type="primary" :loading="chapterSubmitting" @click="handleChapterSubmit"> 确定 </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, h } from 'vue'
import {
  ElMessage,
  ElMessageBox,
  ElTag,
  ElSwitch,
  type FormInstance,
  type FormRules,
} from 'element-plus'
import ProTable from '@/components/common/ProTable/ProTable.vue'
import type {
  ColumnConfig,
  SearchConfig,
  ActionConfig,
  RequestParams,
  RequestResult,
} from '@/components/common/ProTable/ProTable.vue'
import {
  getCourseList,
  createCourse,
  updateCourse,
  deleteCourse,
  publishCourse,
  offlineCourse,
  getChapters,
  createChapter,
  updateChapter,
  deleteChapter,
  type Course,
  type CourseChapter,
  type CourseListParams,
  type SaveCourseData,
  type SaveChapterData,
} from '@modules/Course/api/course'

defineOptions({ name: 'CourseManagement' })

const tableRef = ref<InstanceType<typeof ProTable>>()
const dialogVisible = ref(false)
const dialogTitle = ref('新建课程')
const submitting = ref(false)
const formRef = ref<FormInstance>()
const editingId = ref<number | null>(null)

const defaultFormData = {
  title: '',
  cover: '',
  description: '',
  price: 0,
  points_price: 0,
  sale_mode: 'cash' as 'cash' | 'points' | 'mixed',
  completion_reward_points: 0,
}

const formData = reactive({ ...defaultFormData })

const formRules: FormRules = {
  title: [{ required: true, message: '请输入课程标题', trigger: 'blur' }],
  sale_mode: [{ required: true, message: '请选择售卖方式', trigger: 'change' }],
}

const statusOptions = [
  { label: '草稿', value: 'draft' },
  { label: '已发布', value: 'published' },
  { label: '已下架', value: 'offline' },
]

function statusLabel(value: string) {
  return statusOptions.find((o) => o.value === value)?.label ?? value
}

function chapterTypeLabel(value: string) {
  const map: Record<string, string> = { text: '图文', video: '视频', file: '文件' }
  return map[value] ?? value
}

const searchConfig: SearchConfig[] = [
  { prop: 'search', label: '课程标题', type: 'input', placeholder: '请输入课程标题' },
  {
    prop: 'status',
    label: '状态',
    type: 'select',
    placeholder: '请选择状态',
    options: statusOptions,
  },
]

const columns: ColumnConfig[] = [
  { prop: 'course_id', label: 'ID', width: 70 },
  { prop: 'title', label: '课程标题', minWidth: 180 },
  {
    prop: 'price',
    label: '价格',
    width: 110,
    render: (row: Course) => {
      const parts: string[] = []
      if (Number(row.price) > 0) parts.push(`¥${Number(row.price).toFixed(2)}`)
      if (Number(row.points_price) > 0) parts.push(`${row.points_price}积分`)
      return h('span', null, parts.length ? parts.join(' / ') : '免费')
    },
  },
  { prop: 'completion_reward_points', label: '完成奖励', width: 90 },
  {
    prop: 'status',
    label: '上下架',
    width: 110,
    render: (row: Course) =>
      h(ElSwitch, {
        modelValue: row.status === 'published',
        'onUpdate:modelValue': (val: string | number | boolean) => handleToggleStatus(row, !!val),
        activeText: '发布',
        inactiveText: '下架',
        inlinePrompt: true,
      }),
  },
  { prop: 'createdAt', label: '创建时间', width: 170, sortable: true },
]

const actions: ActionConfig[] = [
  { label: '编辑', type: 'primary', onClick: (row) => handleEdit(row as Course) },
  { label: '章节', type: 'success', onClick: (row) => handleOpenChapters(row as Course) },
  { label: '删除', type: 'danger', onClick: (row) => handleDelete(row as Course) },
]

async function handleRequest(params: RequestParams): Promise<RequestResult> {
  try {
    const query: CourseListParams = { page: params.page, pageSize: params.pageSize }
    if (params.search) query.search = params.search
    if (params.status) query.status = params.status
    const res = await getCourseList(query)
    return { data: res.data ?? [], total: res.total ?? 0 }
  } catch (e: any) {
    ElMessage.error(e.message || '获取课程列表失败')
    return { data: [], total: 0 }
  }
}

function resetForm() {
  Object.assign(formData, { ...defaultFormData })
}

function handleCreate() {
  dialogTitle.value = '新建课程'
  editingId.value = null
  resetForm()
  dialogVisible.value = true
}

function handleEdit(row: Course) {
  dialogTitle.value = '编辑课程'
  editingId.value = row.course_id
  formData.title = row.title
  formData.cover = row.cover ?? ''
  formData.description = row.description ?? ''
  formData.price = Number(row.price) || 0
  formData.points_price = Number(row.points_price) || 0
  formData.sale_mode = row.sale_mode
  formData.completion_reward_points = Number(row.completion_reward_points) || 0
  dialogVisible.value = true
}

async function handleSubmit() {
  if (!formRef.value) return
  try {
    await formRef.value.validate()
  } catch {
    return
  }

  const payload: SaveCourseData = {
    title: formData.title,
    cover: formData.cover,
    description: formData.description,
    price: formData.price,
    points_price: formData.points_price,
    sale_mode: formData.sale_mode,
    completion_reward_points: formData.completion_reward_points,
  }

  submitting.value = true
  try {
    if (editingId.value !== null) {
      await updateCourse(editingId.value, payload)
    } else {
      await createCourse(payload)
    }
    ElMessage.success('操作成功')
    dialogVisible.value = false
    tableRef.value?.refresh()
  } catch (e: any) {
    ElMessage.error(e.message || '操作失败')
  } finally {
    submitting.value = false
  }
}

async function handleDelete(row: Course) {
  try {
    await ElMessageBox.confirm('确定删除该课程吗？章节与学习记录将一并删除', '提示', { type: 'warning' })
    await deleteCourse(row.course_id)
    ElMessage.success('删除成功')
    tableRef.value?.refresh()
  } catch (e: any) {
    if (e !== 'cancel') {
      ElMessage.error(e.message || '删除失败')
    }
  }
}

async function handleToggleStatus(row: Course, val: boolean) {
  try {
    if (val) {
      await publishCourse(row.course_id)
      row.status = 'published'
      ElMessage.success('已发布')
    } else {
      await offlineCourse(row.course_id)
      row.status = 'offline'
      ElMessage.success('已下架')
    }
  } catch (e: any) {
    ElMessage.error(e.message || '操作失败')
  }
}

// ========== 章节管理 ==========

const chapterVisible = ref(false)
const chapterLoading = ref(false)
const chapterCourse = ref<Course | null>(null)
const chapters = ref<CourseChapter[]>([])

const chapterFormVisible = ref(false)
const chapterFormTitle = ref('添加章节')
const chapterSubmitting = ref(false)
const chapterFormRef = ref<FormInstance>()
const editingChapterId = ref<number | null>(null)

const chapterFormData = reactive({
  title: '',
  sort_order: 0,
  type: 'text' as 'text' | 'video' | 'file',
  content: '',
  file_url: '',
})

const chapterFormRules: FormRules = {
  title: [{ required: true, message: '请输入章节标题', trigger: 'blur' }],
}

async function handleOpenChapters(row: Course) {
  chapterCourse.value = row
  chapterVisible.value = true
  await loadChapters()
}

async function loadChapters() {
  if (!chapterCourse.value) return
  chapterLoading.value = true
  try {
    chapters.value = await getChapters(chapterCourse.value.course_id)
  } catch (e: any) {
    ElMessage.error(e.message || '获取章节失败')
    chapters.value = []
  } finally {
    chapterLoading.value = false
  }
}

function handleAddChapter() {
  chapterFormTitle.value = '添加章节'
  editingChapterId.value = null
  Object.assign(chapterFormData, {
    title: '',
    sort_order: chapters.value.length,
    type: 'text',
    content: '',
    file_url: '',
  })
  chapterFormVisible.value = true
}

function handleEditChapter(row: CourseChapter) {
  chapterFormTitle.value = '编辑章节'
  editingChapterId.value = row.chapter_id
  Object.assign(chapterFormData, {
    title: row.title,
    sort_order: row.sort_order,
    type: row.type,
    content: row.content ?? '',
    file_url: row.file_url ?? '',
  })
  chapterFormVisible.value = true
}

async function handleChapterSubmit() {
  if (!chapterFormRef.value || !chapterCourse.value) return
  try {
    await chapterFormRef.value.validate()
  } catch {
    return
  }

  const payload: SaveChapterData = {
    title: chapterFormData.title,
    sort_order: chapterFormData.sort_order,
    type: chapterFormData.type,
  }
  if (chapterFormData.type === 'text') {
    payload.content = chapterFormData.content
  } else {
    payload.file_url = chapterFormData.file_url
  }

  chapterSubmitting.value = true
  try {
    if (editingChapterId.value !== null) {
      await updateChapter(chapterCourse.value.course_id, editingChapterId.value, payload)
    } else {
      await createChapter(chapterCourse.value.course_id, payload)
    }
    ElMessage.success('操作成功')
    chapterFormVisible.value = false
    await loadChapters()
  } catch (e: any) {
    ElMessage.error(e.message || '操作失败')
  } finally {
    chapterSubmitting.value = false
  }
}

async function handleDeleteChapter(row: CourseChapter) {
  if (!chapterCourse.value) return
  try {
    await ElMessageBox.confirm('确定删除该章节吗？', '提示', { type: 'warning' })
    await deleteChapter(chapterCourse.value.course_id, row.chapter_id)
    ElMessage.success('删除成功')
    await loadChapters()
  } catch (e: any) {
    if (e !== 'cancel') {
      ElMessage.error(e.message || '删除失败')
    }
  }
}
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
