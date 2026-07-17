<template>
  <div class="page">
    <div class="page-header">
      <h2>订阅计划</h2>
      <el-button type="primary" :icon="Plus" @click="openCreate">创建计划</el-button>
    </div>

    <el-card shadow="never">
      <el-table :data="plans" stripe style="width: 100%" empty-text="暂无订阅计划">
        <el-table-column label="ID" width="80">
          <template #default="{ row }">{{ row.id ?? row.plan_id }}</template>
        </el-table-column>
        <el-table-column prop="name" label="名称" />
        <el-table-column prop="slug" label="标识" />
        <el-table-column label="价格" width="100">
          <template #default="{ row }">¥{{ row.price }}</template>
        </el-table-column>
        <el-table-column label="计费周期" width="100">
          <template #default="{ row }">{{ row.billing_cycle === 'monthly' ? '月付' : '年付' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="80">
          <template #default="{ row }">
            <el-tag :type="row.is_active ? 'success' : 'danger'" size="small">
              {{ row.is_active ? '启用' : '禁用' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="120">
          <template #default="{ row }">
            <el-button link type="primary" size="small" @click="openEdit(row)">编辑</el-button>
            <el-button link type="danger" size="small" @click="handleDelete(row)">删除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="dialogVisible" :title="isEdit ? '编辑计划' : '创建计划'" width="460px">
      <el-form :model="form" label-width="100px">
        <el-form-item label="名称"><el-input v-model="form.name" /></el-form-item>
        <el-form-item label="标识"><el-input v-model="form.slug" :disabled="isEdit" /></el-form-item>
        <el-form-item label="价格">
          <el-input-number v-model="form.price" :min="0" :precision="2" :step="0.01" controls-position="right" style="width: 100%" />
        </el-form-item>
        <el-form-item label="计费周期">
          <el-select v-model="form.billing_cycle" style="width: 100%">
            <el-option label="月付" value="monthly" />
            <el-option label="年付" value="yearly" />
          </el-select>
        </el-form-item>
        <el-form-item label="功能特性">
          <el-input v-model="featuresInput" placeholder="feature1,feature2" />
        </el-form-item>
        <el-form-item label="启用">
          <el-switch v-model="form.is_active" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" @click="handleSubmit">确定</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { Plus } from '@element-plus/icons-vue'
import { ElMessage, ElMessageBox } from 'element-plus'

const API = '/v1/admin/admin/billing/plans'
const plans = ref<any[]>([])
const dialogVisible = ref(false)
const isEdit = ref(false)
const editId = ref<string | number>('')
const form = ref({ name: '', slug: '', price: 0, billing_cycle: 'monthly', features: [] as string[], is_active: true })
const featuresInput = ref('')

const fetchPlans = async () => {
  try { const r = await axios.get(API); plans.value = r.data.data || [] } catch {}
}

const openCreate = () => {
  isEdit.value = false
  form.value = { name: '', slug: '', price: 0, billing_cycle: 'monthly', features: [], is_active: true }
  featuresInput.value = ''
  dialogVisible.value = true
}

const openEdit = (p: any) => {
  isEdit.value = true
  editId.value = p.id ?? p.plan_id
  form.value = { name: p.name, slug: p.slug, price: p.price, billing_cycle: p.billing_cycle, features: p.features || [], is_active: p.is_active ?? true }
  featuresInput.value = (p.features || []).join(',')
  dialogVisible.value = true
}

const handleSubmit = async () => {
  const payload = { ...form.value, features: featuresInput.value ? featuresInput.value.split(',').map(s => s.trim()) : [] }
  try {
    if (isEdit.value) await axios.put(`${API}/${editId.value}`, payload)
    else await axios.post(API, payload)
    dialogVisible.value = false
    await fetchPlans()
    ElMessage.success(isEdit.value ? '更新成功' : '创建成功')
  } catch {}
}

const handleDelete = async (p: any) => {
  try {
    await ElMessageBox.confirm(`确定删除计划 ${p.name}？`, '警告', { type: 'error' })
    await axios.delete(`${API}/${p.id ?? p.plan_id}`)
    await fetchPlans()
    ElMessage.success('删除成功')
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '删除失败')
  }
}

onMounted(fetchPlans)
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
</style>
