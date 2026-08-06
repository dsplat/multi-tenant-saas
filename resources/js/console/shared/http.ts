import axios, { type AxiosRequestConfig, type InternalAxiosRequestConfig } from 'axios'

/**
 * 框架 Console 共享 HTTP 客户端
 *
 * 供框架模块前端（src/Modules/<Name>/api 下的 ts 文件）统一调用：
 * - baseURL /api/v1，Bearer auth_token + X-Tenant-ID 头注入
 * - 响应拦截解包 response.data；401 自动跳转 /console/login
 */

export interface ApiResponse<T = unknown> {
  data: T
  message?: string
  meta?: {
    current_page?: number
    per_page?: number
    total?: number
  }
  total?: number
}

export interface ListResult<T> {
  data: T[]
  total: number
}

/**
 * Axios response interceptor 已做 response.data 解包，
 * 实际返回 ApiResponse<T> 而非 AxiosResponse<T>。
 */
interface HttpInstance {
  get<T = unknown>(url: string, config?: AxiosRequestConfig): Promise<ApiResponse<T>>
  post<T = unknown>(
    url: string,
    data?: unknown,
    config?: AxiosRequestConfig,
  ): Promise<ApiResponse<T>>
  put<T = unknown>(
    url: string,
    data?: unknown,
    config?: AxiosRequestConfig,
  ): Promise<ApiResponse<T>>
  patch<T = unknown>(
    url: string,
    data?: unknown,
    config?: AxiosRequestConfig,
  ): Promise<ApiResponse<T>>
  delete<T = unknown>(url: string, config?: AxiosRequestConfig): Promise<ApiResponse<T>>
}

const instance = axios.create({
  baseURL: '/api/v1',
  timeout: 30_000,
  headers: { 'Content-Type': 'application/json' },
})

instance.interceptors.request.use(
  (config: InternalAxiosRequestConfig) => {
    const token = localStorage.getItem('auth_token')
    if (token) {
      config.headers.Authorization = `Bearer ${token}`
    }
    const tenantId = localStorage.getItem('auth_tenant_id')
    if (tenantId) {
      config.headers['X-Tenant-ID'] = tenantId
    }
    return config
  },
  (error) => Promise.reject(error),
)

instance.interceptors.response.use(
  (response) => response.data,
  (error) => {
    // token 过期/失效：清除会话并跳转登录页（登录请求与登录页自身除外，防循环）
    const status = error?.response?.status
    const reqUrl: string = error?.config?.url || ''
    if (
      status === 401 &&
      !reqUrl.includes('/auth/login') &&
      !window.location.pathname.startsWith('/console/login')
    ) {
      localStorage.removeItem('auth_token')
      const redirect = encodeURIComponent(
        window.location.pathname.replace(/^\/console/, '') + window.location.search,
      )
      window.location.href = `/console/login?redirect=${redirect}`
    }
    return Promise.reject(error)
  },
)

const http = instance as unknown as HttpInstance
export { http }

/**
 * 交易域模块 API 子前缀（运行时读取，项目 console 启动时设置 window.__TRADE_API_PREFIX__）
 *
 * - 框架默认 ''：/api/v1/products、/api/v1/orders …
 * - scrm 设置 '/scrm'：保持既有 /api/v1/scrm/* URL 零变更
 */
export function tradeApiPrefix(): string {
  return (window as unknown as { __TRADE_API_PREFIX__?: string }).__TRADE_API_PREFIX__ || ''
}

/**
 * 列表响应统一解包
 * 兼容三种后端返回形态：
 * 1. Laravel paginator 包裹：{ data: { data: [...], total, current_page, ... } }
 * 2. 扁平数组 + meta：{ data: [...], meta: { total } }
 * 3. 扁平数组 + total：{ data: [...], total }
 */
export function extractListResult<T>(
  res: ApiResponse<T[] | { data: T[]; total?: number }>,
): ListResult<T> {
  const raw = res.data as unknown
  if (Array.isArray(raw)) {
    return { data: raw, total: res.meta?.total ?? res.total ?? raw.length }
  }
  const paginator = (raw ?? {}) as { data?: T[]; total?: number }
  const items = Array.isArray(paginator.data) ? paginator.data : []
  return { data: items, total: paginator.total ?? res.meta?.total ?? res.total ?? items.length }
}
