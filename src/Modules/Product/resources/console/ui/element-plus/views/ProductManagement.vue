<template>
  <div class="page-container">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>商品管理</span>
          <div class="header-actions">
            <el-button type="primary" @click="handleCreate"> 新建商品 </el-button>
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

    <el-dialog
      v-model="dialogVisible"
      :title="dialogTitle"
      width="640px"
      :close-on-click-modal="false"
      @close="handleDialogClose"
    >
      <el-form ref="formRef" :model="formData" :rules="formRules" label-width="100px">
        <el-form-item label="商品名称" prop="name">
          <el-input
            v-model="formData.name"
            placeholder="请输入商品名称"
            maxlength="100"
            show-word-limit
          />
        </el-form-item>
        <el-form-item label="商品图片" prop="image">
          <el-upload
            class="image-uploader"
            :show-file-list="false"
            :before-upload="beforeImageUpload"
            :http-request="handleImageUpload"
            accept="image/jpeg,image/png,image/gif,image/webp"
          >
            <el-image
              v-if="formData.image"
              :src="formData.image"
              fit="cover"
              class="preview-image"
            />
            <el-icon v-else class="upload-placeholder">
              <Plus />
            </el-icon>
          </el-upload>
        </el-form-item>
        <el-form-item label="价格" prop="price">
          <el-input-number
            v-model="formData.price"
            :min="0.01"
            :max="999999"
            :precision="2"
            :step="1"
            style="width: 100%"
          />
          <span class="form-tip">元</span>
        </el-form-item>
        <el-form-item label="库存" prop="stock">
          <el-input-number
            v-model="formData.stock"
            :min="0"
            :max="999999"
            :step="1"
            style="width: 100%"
          />
        </el-form-item>
        <el-form-item label="分类" prop="category">
          <el-select v-model="formData.category" placeholder="请选择分类" style="width: 100%">
            <el-option
              v-for="opt in categoryOptions"
              :key="opt.value"
              :label="opt.label"
              :value="opt.value"
            />
          </el-select>
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-radio-group v-model="formData.status">
            <el-radio value="active"> 上架 </el-radio>
            <el-radio value="inactive"> 下架 </el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false"> 取消 </el-button>
        <el-button type="primary" :loading="submitting" @click="handleSubmit"> 确定 </el-button>
      </template>
    </el-dialog>

    <!-- SKU 管理 -->
    <el-dialog v-model="skuVisible" :title="`SKU 管理 - ${skuProduct?.name ?? ''}`" width="820px">
      <div style="margin-bottom: 12px">
        <el-button type="primary" size="small" @click="handleAddSku"> 添加 SKU </el-button>
      </div>
      <el-table :data="skus" v-loading="skuLoading" size="small">
        <el-table-column prop="name" label="SKU 名称" min-width="140" />
        <el-table-column label="规格" min-width="140">
          <template #default="{ row }">{{ formatSpec(row.spec_attrs) || '-' }}</template>
        </el-table-column>
        <el-table-column label="现金价" width="100">
          <template #default="{ row }">¥{{ Number(row.price).toFixed(2) }}</template>
        </el-table-column>
        <el-table-column label="积分价" width="90">
          <template #default="{ row }">{{ Number(row.points_price) || '-' }}</template>
        </el-table-column>
        <el-table-column prop="stock" label="库存" width="80" />
        <el-table-column prop="sold_count" label="已售" width="80" />
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="row.status === 'active' ? 'success' : 'info'" size="small">
              {{ row.status === 'active' ? '启用' : '停用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="140">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="handleEditSku(row)">编辑</el-button>
            <el-button link type="danger" size="small" @click="handleDeleteSku(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-dialog>

    <!-- SKU 编辑 -->
    <el-dialog v-model="skuFormVisible" :title="skuFormTitle" width="560px" :close-on-click-modal="false">
      <el-form ref="skuFormRef" :model="skuFormData" :rules="skuFormRules" label-width="100px">
        <el-form-item label="SKU 名称" prop="name">
          <el-input v-model="skuFormData.name" placeholder="如：红色 / XL" />
        </el-form-item>
        <el-form-item label="规格属性" prop="specText">
          <el-input
            v-model="skuFormData.specText"
            type="textarea"
            :rows="2"
            placeholder='JSON 格式，如 {"颜色":"红色","尺码":"XL"}（可选）'
          />
        </el-form-item>
        <el-form-item label="现金价格" prop="price">
          <el-input-number v-model="skuFormData.price" :min="0" :precision="2" :step="1" style="width: 100%" />
          <span class="form-tip">元</span>
        </el-form-item>
        <el-form-item label="积分价格" prop="points_price">
          <el-input-number v-model="skuFormData.points_price" :min="0" :step="10" style="width: 100%" />
          <span class="form-tip">积分（支持积分/混合售卖时填写）</span>
        </el-form-item>
        <el-form-item label="库存" prop="stock">
          <el-input-number v-model="skuFormData.stock" :min="0" :step="1" style="width: 100%" />
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-radio-group v-model="skuFormData.status">
            <el-radio value="active">启用</el-radio>
            <el-radio value="inactive">停用</el-radio>
          </el-radio-group>
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="skuFormVisible = false"> 取消 </el-button>
        <el-button type="primary" :loading="skuSubmitting" @click="handleSkuSubmit"> 确定 </el-button>
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
  ElImage,
  ElSwitch,
  type FormInstance,
  type FormRules,
  type UploadRequestOptions,
} from 'element-plus'
import { Plus } from '@element-plus/icons-vue'
import ProTable from '@/components/common/ProTable/ProTable.vue'
import type {
  ColumnConfig,
  SearchConfig,
  ActionConfig,
  RequestParams,
  RequestResult,
} from '@/components/common/ProTable/ProTable.vue'
import { uploadImage } from '@/api/common/upload'
import {
  getProductList,
  createProduct,
  updateProduct,
  deleteProduct,
  updateProductStatus,
  type Product,
  type ProductListParams,
  type CreateProductData,
  type UpdateProductData,
} from '@modules/Product/api/product'
import {
  getSkuList,
  createSku,
  updateSku,
  deleteSku,
  type ProductSku,
  type SaveSkuData,
} from '@modules/Product/api/sku'

defineOptions({ name: 'ProductManagement' })

const tableRef = ref<InstanceType<typeof ProTable>>()
const dialogVisible = ref(false)
const dialogTitle = ref('新建商品')
const submitting = ref(false)
const formRef = ref<FormInstance>()
const editingId = ref<number | null>(null)

const categoryOptions = [
  { label: '数码', value: '数码' },
  { label: '服饰', value: '服饰' },
  { label: '食品', value: '食品' },
  { label: '家居', value: '家居' },
  { label: '美妆', value: '美妆' },
  { label: '其他', value: '其他' },
]

const defaultFormData = {
  name: '',
  image: '',
  price: 0,
  stock: 0,
  category: '',
  status: 'active' as 'active' | 'inactive',
}

const formData = reactive({ ...defaultFormData })

const formRules: FormRules = {
  name: [{ required: true, message: '请输入商品名称', trigger: 'blur' }],
  image: [{ required: true, message: '请上传商品图片', trigger: 'change' }],
  price: [
    { required: true, message: '请输入价格', trigger: 'blur' },
    {
      validator: (_rule: FormRules[string], val: number, callback: (error?: Error) => void) => {
        if (val === undefined || val === null || val <= 0) {
          callback(new Error('价格必须大于0'))
        } else {
          callback()
        }
      },
      trigger: 'blur',
    },
  ],
  stock: [{ required: true, message: '请输入库存', trigger: 'blur' }],
  category: [{ required: true, message: '请选择分类', trigger: 'change' }],
  status: [{ required: true, message: '请选择状态', trigger: 'change' }],
}

function getCategoryLabel(value: string) {
  return categoryOptions.find((o) => o.value === value)?.label ?? value
}

function beforeImageUpload(file: File) {
  const allowed = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']
  if (!allowed.includes(file.type)) {
    ElMessage.error('只能上传 JPG/PNG/GIF/WEBP 格式的图片')
    return false
  }
  if (file.size > 2 * 1024 * 1024) {
    ElMessage.error('图片大小不能超过 2MB')
    return false
  }
  return true
}

async function handleImageUpload(options: UploadRequestOptions) {
  try {
    const res = await uploadImage(options.file as File)
    formData.image = res.url
  } catch (e: any) {
    ElMessage.error(e.message || '上传失败')
  }
}

const searchConfig: SearchConfig[] = [
  { prop: 'name', label: '商品名称', type: 'input', placeholder: '请输入商品名称' },
  {
    prop: 'category',
    label: '分类',
    type: 'select',
    placeholder: '请选择分类',
    options: categoryOptions,
  },
]

const columns: ColumnConfig[] = [
  { prop: 'id', label: 'ID', width: 80 },
  { prop: 'name', label: '商品名称', minWidth: 150 },
  {
    prop: 'image',
    label: '图片',
    width: 80,
    render: (row: Product) =>
      row.image
        ? h(ElImage, {
            src: row.image,
            fit: 'cover',
            style: 'width:48px;height:48px;border-radius:4px',
          })
        : h('span', { style: 'color:var(--el-text-color-secondary)' }, '暂无'),
  },
  {
    prop: 'price',
    label: '价格',
    width: 100,
    render: (row: Product) => h('span', null, `¥${row.price?.toFixed(2) ?? '0.00'}`),
  },
  { prop: 'stock', label: '库存', width: 80 },
  {
    prop: 'category',
    label: '分类',
    width: 100,
    render: (row: Product) => h(ElTag, null, () => getCategoryLabel(row.category)),
  },
  {
    prop: 'status',
    label: '上下架',
    width: 100,
    render: (row: Product) =>
      h(ElSwitch, {
        modelValue: row.status === 'active',
        'onUpdate:modelValue': (val: string | number | boolean) => handleToggleStatus(row, !!val),
        activeText: '上架',
        inactiveText: '下架',
        inlinePrompt: true,
      }),
  },
  { prop: 'created_at', label: '创建时间', width: 170, sortable: true },
]

const actions: ActionConfig[] = [
  { label: '编辑', type: 'primary', onClick: (row) => handleEdit(row as Product) },
  { label: 'SKU', type: 'success', onClick: (row) => handleOpenSkus(row as Product) },
  { label: '删除', type: 'danger', onClick: (row) => handleDelete(row as Product) },
]

async function handleRequest(params: RequestParams): Promise<RequestResult> {
  try {
    const query: ProductListParams = {
      page: params.page,
      pageSize: params.pageSize,
    }
    if (params.name) query.name = params.name
    if (params.category) query.category = params.category
    const res = await getProductList(query)
    return { data: res.data ?? [], total: res.total ?? 0 }
  } catch (e: any) {
    ElMessage.error(e.message || '获取商品列表失败')
    return { data: [], total: 0 }
  }
}

function resetForm() {
  Object.assign(formData, { ...defaultFormData })
}

function handleCreate() {
  dialogTitle.value = '新建商品'
  editingId.value = null
  resetForm()
  dialogVisible.value = true
}

function handleEdit(row: Product) {
  dialogTitle.value = '编辑商品'
  editingId.value = row.product_id
  formData.name = row.name
  formData.image = row.image
  formData.price = row.price
  formData.stock = row.stock
  formData.category = row.category
  // draft 商品编辑时归为下架态（UI 仅两态）
  formData.status = row.status === 'active' ? 'active' : 'inactive'
  dialogVisible.value = true
}

async function handleSubmit() {
  if (!formRef.value) return
  try {
    await formRef.value.validate()
  } catch {
    return
  }

  // 后端 publish 要求库存>0
  if (formData.status === 'active' && formData.stock <= 0) {
    ElMessage.error('上架商品的库存必须大于0')
    return
  }

  const payload: CreateProductData = {
    name: formData.name,
    image: formData.image,
    price: formData.price,
    stock: formData.stock,
    category: formData.category,
    status: formData.status,
  }

  submitting.value = true
  try {
    if (editingId.value !== null) {
      await updateProduct(editingId.value, payload as UpdateProductData)
    } else {
      await createProduct(payload)
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

async function handleDelete(row: Product) {
  try {
    await ElMessageBox.confirm('确定删除该商品吗？', '提示', { type: 'warning' })
    await deleteProduct(row.product_id)
    ElMessage.success('删除成功')
    tableRef.value?.refresh()
  } catch (e: any) {
    if (e !== 'cancel') {
      ElMessage.error(e.message || '删除失败')
    }
  }
}

async function handleToggleStatus(row: Product, val: boolean) {
  const newStatus: 'active' | 'inactive' = val ? 'active' : 'inactive'
  try {
    await updateProductStatus(row.product_id, newStatus)
    row.status = newStatus
    ElMessage.success(val ? '已上架' : '已下架')
  } catch (e: any) {
    ElMessage.error(e.message || '操作失败')
  }
}

function handleDialogClose() {
  formRef.value?.resetFields()
}

// ========== SKU 管理 ==========

const skuVisible = ref(false)
const skuLoading = ref(false)
const skuProduct = ref<Product | null>(null)
const skus = ref<ProductSku[]>([])

const skuFormVisible = ref(false)
const skuFormTitle = ref('添加 SKU')
const skuSubmitting = ref(false)
const skuFormRef = ref<FormInstance>()
const editingSkuId = ref<number | null>(null)

const skuFormData = reactive({
  name: '',
  specText: '',
  price: 0,
  points_price: 0,
  stock: 0,
  status: 'active' as 'active' | 'inactive',
})

const skuFormRules: FormRules = {
  name: [{ required: true, message: '请输入 SKU 名称', trigger: 'blur' }],
  price: [{ required: true, message: '请输入现金价格', trigger: 'blur' }],
}

function formatSpec(spec: Record<string, string> | null): string {
  if (!spec) return ''
  return Object.entries(spec)
    .map(([k, v]) => `${k}:${v}`)
    .join(' / ')
}

async function handleOpenSkus(row: Product) {
  // 显式拷贝字段：ProTable 传入的 row 为表格行代理，直接引用可能丢失属性
  skuProduct.value = { ...row }
  skuVisible.value = true
  await loadSkus()
}

async function loadSkus() {
  if (!skuProduct.value) return
  skuLoading.value = true
  try {
    skus.value = await getSkuList(skuProduct.value.product_id)
  } catch (e: any) {
    ElMessage.error(e.message || '获取 SKU 列表失败')
    skus.value = []
  } finally {
    skuLoading.value = false
  }
}

function handleAddSku() {
  skuFormTitle.value = '添加 SKU'
  editingSkuId.value = null
  Object.assign(skuFormData, { name: '', specText: '', price: 0, points_price: 0, stock: 0, status: 'active' })
  skuFormVisible.value = true
}

function handleEditSku(row: ProductSku) {
  skuFormTitle.value = '编辑 SKU'
  editingSkuId.value = row.sku_id
  Object.assign(skuFormData, {
    name: row.name,
    specText: row.spec_attrs ? JSON.stringify(row.spec_attrs) : '',
    price: Number(row.price) || 0,
    points_price: Number(row.points_price) || 0,
    stock: row.stock ?? 0,
    status: row.status,
  })
  skuFormVisible.value = true
}

async function handleSkuSubmit() {
  if (!skuFormRef.value || !skuProduct.value) return
  try {
    await skuFormRef.value.validate()
  } catch {
    return
  }

  let specAttrs: Record<string, string> | undefined
  if (skuFormData.specText.trim()) {
    try {
      const parsed = JSON.parse(skuFormData.specText)
      if (typeof parsed !== 'object' || Array.isArray(parsed) || parsed === null) {
        throw new Error('not object')
      }
      specAttrs = parsed as Record<string, string>
    } catch {
      ElMessage.error('规格属性必须是合法的 JSON 对象，如 {"颜色":"红色"}')
      return
    }
  }

  const payload: SaveSkuData = {
    name: skuFormData.name,
    price: skuFormData.price,
    points_price: skuFormData.points_price,
    stock: skuFormData.stock,
    status: skuFormData.status,
  }
  if (specAttrs) payload.spec_attrs = specAttrs

  skuSubmitting.value = true
  try {
    if (editingSkuId.value !== null) {
      await updateSku(skuProduct.value.product_id, editingSkuId.value, payload)
    } else {
      await createSku(skuProduct.value.product_id, payload)
    }
    ElMessage.success('操作成功')
    skuFormVisible.value = false
    await loadSkus()
  } catch (e: any) {
    ElMessage.error(e.message || '操作失败')
  } finally {
    skuSubmitting.value = false
  }
}

async function handleDeleteSku(row: ProductSku) {
  if (!skuProduct.value) return
  try {
    await ElMessageBox.confirm('确定删除该 SKU 吗？', '提示', { type: 'warning' })
    await deleteSku(skuProduct.value.product_id, row.sku_id)
    ElMessage.success('删除成功')
    await loadSkus()
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

.image-uploader :deep(.el-upload) {
  border: 1px dashed var(--el-border-color);
  border-radius: 6px;
  cursor: pointer;
  position: relative;
  overflow: hidden;
  width: 120px;
  height: 120px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: border-color 0.3s;
}

.image-uploader :deep(.el-upload:hover) {
  border-color: var(--el-color-primary);
}

.preview-image {
  width: 120px;
  height: 120px;
}

.upload-placeholder {
  font-size: 28px;
  color: var(--el-text-color-secondary);
}
</style>
