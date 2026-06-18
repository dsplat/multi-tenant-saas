<template>
  <div class="tenants">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>租户管理</span>
          <el-button type="primary" @click="handleCreate">
            <el-icon><Plus /></el-icon>
            创建租户
          </el-button>
        </div>
      </template>
      
      <!-- 搜索和筛选 -->
      <el-form :inline="true" :model="filters" class="filter-form">
        <el-form-item label="搜索">
          <el-input
            v-model="filters.search"
            placeholder="租户名称/标识"
            clearable
            @clear="fetchTenants"
            @keyup.enter="fetchTenants"
          />
        </el-form-item>
        <el-form-item label="状态">
          <el-select v-model="filters.status" clearable placeholder="全部" @change="fetchTenants">
            <el-option label="活跃" value="active" />
            <el-option label="未激活" value="inactive" />
            <el-option label="已暂停" value="suspended" />
          </el-select>
        </el-form-item>
        <el-form-item>
          <el-button type="primary" @click="fetchTenants">查询</el-button>
        </el-form-item>
      </el-form>
      
      <!-- 租户列表 -->
      <el-table :data="tenants" style="width: 100%" v-loading="loading">
        <el-table-column prop="tenant_id" label="ID" width="180" />
        <el-table-column prop="name" label="租户名称" />
        <el-table-column prop="slug" label="标识" />
        <el-table-column prop="custom_domain" label="自定义域名" />
        <el-table-column prop="status" label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="getStatusType(row.status)">
              {{ getStatusLabel(row.status) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="subscription_plan" label="套餐" width="100" />
        <el-table-column prop="created_at" label="创建时间" width="180" />
        <el-table-column label="操作" width="200" fixed="right">
          <template #default="{ row }">
            <el-button link type="primary" @click="handleEdit(row)">编辑</el-button>
            <el-button link type="primary" @click="handleView(row)">查看</el-button>
            <el-popconfirm
              title="确定删除这个租户吗？"
              @confirm="handleDelete(row)"
            >
              <template #reference>
                <el-button link type="danger">删除</el-button>
              </template>
            </el-popconfirm>
          </template>
        </el-table-column>
      </el-table>
      
      <!-- 分页 -->
      <el-pagination
        v-model:current-page="pagination.page"
        v-model:page-size="pagination.perPage"
        :page-sizes="[10, 20, 50, 100]"
        :total="pagination.total"
        layout="total, sizes, prev, pager, next, jumper"
        @size-change="fetchTenants"
        @current-change="fetchTenants"
        style="margin-top: 20px; justify-content: flex-end;"
      />
    </el-card>
    
    <!-- 创建/编辑对话框 -->
    <el-dialog
      v-model="dialogVisible"
      :title="isEdit ? '编辑租户' : '创建租户'"
      width="600px"
    >
      <el-form
        ref="formRef"
        :model="form"
        :rules="rules"
        label-width="100px"
      >
        <el-form-item label="租户名称" prop="name">
          <el-input v-model="form.name" placeholder="请输入租户名称" />
        </el-form-item>
        <el-form-item label="标识" prop="slug">
          <el-input v-model="form.slug" placeholder="请输入标识" :disabled="isEdit" />
        </el-form-item>
        <el-form-item label="自定义域名" prop="custom_domain">
          <el-input v-model="form.custom_domain" placeholder="请输入自定义域名" />
        </el-form-item>
        <el-form-item label="状态" prop="status">
          <el-select v-model="form.status" placeholder="请选择状态">
            <el-option label="活跃" value="active" />
            <el-option label="未激活" value="inactive" />
          </el-select>
        </el-form-item>
        <el-form-item label="套餐" prop="subscription_plan">
          <el-select v-model="form.subscription_plan" placeholder="请选择套餐">
            <el-option label="免费版" value="free" />
            <el-option label="专业版" value="pro" />
            <el-option label="企业版" value="enterprise" />
          </el-select>
        </el-form-item>
        <el-form-item label="总积分" prop="total_credits">
          <el-input-number v-model="form.total_credits" :min="0" />
        </el-form-item>
      </el-form>
      
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" @click="handleSubmit" :loading="submitting">
          确定
        </el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { useRouter } from 'vue-router'
import { ElMessage } from 'element-plus'
import axios from 'axios'
import type { FormInstance, FormRules } from 'element-plus'

const router = useRouter()

const loading = ref(false)
const submitting = ref(false)
const dialogVisible = ref(false)
const isEdit = ref(false)
const formRef = ref<FormInstance>()

const tenants = ref([])
const pagination = reactive({
  page: 1,
  perPage: 15,
  total: 0,
})

const filters = reactive({
  search: '',
  status: '',
})

const form = reactive({
  tenant_id: '',
  name: '',
  slug: '',
  custom_domain: '',
  status: 'active',
  subscription_plan: 'free',
  total_credits: 0,
})

const rules: FormRules = {
  name: [
    { required: true, message: '请输入租户名称', trigger: 'blur' },
  ],
  slug: [
    { required: true, message: '请输入标识', trigger: 'blur' },
    { pattern: /^[a-z0-9-]+$/, message: '标识只能包含小写字母、数字和横线', trigger: 'blur' },
  ],
}

const getStatusType = (status: string) => {
  const map: Record<string, string> = {
    active: 'success',
    inactive: 'info',
    suspended: 'danger',
  }
  return map[status] || 'info'
}

const getStatusLabel = (status: string) => {
  const map: Record<string, string> = {
    active: '活跃',
    inactive: '未激活',
    suspended: '已暂停',
  }
  return map[status] || status
}

const fetchTenants = async () => {
  loading.value = true
  try {
    const response = await axios.get('/api/v1/tenants', {
      params: {
        page: pagination.page,
        per_page: pagination.perPage,
        search: filters.search,
        status: filters.status,
      },
    })
    tenants.value = response.data.data
    pagination.total = response.data.meta.total
  } catch (error) {
    console.error('获取租户列表失败:', error)
    ElMessage.error('获取租户列表失败')
  } finally {
    loading.value = false
  }
}

const handleCreate = () => {
  isEdit.value = false
  form.tenant_id = ''
  form.name = ''
  form.slug = ''
  form.custom_domain = ''
  form.status = 'active'
  form.subscription_plan = 'free'
  form.total_credits = 0
  dialogVisible.value = true
}

const handleEdit = (row: any) => {
  isEdit.value = true
  Object.assign(form, row)
  dialogVisible.value = true
}

const handleView = (row: any) => {
  router.push(`/tenants/${row.tenant_id}`)
}

const handleDelete = async (row: any) => {
  try {
    await axios.delete(`/api/v1/tenants/${row.tenant_id}`)
    ElMessage.success('删除成功')
    fetchTenants()
  } catch (error) {
    console.error('删除租户失败:', error)
    ElMessage.error('删除租户失败')
  }
}

const handleSubmit = async () => {
  const valid = await formRef.value?.validate().catch(() => false)
  if (!valid) return
  
  submitting.value = true
  
  try {
    if (isEdit.value) {
      await axios.put(`/api/v1/tenants/${form.tenant_id}`, form)
      ElMessage.success('更新成功')
    } else {
      await axios.post('/api/v1/tenants', form)
      ElMessage.success('创建成功')
    }
    dialogVisible.value = false
    fetchTenants()
  } catch (error) {
    console.error('操作失败:', error)
    ElMessage.error('操作失败')
  } finally {
    submitting.value = false
  }
}

onMounted(() => {
  fetchTenants()
})
</script>

<style scoped>
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.filter-form {
  margin-bottom: 20px;
}
</style>
