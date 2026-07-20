/**
 * HTTP 请求适配器
 *
 * 框架无关的请求抽象层。
 * 下游通过 configure() 注入具体实现（fetch / axios / uni.request）。
 */

export interface RequestOptions {
  method?: 'GET' | 'POST' | 'PUT' | 'PATCH' | 'DELETE'
  body?: unknown
  headers?: Record<string, string>
  params?: Record<string, string | number | boolean | undefined>
}

export interface RequestAdapter {
  (url: string, options?: RequestOptions): Promise<unknown>
}

export interface UserCoreConfig {
  /** 请求适配器实现 */
  request: RequestAdapter
  /** API 基础路径，默认 /api/v1 */
  baseURL?: string
  /** Token 获取函数 */
  getToken?: () => string | null
  /** Token 存储函数 */
  setToken?: (token: string) => void
  /** Token 清除函数 */
  clearToken?: () => void
}

let _config: UserCoreConfig | null = null

/**
 * 配置 user-core 全局实例
 *
 * @example
 * // Vue SPA (fetch)
 * configure({
 *   request: async (url, opts) => {
 *     const res = await fetch(url, { method: opts?.method, ... })
 *     return res.json()
 *   },
 *   baseURL: '/api/v1',
 *   getToken: () => localStorage.getItem('user_token'),
 *   setToken: (t) => localStorage.setItem('user_token', t),
 *   clearToken: () => localStorage.removeItem('user_token'),
 * })
 *
 * @example
 * // uni-app
 * configure({
 *   request: (url, opts) => new Promise((resolve, reject) => {
 *     uni.request({ url, method: opts?.method, data: opts?.body, success: resolve, fail: reject })
 *   }),
 *   getToken: () => uni.getStorageSync('user_token'),
 *   ...
 * })
 */
export function configure(config: UserCoreConfig): void {
  _config = config
}

/**
 * 获取当前配置（内部使用）
 */
export function getConfig(): UserCoreConfig {
  if (!_config) {
    throw new Error(
      '[@dsplat/user-core] Not configured. Call configure() before using API functions.'
    )
  }
  return _config
}

/**
 * 内部请求方法（自动拼接 baseURL + 注入 token）
 */
export async function request<T = unknown>(
  path: string,
  options?: RequestOptions & { skipAuth?: boolean }
): Promise<T> {
  const config = getConfig()
  const baseURL = config.baseURL || '/api/v1'

  // 构建查询参数
  let url = `${baseURL}${path}`
  if (options?.params) {
    const searchParams = new URLSearchParams()
    for (const [key, value] of Object.entries(options.params)) {
      if (value !== undefined) {
        searchParams.set(key, String(value))
      }
    }
    const qs = searchParams.toString()
    if (qs) url += `?${qs}`
  }

  // 注入 Authorization header
  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    ...options?.headers,
  }

  if (!options?.skipAuth && config.getToken) {
    const token = config.getToken()
    if (token) {
      headers['Authorization'] = `Bearer ${token}`
    }
  }

  const response = await config.request(url, {
    method: options?.method || 'GET',
    body: options?.body,
    headers,
  })

  return response as T
}
