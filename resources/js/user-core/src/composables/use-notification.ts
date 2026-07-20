/**
 * 通知中心业务逻辑（框架无关）
 */

import type {
  Notification,
  NotificationCategory,
  NotificationPreference,
  NotificationQuery,
  UnreadCount,
} from '../types'
import * as notificationApi from '../api/notification.api'

/** 通知列表状态 */
export interface NotificationListState {
  items: Notification[]
  currentPage: number
  lastPage: number
  total: number
  unreadCount: number
  unreadByType: Record<string, number>
  loading: boolean
}

/**
 * 获取通知列表
 */
export async function fetchNotifications(query?: NotificationQuery): Promise<{
  success: boolean
  items?: Notification[]
  meta?: NotificationListState
  error?: string
}> {
  try {
    const res = await notificationApi.getNotifications(query)
    return {
      success: true,
      items: res.data,
      meta: {
        items: res.data,
        currentPage: res.meta.current_page,
        lastPage: res.meta.last_page,
        total: res.meta.total,
        unreadCount: res.meta.unread_count,
        unreadByType: res.meta.unread_by_type,
        loading: false,
      },
    }
  } catch (err: any) {
    return { success: false, error: err.message || '获取通知失败' }
  }
}

/**
 * 获取通知分类
 */
export async function fetchCategories(): Promise<{
  success: boolean
  categories?: NotificationCategory[]
  error?: string
}> {
  try {
    const res = await notificationApi.getCategories()
    if (!res.success) {
      return { success: false, error: res.message }
    }
    return { success: true, categories: res.data || [] }
  } catch (err: any) {
    return { success: false, error: err.message }
  }
}

/**
 * 获取未读数量
 */
export async function fetchUnreadCount(): Promise<{
  success: boolean
  unread?: UnreadCount
  error?: string
}> {
  try {
    const res = await notificationApi.getUnreadCount()
    if (!res.success) {
      return { success: false, error: res.message }
    }
    return {
      success: true,
      unread: {
        unread_count: (res as any).unread_count ?? res.data?.unread_count ?? 0,
        unread_by_type: (res as any).unread_by_type ?? res.data?.unread_by_type ?? {},
      },
    }
  } catch (err: any) {
    return { success: false, error: err.message }
  }
}

/**
 * 标记单条通知为已读
 */
export async function markNotificationRead(id: number): Promise<{
  success: boolean
  error?: string
}> {
  try {
    const res = await notificationApi.markAsRead(id)
    if (!res.success) {
      return { success: false, error: res.message }
    }
    return { success: true }
  } catch (err: any) {
    return { success: false, error: err.message }
  }
}

/**
 * 批量标记已读
 */
export async function batchMarkRead(ids: number[]): Promise<{
  success: boolean
  markedCount?: number
  error?: string
}> {
  try {
    const res = await notificationApi.batchMarkRead(ids)
    if (!res.success) {
      return { success: false, error: res.message }
    }
    return { success: true, markedCount: res.data?.marked_count }
  } catch (err: any) {
    return { success: false, error: err.message }
  }
}

/**
 * 全部标记已读
 */
export async function markAllRead(): Promise<{
  success: boolean
  markedCount?: number
  error?: string
}> {
  try {
    const res = await notificationApi.markAllRead()
    if (!res.success) {
      return { success: false, error: res.message }
    }
    return { success: true, markedCount: res.data?.marked_count }
  } catch (err: any) {
    return { success: false, error: err.message }
  }
}

/**
 * 删除通知
 */
export async function removeNotification(id: number): Promise<{
  success: boolean
  error?: string
}> {
  try {
    const res = await notificationApi.deleteNotification(id)
    if (!res.success) {
      return { success: false, error: res.message }
    }
    return { success: true }
  } catch (err: any) {
    return { success: false, error: err.message }
  }
}

/**
 * 清除所有已读通知
 */
export async function clearReadNotifications(): Promise<{
  success: boolean
  clearedCount?: number
  error?: string
}> {
  try {
    const res = await notificationApi.clearRead()
    if (!res.success) {
      return { success: false, error: res.message }
    }
    return { success: true, clearedCount: res.data?.cleared_count }
  } catch (err: any) {
    return { success: false, error: err.message }
  }
}

// ========== 通知偏好 ==========

/**
 * 获取通知偏好设置
 */
export async function fetchPreferences(): Promise<{
  success: boolean
  preferences?: NotificationPreference[]
  error?: string
}> {
  try {
    const res = await notificationApi.getPreferences()
    if (!res.success) {
      return { success: false, error: res.message }
    }
    return { success: true, preferences: res.data || [] }
  } catch (err: any) {
    return { success: false, error: err.message }
  }
}

/**
 * 设置通知偏好
 */
export async function updatePreference(
  channel: string,
  enabled: boolean,
  type?: string | null
): Promise<{
  success: boolean
  error?: string
}> {
  try {
    const res = await notificationApi.setPreference(channel, enabled, type)
    if (!res.success) {
      return { success: false, error: res.message }
    }
    return { success: true }
  } catch (err: any) {
    return { success: false, error: err.message }
  }
}
