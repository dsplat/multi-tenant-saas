/**
 * 通知相关类型定义
 */

/** 站内通知 */
export interface Notification {
  id: number
  user_id: number
  type: string
  title: string
  content?: string
  data?: Record<string, unknown>
  read_at?: string | null
  created_at: string
}

/** 通知分类 */
export interface NotificationCategory {
  type: string
  label: string
  count?: number
}

/** 未读数量统计 */
export interface UnreadCount {
  unread_count: number
  unread_by_type: Record<string, number>
}

/** 通知偏好设置项 */
export interface NotificationPreference {
  channel: string
  type: string | null
  enabled: boolean
}

/** 通知偏好批量设置请求 */
export interface BatchPreferenceRequest {
  preferences: NotificationPreference[]
}

/** 通知列表查询参数 */
export interface NotificationQuery {
  type?: string
  unread_only?: boolean
  per_page?: number
  page?: number
}

/** 通知列表响应 */
export interface NotificationListResponse {
  data: Notification[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    unread_count: number
    unread_by_type: Record<string, number>
  }
}
