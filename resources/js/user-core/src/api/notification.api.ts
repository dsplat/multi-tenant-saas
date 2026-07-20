/**
 * 通知中心相关 API 函数
 */

import type {
  ApiResponse,
  BatchPreferenceRequest,
  NotificationCategory,
  NotificationListResponse,
  NotificationPreference,
  NotificationQuery,
  UnreadCount,
} from '../types'
import { NOTIFICATION_ENDPOINTS } from './endpoints'
import { request } from './client'

/** 获取通知列表（分页） */
export function getNotifications(query?: NotificationQuery): Promise<NotificationListResponse> {
  return request(NOTIFICATION_ENDPOINTS.LIST, {
    params: {
      type: query?.type,
      unread_only: query?.unread_only,
      per_page: query?.per_page,
      page: query?.page,
    },
  })
}

/** 获取通知分类 */
export function getCategories(): Promise<ApiResponse<NotificationCategory[]>> {
  return request(NOTIFICATION_ENDPOINTS.CATEGORIES)
}

/** 获取未读数量 */
export function getUnreadCount(): Promise<ApiResponse<UnreadCount>> {
  return request(NOTIFICATION_ENDPOINTS.UNREAD_COUNT)
}

/** 标记单条通知为已读 */
export function markAsRead(id: number): Promise<ApiResponse> {
  return request(NOTIFICATION_ENDPOINTS.MARK_READ(id), { method: 'POST' })
}

/** 批量标记已读 */
export function batchMarkRead(ids: number[]): Promise<ApiResponse<{ marked_count: number }>> {
  return request(NOTIFICATION_ENDPOINTS.BATCH_READ, {
    method: 'POST',
    body: { ids },
  })
}

/** 全部标记已读 */
export function markAllRead(): Promise<ApiResponse<{ marked_count: number }>> {
  return request(NOTIFICATION_ENDPOINTS.READ_ALL, { method: 'POST' })
}

/** 删除通知 */
export function deleteNotification(id: number): Promise<ApiResponse> {
  return request(NOTIFICATION_ENDPOINTS.DELETE(id), { method: 'DELETE' })
}

/** 清除所有已读通知 */
export function clearRead(): Promise<ApiResponse<{ cleared_count: number }>> {
  return request(NOTIFICATION_ENDPOINTS.CLEAR_READ, { method: 'DELETE' })
}

/** 获取通知偏好 */
export function getPreferences(): Promise<ApiResponse<NotificationPreference[]>> {
  return request(NOTIFICATION_ENDPOINTS.PREFERENCES)
}

/** 设置单条通知偏好 */
export function setPreference(
  channel: string,
  enabled: boolean,
  type?: string | null
): Promise<ApiResponse> {
  return request(NOTIFICATION_ENDPOINTS.PREFERENCES, {
    method: 'POST',
    body: { channel, type, enabled },
  })
}

/** 批量设置通知偏好 */
export function batchSetPreferences(data: BatchPreferenceRequest): Promise<ApiResponse> {
  return request(NOTIFICATION_ENDPOINTS.PREFERENCES_BATCH, {
    method: 'POST',
    body: data,
  })
}
