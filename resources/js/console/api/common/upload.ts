import { http } from '@/shared/http'

/**
 * Console 通用图片上传
 */
export interface ImageUploadResult {
  url: string
}

const ALLOWED_IMAGE_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp']
const MAX_FILE_SIZE = 2 * 1024 * 1024

export function validateImageFile(file: File): string | null {
  if (!ALLOWED_IMAGE_TYPES.includes(file.type)) {
    return '只能上传 JPG/PNG/GIF/WEBP 格式的图片'
  }
  if (file.size > MAX_FILE_SIZE) {
    return '图片大小不能超过 2MB'
  }
  return null
}

export async function uploadImage(file: File): Promise<ImageUploadResult> {
  const error = validateImageFile(file)
  if (error) {
    throw new Error(error)
  }
  const form = new FormData()
  form.append('file', file)
  const res = await http.post<ImageUploadResult>('/upload', form)
  if (!res.data?.url) {
    throw new Error('上传失败：未返回有效的图片地址')
  }
  return res.data
}
