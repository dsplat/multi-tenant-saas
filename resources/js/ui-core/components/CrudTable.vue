<template>
  <div class="crud-table">
    <div class="table-toolbar">
      <input
        v-if="searchable"
        v-model="searchQuery"
        class="search-input"
        :placeholder="searchPlaceholder"
        @input="handleSearch"
      />
      <div class="toolbar-actions">
        <slot name="toolbar" />
      </div>
    </div>

    <div class="table-wrapper">
      <table class="data-table">
        <thead>
          <tr>
            <th v-for="col in columns" :key="col.key" :style="col.width ? { width: col.width } : {}">
              <span @click="col.sortable && handleSort(col.key)" :class="{ sortable: col.sortable }">
                {{ col.label }}
                <span v-if="col.sortable && sortKey === col.key" class="sort-icon">
                  {{ sortOrder === 'asc' ? '↑' : '↓' }}
                </span>
              </span>
            </th>
            <th v-if="$slots.actions" class="actions-col">操作</th>
          </tr>
        </thead>
        <tbody>
          <tr v-if="loading">
            <td :colspan="columns.length + 1" class="loading-cell">加载中...</td>
          </tr>
          <tr v-else-if="filteredData.length === 0">
            <td :colspan="columns.length + 1" class="empty-cell">暂无数据</td>
          </tr>
          <tr v-for="(row, index) in paginatedData" :key="row[rowKey] || index">
            <td v-for="col in columns" :key="col.key">
              <slot :name="`cell-${col.key}`" :row="row" :value="row[col.key]">
                {{ row[col.key] }}
              </slot>
            </td>
            <td v-if="$slots.actions" class="actions-col">
              <slot name="actions" :row="row" :index="index" />
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <div v-if="totalPages > 1" class="table-pagination">
      <span class="pagination-info">共 {{ filteredData.length }} 条</span>
      <div class="pagination-buttons">
        <button :disabled="currentPage <= 1" @click="currentPage--">上一页</button>
        <span class="page-num">{{ currentPage }} / {{ totalPages }}</span>
        <button :disabled="currentPage >= totalPages" @click="currentPage++">下一页</button>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'

export interface TableColumn {
  key: string
  label: string
  width?: string
  sortable?: boolean
}

const props = withDefaults(defineProps<{
  columns: TableColumn[]
  data: any[]
  rowKey?: string
  searchable?: boolean
  searchPlaceholder?: string
  searchFields?: string[]
  pageSize?: number
  loading?: boolean
}>(), {
  rowKey: 'id',
  searchable: true,
  searchPlaceholder: '搜索...',
  pageSize: 20,
  loading: false,
})

const searchQuery = ref('')
const currentPage = ref(1)
const sortKey = ref('')
const sortOrder = ref<'asc' | 'desc'>('asc')

const filteredData = computed(() => {
  let result = [...props.data]

  if (searchQuery.value) {
    const query = searchQuery.value.toLowerCase()
    const fields = props.searchFields || props.columns.map(c => c.key)
    result = result.filter(row =>
      fields.some(field => String(row[field] ?? '').toLowerCase().includes(query))
    )
  }

  if (sortKey.value) {
    result.sort((a, b) => {
      const va = a[sortKey.value]
      const vb = b[sortKey.value]
      const cmp = va < vb ? -1 : va > vb ? 1 : 0
      return sortOrder.value === 'asc' ? cmp : -cmp
    })
  }

  return result
})

const totalPages = computed(() => Math.max(1, Math.ceil(filteredData.value.length / props.pageSize)))

const paginatedData = computed(() => {
  const start = (currentPage.value - 1) * props.pageSize
  return filteredData.value.slice(start, start + props.pageSize)
})

const handleSearch = () => { currentPage.value = 1 }

const handleSort = (key: string) => {
  if (sortKey.value === key) {
    sortOrder.value = sortOrder.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortKey.value = key
    sortOrder.value = 'asc'
  }
}

watch(() => props.data, () => { currentPage.value = 1 })
</script>

<style scoped>
.crud-table { background: var(--bg-color, #fff); border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.table-toolbar { display: flex; justify-content: space-between; align-items: center; padding: 16px; gap: 12px; }
.search-input { padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 13px; width: 260px; background: var(--bg-color, #fff); color: var(--text-color-primary, #333); }
.toolbar-actions { display: flex; gap: 8px; }
.table-wrapper { overflow-x: auto; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { text-align: left; padding: 12px 16px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; color: var(--text-color-primary, #333); }
.data-table th { color: var(--text-color-secondary, #999); font-weight: 500; background: var(--fill-color, #fafafa); }
.sortable { cursor: pointer; user-select: none; }
.sortable:hover { color: var(--primary-color, #409eff); }
.sort-icon { font-size: 12px; margin-left: 4px; }
.actions-col { text-align: center; white-space: nowrap; }
.loading-cell, .empty-cell { text-align: center; padding: 40px 16px; color: var(--text-color-secondary, #999); }
.table-pagination { display: flex; justify-content: space-between; align-items: center; padding: 12px 16px; border-top: 1px solid var(--border-color, #eee); }
.pagination-info { font-size: 12px; color: var(--text-color-secondary, #999); }
.pagination-buttons { display: flex; align-items: center; gap: 8px; }
.pagination-buttons button { padding: 6px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 4px; background: var(--bg-color, #fff); cursor: pointer; font-size: 12px; color: var(--text-color-primary, #333); }
.pagination-buttons button:disabled { opacity: 0.5; cursor: not-allowed; }
.page-num { font-size: 12px; color: var(--text-color-secondary, #666); }
</style>
