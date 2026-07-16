// Module page loader
// Discovers module pages under src/Modules/<Name>/resources/admin/views/*.vue
// Modules can also provide routes.ts for custom route definitions.

export interface ModuleRoute {
  path: string
  name: string
  component: () => Promise<any>
  meta?: Record<string, any>
}

// 自动发现模块页面（Vite 构建时展开为静态导入）
const moduleViews = import.meta.glob(
  '../../../src/Modules/*/resources/admin/views/*.vue',
  { eager: false }
)

const moduleRoutesFiles = import.meta.glob(
  '../../../src/Modules/*/resources/admin/routes.ts',
  { eager: false }
)

// Extract module name from path (e.g., Auth from src/Modules/Auth/resources/admin/views/Login.vue)
function extractModuleName(path: string): string | null {
  const match = path.match(/src\/Modules\/([^/]+)\/resources\/admin\//)
  return match ? match[1] : null
}

// Extract page name from path (e.g., TenantList from views/TenantList.vue)
function extractPageName(path: string): string | null {
  const match = path.match(/views\/([^/]+)\.vue$/)
  return match ? match[1] : null
}

// Load custom routes from module routes.ts files
export async function loadModuleRoutes(): Promise<ModuleRoute[]> {
  const routes: ModuleRoute[] = []

  // 加载有 routes.ts 的模块
  for (const [path, loader] of Object.entries(moduleRoutesFiles)) {
    const moduleName = extractModuleName(path)
    if (!moduleName) continue

    try {
      const mod = await loader()
      const moduleRoutes = (mod as any).default || mod
      if (Array.isArray(moduleRoutes)) {
        routes.push(...moduleRoutes)
      }
    } catch (e) {
      console.warn(`[ModuleLoader] 加载模块 ${moduleName} 路由失败:`, e)
    }
  }

  return routes
}

// Load module views (auto-generate routes from Vue file names)
export async function loadModuleViews(): Promise<ModuleRoute[]> {
  const routes: ModuleRoute[] = []
  const hasCustomRoutes = new Set<string>()

  // 收集有自定义路由的模块
  for (const path of Object.keys(moduleRoutesFiles)) {
    const name = extractModuleName(path)
    if (name) hasCustomRoutes.add(name)
  }

  // 为没有自定义路由的模块生成默认路由
  for (const [path, loader] of Object.entries(moduleViews)) {
    const moduleName = extractModuleName(path)
    const pageName = extractPageName(path)
    if (!moduleName || !pageName) continue

    // 跳过有自定义路由的模块
    if (hasCustomRoutes.has(moduleName)) continue

    // 跳过 Login.vue（登录页在核心 SPA 中）
    if (pageName === 'Login') continue

    const routePath = pageName.replace(/([A-Z])/g, '-$1').toLowerCase().replace(/^-/, '')

    routes.push({
      path: routePath,
      name: `${moduleName}${pageName}`,
      component: () => loader() as Promise<any>,
      meta: {
        title: pageName,
        requiresAuth: true,
        module: moduleName,
      },
    })
  }

  return routes
}

// Get all module routes (custom + auto-generated)
export async function getAllModuleRoutes(): Promise<ModuleRoute[]> {
  const [customRoutes, autoRoutes] = await Promise.all([
    loadModuleRoutes(),
    loadModuleViews(),
  ])

  return [...customRoutes, ...autoRoutes]
}

// Module pages discovered at build time — available for sidebar rendering
export function getModulePageEntries(): Array<{ moduleName: string; pageName: string; path: string; label: string }> {
  const entries: Array<{ moduleName: string; pageName: string; path: string; label: string }> = []

  for (const [path] of Object.entries(moduleViews)) {
    const moduleName = extractModuleName(path)
    const pageName = extractPageName(path)
    if (!moduleName || !pageName || pageName === 'Login') continue

    const routePath = pageName.replace(/([A-Z])/g, '-$1').toLowerCase().replace(/^-/, '')
    const label = pageName.replace(/([A-Z])/g, ' $1').replace(/^./, s => s.toUpperCase()).trim()

    entries.push({ moduleName, pageName, path: routePath, label })
  }

  return entries
}
