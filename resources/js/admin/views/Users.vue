<template>
  <div class="users">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>用户管理</span>
        </div>
      </template>
      
      <el-table :data="users" style="width: 100%" v-loading="loading">
        <el-table-column prop="user_id" label="ID" width="180" />
        <el-table-column prop="name" label="姓名" />
        <el-table-column prop="email" label="邮箱" />
        <el-table-column prop="role" label="角色" width="120">
          <template #default="{ row }">
            <el-tag :type="row.role === 'super_admin' ? 'danger' : 'info'">
              {{ row.role === 'super_admin' ? '超级管理员' : '普通用户' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="is_active" label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="row.is_active ? 'success' : 'danger'">
              {{ row.is_active ? '激活' : '未激活' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="created_at" label="创建时间" width="180" />
      </el-table>
    </el-card>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const loading = ref(false)
const users = ref([])

const fetchUsers = async () => {
  loading.value = true
  try {
    // 这里调用实际 API
    // const response = await axios.get('/api/v1/admin/users')
    // users.value = response.data.data
    
    // 临时使用模拟数据
    users.value = [
      { user_id: '1', name: '系统管理员', email: 'admin@example.com', role: 'super_admin', is_active: true, created_at: '2026-06-18' },
      { user_id: '2', name: '张三', email: 'zhangsan@example.com', role: 'platform_user', is_active: true, created_at: '2026-06-18' },
    ]
  } catch (error) {
    console.error('获取用户列表失败:', error)
  } finally {
    loading.value = false
  }
}

onMounted(() => {
  fetchUsers()
})
</script>

<style scoped>
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
</style>
