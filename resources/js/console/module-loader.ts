// Module page loader (Console)
// Discovers module pages via absolute-path glob from project root.
// Priority: local framework → vendor framework → local bootstrap → vendor bootstrap

export interface ModuleRoute {
  path: string
  name: string
  component: () => Promise<any>
  meta?: Record<string, any>
}

// 绝对路径 glob — 从项目根开始
const frameworkModuleViews = import.meta.glob(
  '/vendor/dsplat/module-*/resources/console/ui/*/views/*.vue',
  { eager: false }
)

const localModuleViews = import.meta.glob(
  '/src/Modules/*/resources/console/ui/*/views/*.vue',
  { eager: false }
)

const moduleRoutesFiles = import.meta.glob(
  '/src/Modules/*/resources/console/routes.ts',
  { eager: false }
)

function getFramework(): string {
  return localStorage.getItem('multi-tenant-saas-ui-framework')
    || (import.meta.env.VITE_UI_FRAMEWORK as string)
    || 'element-plus'
}

function extractModuleName(path: string, isVendor: boolean): string | null {
  const pattern = isVendor
    ? /vendor\/dsplat\/module-([^/]+)\//
    : /src\/Modules\/([^/]+)\/resources\/console\//
  const match = path.match(pattern)
  return match ? match[1] : null
}

function extractPageName(path: string): string | null {
  const match = path.match(/views\/([^/]+)\.vue$/)
  return match ? match[1] : null
}

