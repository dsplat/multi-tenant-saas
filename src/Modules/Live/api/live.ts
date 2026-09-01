/**
 * Live 模块 Console API（scrm 网关：/api/v1/scrm/live-*）
 */
import { http } from '@/shared/http'

const BASE = '/scrm'

// ========== 类型 ==========

export interface LiveRoom {
  room_id: number
  title: string
  cover: string | null
  course_id: number | null
  provider: 'manual' | 'polyv' | 'tencent'
  provider_room_id: string | null
  config: { push?: string; play?: string; channel_id?: number; stream_name?: string } | null
  status: 'scheduled' | 'living' | 'ended'
  scheduled_at: string | null
  started_at: string | null
  ended_at: string | null
  replay_url: string | null
}

export interface ChatMessage {
  message_id: number
  room_id: number
  nick: string | null
  content: string
  sent_at: string | null
}

// ========== 房间生命周期 ==========

export async function getRooms(params: { status?: string; course_id?: number } = {}): Promise<LiveRoom[]> {
  const res = await http.get<LiveRoom[]>(`${BASE}/live-rooms`, { params })
  return res.data ?? []
}

export async function createRoom(data: Partial<LiveRoom> & { title: string }): Promise<LiveRoom> {
  const res = await http.post<LiveRoom>(`${BASE}/live-rooms`, data)
  return res.data
}

export async function updateRoom(id: number, data: Partial<LiveRoom>): Promise<LiveRoom> {
  const res = await http.patch<LiveRoom>(`${BASE}/live-rooms/${id}`, data)
  return res.data
}

export async function startRoom(id: number): Promise<LiveRoom> {
  const res = await http.post<LiveRoom>(`${BASE}/live-rooms/${id}/start`)
  return res.data
}

export async function endRoom(id: number, replayUrl?: string): Promise<LiveRoom> {
  const res = await http.post<LiveRoom>(`${BASE}/live-rooms/${id}/end`, replayUrl ? { replay_url: replayUrl } : {})
  return res.data
}

export async function getStreamUrls(id: number): Promise<{ push: string | null; play: string | null }> {
  const res = await http.get<{ push: string | null; play: string | null }>(`${BASE}/live-rooms/${id}/stream-urls`)
  return res.data
}

export async function publishReplay(id: number, replayUrl?: string): Promise<unknown> {
  const res = await http.post(`${BASE}/live-rooms/${id}/publish-replay`, replayUrl ? { replay_url: replayUrl } : {})
  return res.data
}

export async function getChatMessages(id: number, limit = 200): Promise<ChatMessage[]> {
  const res = await http.get<ChatMessage[]>(`${BASE}/live-rooms/${id}/chat-messages`, { params: { limit } })
  return res.data ?? []
}
