<template>
  <div class="page-container">
    <el-card>
      <template #header>
        <div class="card-header">
          <span>直播间管理</span>
          <div class="header-actions">
            <el-select v-model="statusFilter" placeholder="全部状态" clearable style="width: 130px" @change="loadRooms">
              <el-option label="待开播" value="scheduled" />
              <el-option label="直播中" value="living" />
              <el-option label="已结束" value="ended" />
            </el-select>
            <el-button type="primary" @click="handleCreate"> 新建直播间 </el-button>
          </div>
        </div>
      </template>

      <el-table :data="rooms" v-loading="loading" stripe>
        <el-table-column prop="room_id" label="ID" width="80" />
        <el-table-column prop="title" label="直播间标题" min-width="180" show-overflow-tooltip />
        <el-table-column label="供给方" width="90">
          <template #default="{ row }">{{ providerLabel(row.provider) }}</template>
        </el-table-column>
        <el-table-column label="状态" width="90">
          <template #default="{ row }">
            <el-tag :type="statusTag(row.status)">{{ statusLabel(row.status) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="scheduled_at" label="计划时间" width="170" />
        <el-table-column prop="replay_url" label="回放" min-width="140" show-overflow-tooltip />
        <el-table-column label="操作" width="330">
          <template #default="{ row }">
            <el-button v-if="row.status === 'scheduled'" link type="success" size="small" @click="handleStart(row)">开始</el-button>
            <el-button v-if="row.status === 'living'" link type="warning" size="small" @click="handleEnd(row)">结束</el-button>
            <el-button link type="primary" size="small" @click="showStreamUrls(row)">推流地址</el-button>
            <el-button v-if="row.status === 'ended' && row.course_id" link type="success" size="small" @click="handlePublishReplay(row)">回放发布</el-button>
            <el-button link type="primary" size="small" @click="openChat(row)">聊天记录</el-button>
            <el-button link type="primary" size="small" @click="handleEdit(row)">编辑</el-button>
          </template>
        </el-table-column>
      </el-table>
    </el-card>

    <!-- 房间编辑 -->
    <el-dialog v-model="dialogVisible" :title="editingId ? '编辑直播间' : '新建直播间'" width="640px" :close-on-click-modal="false">
      <el-form ref="formRef" :model="form" :rules="formRules" label-width="110px">
        <el-form-item label="标题" prop="title">
          <el-input v-model="form.title" maxlength="255" />
        </el-form-item>
        <el-form-item label="封面URL">
          <el-input v-model="form.cover" placeholder="封面图片URL（可选）" />
        </el-form-item>
        <el-form-item label="供给方" prop="provider">
          <el-radio-group v-model="form.provider" :disabled="!!editingId">
            <el-radio value="manual">手动</el-radio>
            <el-radio value="polyv">保利威</el-radio>
            <el-radio value="tencent">腾讯云</el-radio>
          </el-radio-group>
        </el-form-item>
        <el-alert
          v-if="!editingId && form.provider !== 'manual'"
          :title="`将使用租户/平台设置（group=live）中的 ${form.provider} 凭证自动创建，凭证缺失会提示`"
          type="info"
          :closable="false"
          style="margin-bottom: 12px"
        />
        <template v-if="editingId || form.provider === 'manual'">
          <el-form-item label="第三方房间ID">
            <el-input v-model="form.provider_room_id" placeholder="供给方侧房间/频道标识（可选）" :disabled="!!editingId" />
          </el-form-item>
          <el-form-item label="推流地址">
            <el-input v-model="form.push" placeholder="rtmp://...（manual 模式手填）" />
          </el-form-item>
          <el-form-item label="播放地址">
            <el-input v-model="form.play" placeholder="播放页/流地址（manual 模式手填）" />
          </el-form-item>
        </template>
        <el-form-item label="挂载课程ID">
          <el-input-number v-model="form.course_id" :min="0" placeholder="0=公开直播" />
          <span class="form-tip">挂载后观看需课程权益，回放可转化为课程章节</span>
        </el-form-item>
        <el-form-item label="计划开播">
          <el-date-picker v-model="form.scheduled_at" type="datetime" value-format="YYYY-MM-DD HH:mm:ss" />
        </el-form-item>
      </el-form>
      <template #footer>
        <el-button @click="dialogVisible = false">取消</el-button>
        <el-button type="primary" :loading="submitting" @click="submitRoom">确定</el-button>
      </template>
    </el-dialog>

    <!-- 推流地址 -->
    <el-dialog v-model="urlsVisible" title="推流/播放地址" width="640px">
      <el-form label-width="90px">
        <el-form-item label="推流地址">
          <el-input :model-value="streamUrls.push || '—'" readonly type="textarea" :rows="2" />
        </el-form-item>
        <el-form-item label="播放地址">
          <el-input :model-value="streamUrls.play || '—'" readonly type="textarea" :rows="2" />
        </el-form-item>
      </el-form>
    </el-dialog>

    <!-- 聊天记录 -->
    <el-drawer v-model="chatVisible" :title="`聊天记录 - ${chatRoom?.title ?? ''}`" size="420px">
      <div v-if="chatLoading" v-loading="chatLoading" style="min-height: 200px" />
      <el-empty v-else-if="chatMessages.length === 0" description="暂无聊天记录（弹幕回调落库）" />
      <div v-else style="display: flex; flex-direction: column; gap: 10px">
        <div v-for="msg in chatMessages" :key="msg.message_id">
          <div style="font-size: 12px; color: var(--el-text-color-secondary)">
            {{ msg.nick ?? '匿名' }} · {{ msg.sent_at }}
          </div>
          <div>{{ msg.content }}</div>
        </div>
      </div>
    </el-drawer>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue'
import { ElMessage, ElMessageBox, type FormInstance, type FormRules } from 'element-plus'
import {
  getRooms, createRoom, updateRoom, startRoom, endRoom,
  getStreamUrls, publishReplay, getChatMessages,
  type LiveRoom, type ChatMessage,
} from '@modules/Live/api/live'

defineOptions({ name: 'LiveRooms' })

const PROVIDERS: Record<string, string> = { manual: '手动', polyv: '保利威', tencent: '腾讯云' }
const STATUS: Record<string, { label: string; tag: 'info' | 'success' | 'danger' }> = {
  scheduled: { label: '待开播', tag: 'info' },
  living: { label: '直播中', tag: 'success' },
  ended: { label: '已结束', tag: 'danger' },
}

function providerLabel(v: string) {
  return PROVIDERS[v] ?? v
}

function statusLabel(v: string) {
  return STATUS[v]?.label ?? v
}

function statusTag(v: string) {
  return STATUS[v]?.tag ?? 'info'
}

// ========== 房间 ==========

const rooms = ref<LiveRoom[]>([])
const loading = ref(false)
const submitting = ref(false)
const statusFilter = ref('')
const dialogVisible = ref(false)
const formRef = ref<FormInstance>()
const editingId = ref<number | null>(null)

const form = reactive({
  title: '',
  cover: '',
  provider: 'manual' as LiveRoom['provider'],
  provider_room_id: '',
  push: '',
  play: '',
  course_id: 0,
  scheduled_at: '',
})

const formRules: FormRules = {
  title: [{ required: true, message: '请输入直播间标题', trigger: 'blur' }],
}

async function loadRooms() {
  loading.value = true
  try {
    rooms.value = await getRooms({ status: statusFilter.value || undefined })
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '获取直播间列表失败')
  } finally {
    loading.value = false
  }
}

function handleCreate() {
  editingId.value = null
  Object.assign(form, { title: '', cover: '', provider: 'manual', provider_room_id: '', push: '', play: '', course_id: 0, scheduled_at: '' })
  dialogVisible.value = true
}

function handleEdit(row: LiveRoom) {
  editingId.value = row.room_id
  Object.assign(form, {
    title: row.title,
    cover: row.cover ?? '',
    provider: row.provider,
    provider_room_id: row.provider_room_id ?? '',
    push: row.config?.push ?? '',
    play: row.config?.play ?? '',
    course_id: row.course_id ?? 0,
    scheduled_at: row.scheduled_at ?? '',
  })
  dialogVisible.value = true
}

async function submitRoom() {
  if (!formRef.value) return
  try { await formRef.value.validate() } catch { return }

  const payload: Record<string, unknown> = {
    title: form.title,
    cover: form.cover || undefined,
    provider: form.provider,
    course_id: form.course_id > 0 ? form.course_id : undefined,
    scheduled_at: form.scheduled_at || undefined,
  }
  if (!editingId.value || form.provider === 'manual') {
    payload.provider_room_id = form.provider_room_id || undefined
    payload.config = {
      ...(form.push ? { push: form.push } : {}),
      ...(form.play ? { play: form.play } : {}),
    }
  }

  submitting.value = true
  try {
    if (editingId.value !== null) {
      await updateRoom(editingId.value, payload as any)
    } else {
      await createRoom(payload as any)
    }
    ElMessage.success('操作成功')
    dialogVisible.value = false
    await loadRooms()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '操作失败')
  } finally {
    submitting.value = false
  }
}

