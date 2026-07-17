<template>
  <div class="page">
    <div class="page-header">
      <h2>插件管理</h2>
      <el-button type="primary" @click="showInstall = true">+ 安装插件</el-button>
    </div>

    <el-card shadow="never">
      <el-table :data="plugins" stripe style="width: 100%" empty-text="暂无已安装插件">
        <el-table-column label="插件名">
          <template #default="{ row }"><strong>{{ row.name }}</strong></template>
        </el-table-column>
        <el-table-column label="版本" width="100">
          <template #default="{ row }">{{ row.version || '-' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="100">
          <template #default="{ row }">
            <el-tag :type="statusType(row.status)" size="small">{{ statusLabel(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="安装时间" width="180">
          <template #default="{ row }">{{ row.installed_at || '-' }}</template>
        </el-table-column>
        <el-table-column label="操作" width="200">
          <template #default="{ row }">
            <el-button v-if="row.status === 'installed' || row.status === 'disabled'" link type="primary" size="small" @click="enablePlugin(row)">启用</el-button>
            <el-button v-if="row.status === 'enabled'" link type="warning" size="small" @click="disablePlugin(row)">禁用</el-button>
            <el-button link type="danger" size="small" @click="uninstallPlugin(row)">卸载</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <el-dialog v-model="showInstall" title="安装插件" width="420px">
      <el-form :model="form" label-width="80px">
        <el-form-item label="插件名称">
          <el-input v-model="form.name" placeholder="plugin-name" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="showInstall = false">取消</el-button>
        <el-button type="primary" @click="handleInstall">安装</el-button>
      </template>
    </el-dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'
import { ElMessage, ElMessageBox } from 'element-plus'

const API = '/v1/admin/admin/plugins'
const plugins = ref<any[]>([])
const showInstall = ref(false)
const form = ref({ name: '' })

const statusType = (s: string) => ({ enabled: 'success', disabled: 'danger', installed: 'info', error: 'warning' }[s] || 'info')
const statusLabel = (s: string) => ({ enabled: '已启用', disabled: '已禁用', installed: '已安装', error: '错误' }[s] || s)

const fetchPlugins = async () => {
  try {
    const r = await axios.get(API)
    plugins.value = r.data.data || []
  } catch {}
}

const handleInstall = async () => {
  try {
    await axios.post(`${API}/${form.value.name}/install`)
    showInstall.value = false
    form.value.name = ''
    await fetchPlugins()
    ElMessage.success('安装成功')
  } catch (e: any) {
    ElMessage.error(e.response?.data?.message || '安装失败')
  }
}

const enablePlugin = async (p: any) => {
  try {
    await axios.post(`${API}/${p.name}/enable`)
    await fetchPlugins()
    ElMessage.success('已启用')
  } catch {}
}

const disablePlugin = async (p: any) => {
  try {
    await axios.post(`${API}/${p.name}/disable`)
    await fetchPlugins()
    ElMessage.success('已禁用')
  } catch {}
}

const uninstallPlugin = async (p: any) => {
  try {
    await ElMessageBox.confirm(`确定卸载插件 ${p.name}？`, '警告', { type: 'warning' })
    await axios.post(`${API}/${p.name}/uninstall`)
    await fetchPlugins()
    ElMessage.success('已卸载')
  } catch (e: any) {
    if (e !== 'cancel' && e?.response) ElMessage.error(e.response?.data?.message || '卸载失败')
  }
}

onMounted(fetchPlugins)
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
</style>
