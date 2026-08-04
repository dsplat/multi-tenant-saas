<template>
  <div class="page">
    <div class="page-header"><h2>平台内容库</h2></div>

    <el-tabs v-model="tab">
      <el-tab-pane label="内容条目" name="contents">
        <el-card shadow="never">
          <CrudTable ref="contentTableRef" fetch-api="/api/v1/admin/commerce/content-library"
            :columns="contentColumns" :search-fields="[{ prop: 'keyword', label: '标题' }]">
            <template #toolbar>
              <el-button type="primary" @click="openContentForm()">新建内容</el-button>
            </template>
            <template #col-status="{ row }">
              <el-tag :type="row.status === 'published' ? 'success' : 'info'" size="small">
                {{ row.status === 'published' ? '已发布' : '草稿' }}
              </el-tag>
            </template>
            <template #actions="{ row }">
              <el-button size="small" @click="openContentForm(row)">编辑</el-button>
              <el-button v-if="row.status !== 'published'" size="small" type="success"
                @click="publishContent(row)">发布</el-button>
              <el-button size="small" type="danger" @click="retireContent(row)">下架</el-button>
            </template>
          </CrudTable>
        </el-card>
      </el-tab-pane>

      <el-tab-pane label="内容包" name="packs">
        <el-card shadow="never">
          <CrudTable ref="packTableRef" fetch-api="/api/v1/admin/commerce/content-packs"
            :columns="packColumns">
            <template #toolbar>
              <el-button type="primary" @click="openPackForm()">新建内容包</el-button>
            </template>
            <template #actions="{ row }">
              <el-button size="small" @click="openPackForm(row)">编辑</el-button>
              <el-button size="small" type="danger" @click="retirePack(row)">删除</el-button>
            </template>
          </CrudTable>
        </el-card>
      </el-tab-pane>
    </el-tabs>

    <!-- 内容条目编辑 -->
    <el-dialog v-model="contentDialog" :title="contentModel.content_id ? '编辑内容' : '新建内容'" width="560px">
      <CrudForm ref="contentFormRef" :fields="contentFields" :model="contentModel" />
      <template #footer>
        <el-button @click="contentDialog = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="saveContent">保存</el-button>
      </template>
    </el-dialog>

    <!-- 内容包编辑 -->
    <el-dialog v-model="packDialog" :title="packModel.pack_id ? '编辑内容包' : '新建内容包'" width="560px">
      <CrudForm ref="packFormRef" :fields="packFields" :model="packModel" />
      <template #footer>
        <el-button @click="packDialog = false">取消</el-button>
        <el-button type="primary" :loading="saving" @click="savePack">保存</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { reactive, ref } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'
import CrudTable from '@multi-tenant-saas/ui-core/components/CrudTable.vue'
import CrudForm from '@multi-tenant-saas/ui-core/components/CrudForm.vue'

const tab = ref('contents')
const saving = ref(false)

const contentTableRef = ref()
const packTableRef = ref()
const contentFormRef = ref()
const packFormRef = ref()

const contentColumns = [
  { prop: 'title', label: '标题', minWidth: 200 },
  { prop: 'type', label: '类型', width: 110 },
  { prop: 'status', label: '状态', width: 90 },
  { prop: 'sort_order', label: '排序', width: 80 },
]

const packColumns = [
  { prop: 'name', label: '内容包名称', minWidth: 200 },
  { prop: 'item_count', label: '条目数', width: 90 },
  { prop: 'status', label: '状态', width: 90 },
]

const contentFields = [
  { prop: 'title', label: '标题', required: true },
  { prop: 'type', label: '类型', type: 'select' as const, required: true, options: [
    { label: '文章', value: 'article' },
    { label: '视频', value: 'video' },
    { label: '文档', value: 'document' },
    { label: '模板', value: 'template' },
  ] },
  { prop: 'body', label: '正文', type: 'textarea' as const, rows: 5 },
  { prop: 'file_url', label: '文件URL' },
  { prop: 'sort_order', label: '排序', type: 'number' as const, min: 0 },
]

const packFields = [
  { prop: 'name', label: '名称', required: true },
  { prop: 'description', label: '描述', type: 'textarea' as const },
]

// ===== 内容条目 =====
const contentDialog = ref(false)
const contentModel = reactive<Record<string, any>>({})

const openContentForm = (row?: any) => {
  Object.keys(contentModel).forEach(k => delete contentModel[k])
  if (row) Object.assign(contentModel, row)
  contentDialog.value = true
}

const saveContent = async () => {
  try {
    await contentFormRef.value?.validate()
    saving.value = true
    if (contentModel.content_id) {
      await axios.put(`/api/v1/admin/commerce/content-library/${contentModel.content_id}`, contentModel)
    } else {
      await axios.post('/api/v1/admin/commerce/content-library', contentModel)
    }
    ElMessage.success('已保存')
    contentDialog.value = false
    contentTableRef.value?.reload()
  } catch (e: any) {
    if (e?.response) ElMessage.error(e.response?.data?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

const publishContent = async (row: any) => {
  try {
    await axios.post(`/api/v1/admin/commerce/content-library/${row.content_id}/publish`)
    ElMessage.success('已发布')
    contentTableRef.value?.reload()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '发布失败')
  }
}

const retireContent = async (row: any) => {
  try {
    await ElMessageBox.confirm('确定下架该内容？', '警告', { type: 'warning' })
    await axios.delete(`/api/v1/admin/commerce/content-library/${row.content_id}`)
    ElMessage.success('已下架')
    contentTableRef.value?.reload()
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '下架失败')
  }
}

// ===== 内容包 =====
const packDialog = ref(false)
const packModel = reactive<Record<string, any>>({})

const openPackForm = (row?: any) => {
  Object.keys(packModel).forEach(k => delete packModel[k])
  if (row) Object.assign(packModel, row)
  packDialog.value = true
}

const savePack = async () => {
  try {
    await packFormRef.value?.validate()
    saving.value = true
    if (packModel.pack_id) {
      await axios.put(`/api/v1/admin/commerce/content-packs/${packModel.pack_id}`, packModel)
    } else {
      await axios.post('/api/v1/admin/commerce/content-packs', packModel)
    }
    ElMessage.success('已保存')
    packDialog.value = false
    packTableRef.value?.reload()
  } catch (e: any) {
    if (e?.response) ElMessage.error(e.response?.data?.message || '保存失败')
  } finally {
    saving.value = false
  }
}

const retirePack = async (row: any) => {
  try {
    await ElMessageBox.confirm('确定删除该内容包？', '警告', { type: 'warning' })
    await axios.delete(`/api/v1/admin/commerce/content-packs/${row.pack_id}`)
    ElMessage.success('已删除')
    packTableRef.value?.reload()
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '删除失败')
  }
}
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
</style>
