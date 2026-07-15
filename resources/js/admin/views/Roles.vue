<template>
  <div class="page">
    <div class="page-header"><h2>角色权限</h2><button class="primary-btn" @click="showCreate = true">+ 创建角色</button></div>
    <div class="panel">
      <table class="data-table">
        <thead><tr><th>ID</th><th>角色名</th><th>显示名</th><th>描述</th><th>权限数</th><th>操作</th></tr></thead>
        <tbody>
          <tr v-for="r in roles" :key="r.role_id">
            <td>{{ r.role_id }}</td><td>{{ r.name }}</td><td>{{ r.display_name }}</td>
            <td>{{ r.description || '-' }}</td><td>{{ r.permissions_count ?? '-' }}</td>
            <td><button class="link-btn" @click="editPerms(r)">权限</button><button class="link-btn danger" @click="deleteRole(r)">删除</button></td>
          </tr>
          <tr v-if="roles.length === 0"><td colspan="6" class="empty-row">暂无角色</td></tr>
        </tbody>
      </table>
    </div>

    <div class="modal-backdrop" v-if="showCreate" @click="showCreate = false">
      <div class="modal-content" @click.stop>
        <h3>创建角色</h3>
        <form @submit.prevent="handleCreate">
          <div class="form-group"><label>角色名</label><input v-model="form.name" required /></div>
          <div class="form-group"><label>显示名</label><input v-model="form.display_name" required /></div>
          <div class="form-group"><label>描述</label><input v-model="form.description" /></div>
          <div class="form-actions"><button type="button" @click="showCreate = false">取消</button><button type="submit" class="primary-btn">创建</button></div>
        </form>
      </div>
    </div>

    <div class="modal-backdrop" v-if="permRole" @click="permRole = null">
      <div class="modal-content" @click.stop>
        <h3>编辑权限 — {{ permRole.display_name }}</h3>
        <div class="perm-list">
          <label v-for="p in allPermissions" :key="p.permission_id" class="perm-item">
            <input type="checkbox" :value="p.permission_id" v-model="selectedPerms" />
            <span>{{ p.name }}</span>
          </label>
        </div>
        <div class="form-actions"><button @click="permRole = null">取消</button><button class="primary-btn" @click="savePerms">保存</button></div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import axios from 'axios'

const roles = ref<any[]>([])
const allPermissions = ref<any[]>([])
const showCreate = ref(false)
const form = ref({ name: '', display_name: '', description: '' })
const permRole = ref<any>(null)
const selectedPerms = ref<number[]>([])

const fetchRoles = async () => { try { const r = await axios.get('/v1/admin/auth/roles'); roles.value = r.data.data || [] } catch {} }
const fetchPerms = async () => { try { const r = await axios.get('/v1/admin/auth/permissions'); allPermissions.value = r.data.data?.flat?.() || r.data.data || [] } catch {} }

const handleCreate = async () => {
  try { await axios.post('/v1/admin/auth/roles', form.value); showCreate.value = false; form.value = { name: '', display_name: '', description: '' }; await fetchRoles() } catch {}
}

const deleteRole = async (r: any) => {
  if (!confirm(`确定删除角色 ${r.display_name}？`)) return
  try { await axios.delete(`/v1/admin/auth/roles/${r.role_id}`); await fetchRoles() } catch {}
}

const editPerms = (r: any) => { permRole.value = r; selectedPerms.value = (r.permissions || []).map((p: any) => p.permission_id ?? p); permRole.value && fetchPerms() }

const savePerms = async () => {
  try { await axios.put(`/v1/admin/auth/roles/${permRole.value.role_id}/permissions`, { permissions: selectedPerms.value }); permRole.value = null; await fetchRoles() } catch {}
}

onMounted(() => { fetchRoles(); fetchPerms() })
</script>

<style scoped>
.page-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; }
.page-header h2 { margin: 0; }
.primary-btn { padding: 8px 16px; background: var(--primary-color, #409eff); color: #fff; border: none; border-radius: 6px; cursor: pointer; }
.panel { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th, .data-table td { text-align: left; padding: 10px 12px; border-bottom: 1px solid var(--border-color, #eee); font-size: 13px; }
.empty-row { text-align: center; color: var(--text-color-secondary, #999); padding: 24px; }
.link-btn { background: none; border: none; color: var(--primary-color, #409eff); cursor: pointer; font-size: 13px; padding: 0 4px; }
.link-btn.danger { color: #f5222d; }
.modal-backdrop { position: fixed; inset: 0; background: rgba(0,0,0,0.4); display: flex; align-items: center; justify-content: center; z-index: 1000; }
.modal-content { background: var(--bg-color, #fff); border-radius: 8px; padding: 24px; min-width: 400px; max-width: 600px; max-height: 80vh; overflow-y: auto; }
.modal-content h3 { margin: 0 0 20px; }
.form-group { margin-bottom: 16px; }
.form-group label { display: block; margin-bottom: 4px; font-size: 13px; color: var(--text-color-secondary, #666); }
.form-group input { width: 100%; padding: 8px 12px; border: 1px solid var(--border-color, #ddd); border-radius: 6px; box-sizing: border-box; }
.form-actions { display: flex; justify-content: flex-end; gap: 8px; margin-top: 20px; }
.form-actions button { padding: 8px 16px; border-radius: 6px; border: 1px solid var(--border-color, #ddd); background: #fff; cursor: pointer; }
.perm-list { display: flex; flex-wrap: wrap; gap: 8px; max-height: 300px; overflow-y: auto; }
.perm-item { display: flex; align-items: center; gap: 4px; font-size: 13px; padding: 4px 8px; border: 1px solid var(--border-color, #eee); border-radius: 4px; cursor: pointer; }
</style>