async function handleStart(row: LiveRoom) {
  try {
    await startRoom(row.room_id)
    ElMessage.success('直播已开始')
    await loadRooms()
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '开始失败（凭证缺失会提示）')
  }
}

async function handleEnd(row: LiveRoom) {
  try {
    const { value } = await ElMessageBox.prompt('回放地址（可选，稍后也可单独更新）', '结束直播', {
      inputPlaceholder: 'https://...',
    })
    await endRoom(row.room_id, value || undefined)
    ElMessage.success('直播已结束')
    await loadRooms()
  } catch (e: any) {
    if (e !== 'cancel') ElMessage.error(e?.response?.data?.message || '结束失败')
  }
}

// ========== 推流地址 ==========

const urlsVisible = ref(false)
const streamUrls = reactive<{ push: string | null; play: string | null }>({ push: null, play: null })

async function showStreamUrls(row: LiveRoom) {
  try {
    const urls = await getStreamUrls(row.room_id)
    streamUrls.push = urls.push
    streamUrls.play = urls.play
    urlsVisible.value = true
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '获取推流地址失败（凭证缺失会提示）')
  }
}

// ========== 回放发布 ==========

async function handlePublishReplay(row: LiveRoom) {
  try {
    const { value } = await ElMessageBox.prompt('回放地址（留空使用房间已存回放）', '发布回放为课程章节', {
      inputPlaceholder: 'https://...',
    })
    await publishReplay(row.room_id, value || undefined)
    ElMessage.success('已发布为挂载课程的视频章节')
    await loadRooms()
  } catch (e: any) {
    if (e !== 'cancel') ElMessage.error(e?.response?.data?.message || '发布失败')
  }
}

// ========== 聊天记录 ==========

const chatVisible = ref(false)
const chatLoading = ref(false)
const chatRoom = ref<LiveRoom | null>(null)
const chatMessages = ref<ChatMessage[]>([])

async function openChat(row: LiveRoom) {
  chatRoom.value = row
  chatVisible.value = true
  chatLoading.value = true
  try {
    chatMessages.value = await getChatMessages(row.room_id)
  } catch (e: any) {
    ElMessage.error(e?.response?.data?.message || '获取聊天记录失败')
  } finally {
    chatLoading.value = false
  }
}

onMounted(loadRooms)
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
</style>