function extractFramework(path: string): string | null {
  const match = path.match(/\/ui\/([^/]+)\/views\//)
  return match ? match[1] : null
}

const knownPaths: Record<string, string> = {
  Members: 'members', Credits: 'credits', OAuthSettings: 'oauth',
  SmsSettings: 'sms', ApiTokens: 'api-tokens', PaymentSettings: 'payment',
  Workflows: 'workflows', SSL: 'ssl', Webhooks: 'webhooks',
  TenantSettings: 'tenant-settings', TenantDetail: 'tenants/:id',
  Tickets: 'tickets', TenantSettingsPage: 'settings',
}

// Load custom routes from module routes.ts files
export async function loadModuleRoutes(): Promise<ModuleRoute[]> {
  const routes: ModuleRoute[] = []

  for (const [path, loader] of Object.entries(moduleRoutesFiles)) {
    const moduleName = extractModuleName(path, false)
    if (!moduleName) continue

    try {
      const mod = await (loader as () => Promise<any>)()
      const moduleRoutes = mod.default || mod
      if (Array.isArray(moduleRoutes)) {
        routes.push(...moduleRoutes)
      }
    } catch (e) {
      console.warn(`[ModuleLoader] 加载模块 ${moduleName} 路由失败:`, e)
    }
  }

  return routes
}

// Load module views with priority chain
export async function loadModuleViews(): Promise<ModuleRoute[]> {
  const routes: ModuleRoute[] = []
  const fw = getFramework()

  const localFwPages = new Map<string, string>()
  const localBsPages = new Map<string, string>()
  for (const [path] of Object.entries(localModuleViews)) {
    const pageName = extractPageName(path)
    const pageFw = extractFramework(path)
    if (!pageName || pageName === 'Login') continue
    if (pageFw === fw) localFwPages.set(pageName, path)
    if (pageFw === 'bootstrap') localBsPages.set(pageName, path)
  }

  const vendorFwPages = new Map<string, string>()
  const vendorBsPages = new Map<string, string>()
  for (const [path] of Object.entries(frameworkModuleViews)) {
    const pageName = extractPageName(path)
    const pageFw = extractFramework(path)
    if (!pageName || pageName === 'Login') continue
    if (pageFw === fw) vendorFwPages.set(pageName, path)
    if (pageFw === 'bootstrap') vendorBsPages.set(pageName, path)
  }

  const hasCustomRoutes = new Set<string>()
  for (const [path] of Object.entries(moduleRoutesFiles)) {
    const name = extractModuleName(path, false)
    if (name) hasCustomRoutes.add(name)
  }

  function makeRoute(pageName: string, path: string, moduleName: string, isVendor: boolean): ModuleRoute | null {
    if (hasCustomRoutes.has(moduleName)) return null
    const routePath = knownPaths[pageName]
      || pageName.replace(/([a-z])([A-Z])/g, '$1-$2').replace(/([A-Z]+)([A-Z][a-z])/g, '$1-$2').toLowerCase()
    const loader = isVendor ? frameworkModuleViews[path] : localModuleViews[path]
    return {
      path: routePath,
      name: `${moduleName}${pageName}`,
      component: () => (loader as () => Promise<any>)(),
      meta: { title: pageName, requiresAuth: true, module: moduleName },
    }
  }

  // 优先级：本地 framework → vendor framework → 本地 bootstrap → vendor bootstrap
  const seen = new Set<string>()

  for (const [pageName, path] of localFwPages) {
    const moduleName = extractModuleName(path, false)
    if (!moduleName || seen.has(pageName)) continue
    seen.add(pageName)
    const r = makeRoute(pageName, path, moduleName, false)
    if (r) routes.push(r)
  }
  for (const [pageName, path] of vendorFwPages) {
    if (seen.has(pageName)) continue
    const moduleName = extractModuleName(path, true)
    if (!moduleName || seen.has(pageName)) continue
    seen.add(pageName)
    const r = makeRoute(pageName, path, moduleName, true)
    if (r) routes.push(r)
  }
  for (const [pageName, path] of localBsPages) {
    if (seen.has(pageName)) continue
    const moduleName = extractModuleName(path, false)
    if (!moduleName || seen.has(pageName)) continue
    seen.add(pageName)
    const r = makeRoute(pageName, path, moduleName, false)
    if (r) routes.push(r)
  }
  for (const [pageName, path] of vendorBsPages) {
    if (seen.has(pageName)) continue
    const moduleName = extractModuleName(path, true)
    if (!moduleName || seen.has(pageName)) continue
    seen.add(pageName)
    const r = makeRoute(pageName, path, moduleName, true)
    if (r) routes.push(r)
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
  const fw = getFramework()

  const allPages = new Map<string, { moduleName: string; path: string }>()

  for (const [path] of Object.entries(localModuleViews)) {
    const pageName = extractPageName(path)
    const pageFw = extractFramework(path)
    if (!pageName || pageName === 'Login') continue
    if (pageFw !== fw) continue
    const moduleName = extractModuleName(path, false)
    if (!moduleName) continue
    if (!allPages.has(pageName)) allPages.set(pageName, { moduleName, path })
  }
  for (const [path] of Object.entries(frameworkModuleViews)) {
    const pageName = extractPageName(path)
    const pageFw = extractFramework(path)
    if (!pageName || pageName === 'Login') continue
    if (pageFw !== fw) continue
    const moduleName = extractModuleName(path, true)
    if (!moduleName) continue
    if (!allPages.has(pageName)) allPages.set(pageName, { moduleName, path })
  }
  for (const [path] of Object.entries(localModuleViews)) {
    const pageName = extractPageName(path)
    const pageFw = extractFramework(path)
    if (!pageName || pageName === 'Login') continue
    if (pageFw !== 'bootstrap') continue
    const moduleName = extractModuleName(path, false)
    if (!moduleName) continue
    if (!allPages.has(pageName)) allPages.set(pageName, { moduleName, path })
  }
  for (const [path] of Object.entries(frameworkModuleViews)) {
    const pageName = extractPageName(path)
    const pageFw = extractFramework(path)
    if (!pageName || pageName === 'Login') continue
    if (pageFw !== 'bootstrap') continue
    const moduleName = extractModuleName(path, true)
    if (!moduleName) continue
    if (!allPages.has(pageName)) allPages.set(pageName, { moduleName, path })
  }

  for (const [pageName, { moduleName }] of allPages) {
    const routePath = knownPaths[pageName]
      || pageName.replace(/([a-z])([A-Z])/g, '$1-$2').replace(/([A-Z]+)([A-Z][a-z])/g, '$1-$2').toLowerCase()
    const label = pageName.replace(/([a-z])([A-Z])/g, '$1 $2').replace(/([A-Z]+)([A-Z][a-z])/g, '$1 $2').replace(/^./, s => s.toUpperCase()).trim()

    entries.push({ moduleName, pageName, path: routePath, label })
  }

  return entries
}
