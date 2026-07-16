// Module page loader (Console)
// Discovers module pages under src/Modules/<Name>/resources/console/views/*.vue

export interface ModuleRoute {
  path: string
  name: string
  component: () => Promise<any>
  meta?: Record<string, any>
}

const moduleViews = import.meta.glob(
  '../../../src/Modules/*/resources/console/views/*.vue',
  { eager: false }
)

const moduleRoutesFiles = import.meta.glob(
  '../../../src/Modules/*/resources/console/routes.ts',
  { eager: false }
)

function extractModuleName(path: string): string | null {
  const match = path.match(/src\/Modules\/([^/]+)\/resources\/console\//)
  return match ? match[1] : null
}

function extractPageName(path: string): string | null {
  const match = path.match(/views\/([^/]+)\.vue$/)
  return match ? match[1] : null
}

export async function loadModuleRoutes(): Promise<ModuleRoute[]> {
  const routes: ModuleRoute[] = []

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

export async function loadModuleViews(): Promise<ModuleRoute[]> {
  const routes: ModuleRoute[] = []
  const hasCustomRoutes = new Set<string>()

  for (const path of Object.keys(moduleRoutesFiles)) {
    const name = extractModuleName(path)
    if (name) hasCustomRoutes.add(name)
  }

  for (const [path, loader] of Object.entries(moduleViews)) {
    const moduleName = extractModuleName(path)
    const pageName = extractPageName(path)
    if (!moduleName || !pageName) continue

    if (hasCustomRoutes.has(moduleName)) continue
    if (pageName === 'Login') continue

    const knownPaths: Record<string, string> = {
      Members: 'members', Credits: 'credits', OAuthSettings: 'oauth',
      SmsSettings: 'sms', ApiTokens: 'api-tokens', PaymentSettings: 'payment',
      Workflows: 'workflows', SSL: 'ssl', Webhooks: 'webhooks',
      TenantSettings: 'tenant-settings', TenantDetail: 'tenants/:id',
      Tickets: 'tickets',
    }
    const routePath = knownPaths[pageName]
      || pageName.replace(/([a-z])([A-Z])/g, '$1-$2').replace(/([A-Z]+)([A-Z][a-z])/g, '$1-$2').toLowerCase()

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

    const knownPaths: Record<string, string> = {
      Members: 'members', Credits: 'credits', OAuthSettings: 'oauth',
      SmsSettings: 'sms', ApiTokens: 'api-tokens', PaymentSettings: 'payment',
      Workflows: 'workflows', SSL: 'ssl', Webhooks: 'webhooks',
      TenantSettings: 'tenant-settings', TenantDetail: 'tenants/:id',
      Tickets: 'tickets',
    }
    const routePath = knownPaths[pageName]
      || pageName.replace(/([a-z])([A-Z])/g, '$1-$2').replace(/([A-Z]+)([A-Z][a-z])/g, '$1-$2').toLowerCase()
    const label = pageName.replace(/([a-z])([A-Z])/g, '$1 $2').replace(/([A-Z]+)([A-Z][a-z])/g, '$1 $2').replace(/^./, s => s.toUpperCase()).trim()

    entries.push({ moduleName, pageName, path: routePath, label })
  }

  return entries
}
