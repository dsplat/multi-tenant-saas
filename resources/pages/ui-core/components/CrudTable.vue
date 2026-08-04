<template>
  <div class="crud-table">
    <!-- 工具栏：搜索区 + 右侧操作插槽 -->
    <div v-if="searchFields.length || $slots.toolbar" class="crud-table__toolbar">
      <el-form v-if="searchFields.length" inline @submit.prevent="reload">
        <el-form-item v-for="f in searchFields" :key="f.prop" :label="f.label">
          <el-input v-if="!f.type || f.type === 'text'" v-model="search[f.prop]" clearable
            :placeholder="`搜索${f.label}`" style="width: 180px" @keyup.enter="reload" @clear="reload" />
          <el-select v-else-if="f.type === 'select'" v-model="search[f.prop]" clearable
            :placeholder="`筛选${f.label}`" style="width: 160px" @change="reload">
            <el-option v-for="opt in f.options" :key="opt.value" :label="opt.label" :value="opt.value" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="reload">查询</el-button>
        </el-form-item>
      </el-form>
      <div class="crud-table__toolbar-right">
        <slot name="toolbar" />
      </div>
    </div>

    <el-table v-loading="loading" :data="rows" border stripe>
      <el-table-column v-for="col in columns" :key="col.prop" :prop="col.prop" :label="col.label"
        :width="col.width" :min-width="col.minWidth" show-overflow-tooltip>
        <template #default="scope">
          <slot :name="`col-${col.prop}`" :row="scope.row">
            {{ col.formatter ? col.formatter(scope.row) : scope.row[col.prop] }}
          </slot>
        </template>
      </el-table-column>
      <el-table-column v-if="$slots.actions" label="操作" :width="actionsWidth" fixed="right">
        <template #default="scope">
          <slot name="actions" :row="scope.row" />
        </template>
      </el-table-column>
    </el-table>

    <el-pagination v-if="total > 0" class="crud-table__pagination" v-model:current-page="page"
      v-model:page-size="pageSize" :total="total" layout="total, sizes, prev, pager, next"
      :page-sizes="[10, 20, 50, 100]" @current-change="load" @size-change="reload" />
  </div>
</template>

<script setup lang="ts">
/**
 * 通用 CRUD 表格
 *
 * 约定后端返回结构：{ success: true, data: { items|data|rows: [...], total: number } }
 * 兼容 data 直接为数组的简化结构（total 取数组长度）。
 */
import { onMounted, reactive, ref } from 'vue'
import axios from 'axios'

export interface CrudColumn {
  prop: string
  label: string
  width?: number
  minWidth?: number
  formatter?: (row: any) => string | number
}

export interface CrudSearchField {
  prop: string
  label: string
  type?: 'text' | 'select'
  options?: Array<{ label: string; value: string | number }>
}

const props = withDefaults(defineProps<{
  columns: CrudColumn[]
  fetchApi: string
  searchFields?: CrudSearchField[]
  actionsWidth?: number
  immediate?: boolean
  extraParams?: Record<string, any>
}>(), {
  searchFields: () => [],
  actionsWidth: 160,
  immediate: true,
  extraParams: () => ({}),
})

const emit = defineEmits<{
  (e: 'loaded', rows: any[]): void
}>()

const loading = ref(false)
const rows = ref<any[]>([])
const total = ref(0)
const page = ref(1)
const pageSize = ref(20)
const search = reactive<Record<string, any>>({})

const load = async () => {
  loading.value = true
  try {
    const params: Record<string, any> = {
      page: page.value,
      page_size: pageSize.value,
      ...props.extraParams,
    }
    for (const f of props.searchFields) {
      if (search[f.prop] !== undefined && search[f.prop] !== '') params[f.prop] = search[f.prop]
    }
    const res = await axios.get(props.fetchApi, { params })
    const data = res.data?.data ?? res.data
    if (Array.isArray(data)) {
      rows.value = data
      total.value = data.length
    } else {
      rows.value = data?.items ?? data?.data ?? data?.rows ?? []
      total.value = data?.total ?? rows.value.length
    }
    emit('loaded', rows.value)
  } catch (e) {
    rows.value = []
    total.value = 0
  } finally {
    loading.value = false
  }
}

/** 重置到第一页并重新加载 */
const reload = () => {
  page.value = 1
  return load()
}

if (props.immediate) onMounted(load)

defineExpose({ load, reload })
</script>

<style scoped>
.crud-table__toolbar {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  margin-bottom: 12px;
}
.crud-table__pagination {
  margin-top: 16px;
  justify-content: flex-end;
}
</style>
