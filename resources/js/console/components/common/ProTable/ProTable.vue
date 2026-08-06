<template>
  <!-- ProTable — 通用表格组件，支持搜索、分页、排序、操作列 -->
  <div class="pro-table">
    <el-card v-if="searchConfig?.length" shadow="never" class="search-card">
      <el-form :model="searchForm" inline @submit.prevent="handleSearch">
        <el-form-item v-for="item in searchConfig" :key="item.prop" :label="item.label">
          <el-input
            v-if="item.type === 'input'"
            v-model="searchForm[item.prop]"
            :placeholder="item.placeholder"
            clearable
            style="width: 200px"
          />
          <el-select
            v-else-if="item.type === 'select'"
            v-model="searchForm[item.prop]"
            :placeholder="item.placeholder"
            clearable
            style="width: 200px"
          >
            <el-option
              v-for="opt in item.options"
              :key="opt.value"
              :label="opt.label"
              :value="opt.value"
            />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="handleSearch"> 搜索 </el-button>
          <el-button @click="handleReset"> 重置 </el-button>
        </el-form-item>
      </el-form>
    </el-card>

    <el-card shadow="never">
      <el-table
        v-loading="loading"
        :data="tableData"
        :default-sort="defaultSort"
        stripe
        style="width: 100%"
        @sort-change="handleSortChange"
      >
        <el-table-column
          v-for="col in columns"
          :key="col.prop"
          :prop="col.prop"
          :label="col.label"
          :width="col.width"
          :min-width="col.minWidth"
          :sortable="col.sortable"
          :fixed="col.fixed"
          :show-overflow-tooltip="col.showOverflowTooltip ?? true"
        >
          <template v-if="col.render" #default="{ row, $index }">
            <component :is="() => col.render!(row, $index)" />
          </template>
        </el-table-column>
        <el-table-column v-if="actions?.length" label="操作" :width="actionWidth" fixed="right">
          <template #default="{ row, $index }">
            <template v-for="action in actions" :key="action.label">
              <el-button
                v-if="!action.visible || action.visible(row, $index)"
                link
                :type="action.type ?? 'primary'"
                @click="action.onClick(row, $index)"
              >
                {{ action.label }}
              </el-button>
            </template>
          </template>
        </el-table-column>
      </el-table>

      <div v-if="showPagination" class="pagination-wrapper">
        <el-pagination
          v-model:current-page="pagination.page"
          v-model:page-size="pagination.pageSize"
          :total="pagination.total"
          :page-sizes="pageSizes"
          layout="total, sizes, prev, pager, next, jumper"
          @size-change="fetchData"
          @current-change="fetchData"
        />
      </div>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted, type VNode } from 'vue'
import { ElMessage } from 'element-plus'

export interface SearchConfig {
  prop: string
  label: string
  type: 'input' | 'select'
  placeholder?: string
  options?: { label: string; value: any }[]
}

export interface ColumnConfig {
  prop: string
  label: string
  width?: number | string
  minWidth?: number | string
  sortable?: boolean | 'custom'
  fixed?: boolean | 'left' | 'right'
  showOverflowTooltip?: boolean
  render?: (row: any, index: number) => VNode
}

export interface ActionConfig {
  label: string
  type?: 'primary' | 'success' | 'warning' | 'danger' | 'info'
  onClick: (row: any, index: number) => void
  visible?: (row: any, index: number) => boolean
}

export interface RequestParams {
  page: number
  pageSize: number
  sortProp?: string
  sortOrder?: 'ascending' | 'descending'
  [key: string]: any
}

export interface RequestResult {
  data: any[]
  total: number
}

interface Props {
  columns: ColumnConfig[]
  request: (params: RequestParams) => Promise<RequestResult>
  searchConfig?: SearchConfig[]
  actions?: ActionConfig[]
  actionWidth?: number | string
  defaultSort?: { prop: string; order: 'ascending' | 'descending' }
  showPagination?: boolean
  pageSizes?: number[]
  immediate?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  actionWidth: 200,
  showPagination: true,
  pageSizes: () => [10, 20, 50, 100],
  immediate: true,
})

const loading = ref(false)
const tableData = ref<any[]>([])
const pagination = reactive({ page: 1, pageSize: 10, total: 0 })
const sortState = reactive({ prop: '', order: '' as '' | 'ascending' | 'descending' })

const searchForm = reactive<Record<string, any>>({})

function initSearchForm() {
  props.searchConfig?.forEach((item) => {
    searchForm[item.prop] = ''
  })
}

async function fetchData() {
  loading.value = true
  try {
    const params: RequestParams = {
      page: pagination.page,
      pageSize: pagination.pageSize,
      ...searchForm,
    }
    if (sortState.prop) {
      params.sortProp = sortState.prop
      params.sortOrder = sortState.order || undefined
    }
    const result = await props.request(params)
    tableData.value = Array.isArray(result.data) ? result.data : []
    pagination.total = result.total ?? 0
  } catch {
    ElMessage.error('数据加载失败')
    tableData.value = []
    pagination.total = 0
  } finally {
    loading.value = false
  }
}

function handleSearch() {
  pagination.page = 1
  fetchData()
}

function handleReset() {
  initSearchForm()
  handleSearch()
}

function handleSortChange({
  prop,
  order,
}: {
  prop: string
  order: 'ascending' | 'descending' | null
}) {
  sortState.prop = prop ?? ''
  sortState.order = order ?? ''
  fetchData()
}

function refresh() {
  fetchData()
}

initSearchForm()

if (props.immediate) {
  onMounted(fetchData)
}

defineExpose({ refresh, fetchData })
</script>

<style scoped lang="scss">
.pro-table {
  .search-card {
    margin-bottom: 16px;

    :deep(.el-card__body) {
      padding-bottom: 0;
    }
  }

  .pagination-wrapper {
    margin-top: 16px;
    display: flex;
    justify-content: flex-end;
  }
}
</style>
