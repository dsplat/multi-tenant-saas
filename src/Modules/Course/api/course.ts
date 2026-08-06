import { http, extractListResult, tradeApiPrefix } from '@/shared/http'

// ========== 类型定义 ==========

export interface Course {
  course_id: number
  title: string
  cover: string | null
  description: string | null
  price: string | number
  points_price: number
  sale_mode: 'cash' | 'points' | 'mixed'
  completion_reward_points: number
  status: 'draft' | 'published' | 'offline'
  created_at: string
  updated_at: string
}

export interface CourseChapter {
  chapter_id: number
  course_id: number
  sort_order: number
  title: string
  type: 'text' | 'video' | 'file'
  content: string | null
  file_url: string | null
}

export interface CourseListParams {
  page: number
  pageSize: number
  status?: string
  search?: string
}

export interface CourseListResult {
  data: Course[]
  total: number
}

export interface SaveCourseData {
  title: string
  cover?: string
  description?: string
  price?: number
  points_price?: number
  sale_mode?: 'cash' | 'points' | 'mixed'
  completion_reward_points?: number
}

export interface SaveChapterData {
  sort_order?: number
  title: string
  type?: 'text' | 'video' | 'file'
  content?: string
  file_url?: string
}

// ========== 课程 CRUD ==========

export async function getCourseList(params: CourseListParams): Promise<CourseListResult> {
  const query: Record<string, unknown> = {
    page: params.page,
    per_page: params.pageSize,
  }
  if (params.status) query.status = params.status
  if (params.search) query.search = params.search
  const res = await http.get<Course[]>(`${tradeApiPrefix()}/courses`, { params: query })
  return extractListResult(res)
}

export async function createCourse(data: SaveCourseData): Promise<Course> {
  const res = await http.post<Course>(`${tradeApiPrefix()}/courses`, data)
  return res.data
}

export async function updateCourse(id: number, data: Partial<SaveCourseData>): Promise<Course> {
  const res = await http.put<Course>(`${tradeApiPrefix()}/courses/${id}`, data)
  return res.data
}

export async function deleteCourse(id: number): Promise<void> {
  await http.delete(`${tradeApiPrefix()}/courses/${id}`)
}

export async function publishCourse(id: number): Promise<Course> {
  const res = await http.patch<Course>(`${tradeApiPrefix()}/courses/${id}/publish`)
  return res.data
}

export async function offlineCourse(id: number): Promise<Course> {
  const res = await http.patch<Course>(`${tradeApiPrefix()}/courses/${id}/offline`)
  return res.data
}

// ========== 章节管理 ==========

export async function getChapters(courseId: number): Promise<CourseChapter[]> {
  const res = await http.get<CourseChapter[]>(`${tradeApiPrefix()}/courses/${courseId}/chapters`)
  return Array.isArray(res.data) ? res.data : []
}

export async function createChapter(courseId: number, data: SaveChapterData): Promise<CourseChapter> {
  const res = await http.post<CourseChapter>(`${tradeApiPrefix()}/courses/${courseId}/chapters`, data)
  return res.data
}

export async function updateChapter(
  courseId: number,
  chapterId: number,
  data: Partial<SaveChapterData>,
): Promise<CourseChapter> {
  const res = await http.put<CourseChapter>(`${tradeApiPrefix()}/courses/${courseId}/chapters/${chapterId}`, data)
  return res.data
}

export async function deleteChapter(courseId: number, chapterId: number): Promise<void> {
  await http.delete(`${tradeApiPrefix()}/courses/${courseId}/chapters/${chapterId}`)
}
