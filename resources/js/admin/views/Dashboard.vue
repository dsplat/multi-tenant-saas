<template>
  <div class="dashboard">
    <el-row :gutter="20">
      <el-col :span="6">
        <el-card shadow="hover">
          <template #header>
            <div class="card-header">
              <span>租户总数</span>
              <el-icon><OfficeBuilding /></el-icon>
            </div>
          </template>
          <div class="card-content">
            <span class="number">{{ stats.tenantCount }}</span>
          </div>
        </el-card>
      </el-col>
      
      <el-col :span="6">
        <el-card shadow="hover">
          <template #header>
            <div class="card-header">
              <span>用户总数</span>
              <el-icon><User /></el-icon>
            </div>
          </template>
          <div class="card-content">
            <span class="number">{{ stats.userCount }}</span>
          </div>
        </el-card>
      </el-col>
      
      <el-col :span="6">
        <el-card shadow="hover">
          <template #header>
            <div class="card-header">
              <span>活跃租户</span>
              <el-icon><CircleCheck /></el-icon>
            </div>
          </template>
          <div class="card-content">
            <span class="number">{{ stats.activeTenantCount }}</span>
          </div>
        </el-card>
      </el-col>
      
      <el-col :span="6">
        <el-card shadow="hover">
          <template #header>
            <div class="card-header">
              <span>今日新增</span>
              <el-icon><TrendCharts /></el-icon>
            </div>
          </template>
          <div class="card-content">
            <span class="number">{{ stats.todayNewCount }}</span>
          </div>
        </el-card>
      </el-col>
    </el-row>
    
    <el-row :gutter="20" style="margin-top: 20px;">
      <el-col :span="12">
        <el-card>
          <template #header>
            <span>最近租户</span>
          </template>
          <el-table :data="recentTenants" style="width: 100%">
            <el-table-column prop="name" label="租户名称" />
            <el-table-column prop="status" label="状态">
              <template #default="{ row }">
                <el-tag :type="row.status === 'active' ? 'success' : 'danger'">
                  {{ row.status === 'active' ? '活跃' : '未激活' }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="created_at" label="创建时间" />
          </el-table>
        </el-card>
      </el-col>
      
      <el-col :span="12">
        <el-card>
          <template #header>
            <span>系统信息</span>
          </template>
          <el-descriptions :column="1" border>
            <el-descriptions-item label="框架版本">1.0.0</el-descriptions-item>
            <el-descriptions-item label="Laravel 版本">12.x</el-descriptions-item>
            <el-descriptions-item label="PHP 版本">8.2+</el-descriptions-item>
            <el-descriptions-item label="数据库">MySQL 8.0+</el-descriptions-item>
          </el-descriptions>
        </el-card>
      </el-col>
    </el-row>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const stats = ref({
  tenantCount: 0,
  userCount: 0,
  activeTenantCount: 0,
  todayNewCount: 0,
})

const recentTenants = ref([])

const fetchStats = async () => {
  try {
    // 这里调用实际 API
    // const response = await axios.get('/api/v1/admin/stats')
    // stats.value = response.data.data
    
    // 临时使用模拟数据
    stats.value = {
      tenantCount: 10,
      userCount: 156,
      activeTenantCount: 8,
      todayNewCount: 3,
    }
  } catch (error) {
    console.error('获取统计数据失败:', error)
  }
}

const fetchRecentTenants = async () => {
  try {
    // 这里调用实际 API
    // const response = await axios.get('/api/v1/tenants?per_page=5&sort_by=created_at&sort_direction=desc')
    // recentTenants.value = response.data.data
    
    // 临时使用模拟数据
    recentTenants.value = [
      { name: '示例企业A', status: 'active', created_at: '2026-06-18' },
      { name: '示例企业B', status: 'active', created_at: '2026-06-17' },
      { name: '示例企业C', status: 'inactive', created_at: '2026-06-16' },
    ]
  } catch (error) {
    console.error('获取最近租户失败:', error)
  }
}

onMounted(() => {
  fetchStats()
  fetchRecentTenants()
})
</script>

<style scoped>
.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.card-content {
  text-align: center;
}

.number {
  font-size: 32px;
  font-weight: bold;
  color: #409eff;
}
</style>
