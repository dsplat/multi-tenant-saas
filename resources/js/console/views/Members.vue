<template>
  <div class="page">
    <div class="page-header">
      <h2>成员管理</h2>
      <button class="primary-btn" @click="showInvite = true">+ 邀请成员</button>
    </div>

    <div class="panel">
      <table class="data-table">
        <thead><tr><th>用户ID</th><th>姓名</th><th>邮箱</th><th>角色</th><th>状态</th><th>加入时间</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="m in members" :key="m.user_id">
            <td>{{ m.user_id }}</td><td>{{ m.name }}</td><td>{{ m.email }}</td>
            <td><span :class="['badge', m.role === 'tenant_admin' ? 'badge-warning' : 'badge-info']">{{ roleLabel(m.role) }}</span></td>
            <td><span :class="['badge', m.is_active ? 'badge-success' : 'badge-danger']">{{ m.is_active ? '激活' : '未激活' }}</span></td>
            <td>{{ formatDate(m.joined_at ?? m.created_at) }}</td>
            <td>
              <button class="link-btn" @click="openEdit(m)">编辑</button>
              <button class="link-btn danger" @click="handleRemove(m)">移除</button>
            </td>
          </tr>
          <tr v-if="members.length === 0"><td colspan="7" class="empty-row">暂无成员</td></tr>
        </tbody>
      </table>
    </div>

    <div class="modal-backdrop" v-if="showInvite" @click="showInvite = false">
      <div class="modal-content" @click.stop>
        <h3>邀请成员</h3>
        <form @submit.prevent="handleInvite">
          <div class="form-group"><label>邮箱</label><input v-model="inviteForm.email" type="email" required /></div>
          <div class="form-group"><label>角色</label><select v-model="inviteForm.role"><option value="end_user">成员</option><option value="tenant_admin">管理员</option></select></div>
          <div class="form-actions"><button type="button" @click="showInvite = false">取消</button><button type="submit" class="primary-btn">发送邀请</button></div>
        </form>
      </div>
    </div>

    <div class="modal-backdrop" v-if="editMember" @click="editMember = null">
      <div class="modal-content" @click.stop>
        <h3>编辑成员 — {{ editMember.name }}</h3>
        <form @submit.prevent="handleUpdate">
          <div class="form-group"><label>角色</label><select v-model="editForm.role"><option value="end_user">成员</option><option value="tenant_admin">管理员</option></select></div>
          <div class="form-actions"><button type="button" @click="editMember = null">取消</button><button type="submit" class="primary-btn">保存</button></div>
        </form>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import axios from 'axios'

const API = '/tenant/members'
const members = ref<any[]>([])
const showInvite = ref(false)
const inviteForm = reactive({ email: '', role: 'end_user' })
const editMember = ref<any>(null)
const editForm = reactive({ role: 'end_user' })

const formatDate = (d: string) => d ? d.substring(0, 10) : '-'
const roleLabel = (r: string) => ({ tenant_admin: '管理员', end_user: '成员', super_admin: '超级管理员' }[r] || r)

const fetchMembers = async () => { try { const r = await axios.get(API); members.value = r.data.data || [] } catch { members.value = [] } }

const handleInvite = async () => {
  try { await axios.post(API, inviteForm); showInvite.value = false; inviteForm.email = ''; inviteForm.role = 'end_user'; await fetchMembers() } catch (e: any) { alert(e.response?.data?.message || '邀请失败') }
}

const openEdit = (m: any) => { editMember.value = m; editForm.role = m.role }

const handleUpdate = async () => {
  try { await axios.put(`${API}/${editMember.value.user_id}`, { role: editForm.role }); editMember.value = null; await fetchMembers() } catch (e: any) { alert(e.response?.data?.message || '更新失败') }
}

const handleRemove = async (m: any) => {
  if (!confirm(`确定移除成员 ${m.name}？`)) return
  try { await axios.delete(`${API}/${m.user_id}`); await fetchMembers() } catch (e: any) { alert(e.response?.data?.message || '移除失败') }
}

onMounted(fetchMembers)
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.primary-btn { padding: 8px 16px; background: var(--primary-color, #409eff); color: #fff; border: none; border-radius: 6px; cursor: pointer; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.empty-row { text-align: center; color: var(--text-color-secondary, #999); padding: 24px; }
.badge { display: inline-block; padding: 2px 8px; border-radius: 4px; font-size: 12px; }
.badge-success { background: #e6ffed; color: #52c41a; }
.badge-info { background: #eee; color: #666; }
.badge-warning { background: #fff7e6; color: #fa8c16; }
.badge-danger { background: #fff1f0; color: #f5222d; }
.link-btn { background: none; border: none; color: var(--primary-color, #409eff); cursor: pointer; font-size: 13px; padding: 0 4px; }
.link-btn.danger { color: #f5222d; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-content { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; min-width: 400px; }
.modal-content h3 { margin: 0 0 20px; }
.form-group { margin-bottom: 14px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input, .form-group select { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; box-sizing: border-box; }
.form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
.form-actions button { padding: 8px 16px; border-radius: 6px; border: 1px solid var(--border-color, #ddd); background: #fff; cursor: pointer; }
</style>
