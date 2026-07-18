<template>
  <div class="page">
    <div class="page-header">
      <h2>申请字段配置</h2>
      <el-button type="primary" :loading="saving" @click="handleSave">保存</el-button>
    </div>
    <el-card shadow="never">
      <el-table :data="fields" style="width: 100%">
        <el-table-column label="字段名" prop="name" width="150" />
        <el-table-column label="标签" width="150">
          <template #default="{ row }">
            <el-input v-model="row.label" size="small" />
          </template>
        </el-table-column>
        <el-table-column label="类型" width="120">
          <template #default="{ row }">
            <el-select v-model="row.type" size="small" style="width: 100%">
              <el-option v-for="t in types" :key="t" :label="t" :value="t" />
            </el-select>
          </template>
        </el-table-column>
        <el-table-column label="必填" width="80">
          <template #default="{ row }">
            <el-switch v-model="row.required" />
          </template>
        </el-table-column>
        <el-table-column label="启用" width="80">
          <template #default="{ row }">
            <el-switch v-model="row.enabled" />
          </template>
        </el-table-column>
        <el-table-column label="排序" width="80">
          <template #default="{ row }">
            <el-input-number v-model="row.sort" :min="0" size="small" />
          </template>
        </el-table-column>
      </el-table>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage } from 'element-plus'

const fields = ref<any[]>([])
const saving = ref(false)
const types = ['text', 'textarea', 'select', 'tel', 'email', 'number']

onMounted(async () => {
  try {
    const res = await axios.get('/v1/admin/apply-fields')
    fields.value = res.data.data?.fields || []
  } catch {}
})

const handleSave = async () => {
  saving.value = true
  try {
    await axios.put('/v1/admin/apply-fields', { fields: fields.value })
    ElMessage.success('保存成功')
  } catch {
    ElMessage.error('保存失败')
  } finally { saving.value = false }
}
</script>
