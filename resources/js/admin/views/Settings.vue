<template>
  <div class="settings-page">
    <div class="page-header"><h2>系统设置</h2></div>
    <div class="panel">
      <form @submit.prevent="handleSave">
        <div class="form-group">
          <label>系统名称</label>
          <input v-model="settings.app_name" />
        </div>
        <div class="form-group">
          <label>系统 URL</label>
          <input v-model="settings.app_url" />
        </div>
        <div class="form-group">
          <label>管理员邮箱</label>
          <input v-model="settings.admin_email" type="email" />
        </div>
        <div class="form-group">
          <label>默认套餐</label>
          <select v-model="settings.default_plan">
            <option value="free">免费版</option>
            <option value="pro">专业版</option>
          </select>
        </div>
        <div class="form-group">
          <label>默认积分</label>
          <input v-model.number="settings.default_credits" type="number" />
        </div>
        <button type="submit" class="primary-btn">保存设置</button>
      </form>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'

const settings = ref({
  app_name: 'Multi-Tenant SaaS',
  app_url: 'http://ai.lyt.com',
  admin_email: 'admin@example.com',
  default_plan: 'free',
  default_credits: 500,
})

const handleSave = () => {
  alert('保存成功')
}
</script>

<style scoped>
.page-header { margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; max-width: 600px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 6px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input, .form-group select { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; font-size: 14px; box-sizing: border-box; background: var(--bg-color, #fff); color: var(--text-color-primary, #333); }
.primary-btn { padding: 10px 24px; border: none; border-radius: 6px; background: var(--primary-color, #409eff); color: #fff; font-size: 14px; cursor: pointer; }
</style>
