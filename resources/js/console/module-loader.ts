// Module page loader (Console)
// Discovers module pages via absolute-path glob from project root.
// Priority: local framework → vendor framework → vendor-core → local bootstrap → vendor bootstrap → vendor-core bootstrap

export interface ModuleRoute {
  path: string
  name: string
  component: () => Promise<any>
  meta?: Record<string, any>
}

export interface NavSection {
  label: string
  items: NavItem[]
}

export interface NavItem {
  path: string
  label: string
  module: string
  icon: string
}

// ---- Glob sources ----

// Project-level module views (when framework is consumed by a downstream project)
// Also covers framework standalone mode (its own src/Modules/)
const projectModuleViews = import.meta.glob(
  '/src/Modules/*/resources/console/ui/*/views/*.vue',
  { eager: false }
)

// Project-level module view files (recursive, for framework-aware view resolution)
const projectModuleViewFiles = import.meta.glob(
  '/src/Modules/*/resources/console/ui/*/views/**/*.vue',
  { eager: false }
)

// Vendor independent packages — broad pattern matches any vendor console module
const frameworkModuleViews = import.meta.glob(
  '/vendor/dsplat/*/resources/console/ui/*/views/*.vue',
  { eager: false }
)

// Vendor core modules (inside main framework package)
const frameworkCoreModuleViews = import.meta.glob(
  '/vendor/dsplat/multi-tenant-saas/src/Modules/*/resources/console/ui/*/views/*.vue',
  { eager: false }
)

// Project local modules (legacy alias — same as projectModuleViews)
const localModuleViews = projectModuleViews

// Module custom route definitions
const moduleRoutesFiles = import.meta.glob(
  '/src/Modules/*/resources/console/routes.ts',
  { eager: false }
)

// Downstream project modules (self-contained: frontend colocated under app/Modules/<Name>/resources/console)
const appModuleViews = import.meta.glob(
  '/app/Modules/*/resources/console/ui/*/views/*.vue',
  { eager: false }
)

const appModuleViewFiles = import.meta.glob(
  '/app/Modules/*/resources/console/ui/*/views/**/*.vue',
  { eager: false }
)

const appModuleRoutesFiles = import.meta.glob(
  '/app/Modules/*/resources/console/routes.ts',
  { eager: false }
)

// ---- Case-insensitive glob lookup ----
// Vite import.meta.glob keys preserve filesystem case (PascalCase on disk).
// Module routes.ts may pass lowercase names (e.g. view('channel', ...)).
// Build lowercase→original maps once for O(1) case-insensitive resolution.

function buildCaseInsensitiveMap(glob: Record<string, any>): Map<string, string> {
  const map = new Map<string, string>()
  for (const key of Object.keys(glob)) {
    map.set(key.toLowerCase(), key)
  }
  return map
}

const projectViewsCI = buildCaseInsensitiveMap(projectModuleViewFiles)
const appViewsCI = buildCaseInsensitiveMap(appModuleViewFiles)

/** Resolve a glob key case-insensitively; returns the loader or undefined. */
function resolveGlobLoader(
  glob: Record<string, any>,
  ciMap: Map<string, string>,
  key: string
): (() => Promise<any>) | undefined {
  // Exact match first (fast path)
  if (glob[key]) return glob[key] as () => Promise<any>
  // Case-insensitive fallback
  const realKey = ciMap.get(key.toLowerCase())
  return realKey ? (glob[realKey] as () => Promise<any>) : undefined
}

// ---- Helpers ----

type SourceType = 'local' | 'app' | 'vendor' | 'vendor-core'

function getFramework(): string {
  return localStorage.getItem('multi-tenant-saas-ui-framework')
    || (import.meta.env.VITE_UI_FRAMEWORK as string)
    || 'element-plus'
}

function extractModuleName(path: string, type: SourceType): string | null {
  const patterns: Record<SourceType, RegExp> = {
    'local': /(?:src|app)\/Modules\/([^/]+)\/resources\/console\//,
    'app': /app\/Modules\/([^/]+)\/resources\/console\//,
    'vendor': /vendor\/dsplat\/([^/]+)\//,
    'vendor-core': /vendor\/dsplat\/multi-tenant-saas\/src\/Modules\/([^/]+)\//,
  }
  const match = path.match(patterns[type])
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

function pageNameToPath(pageName: string): string {
  return knownPaths[pageName]
    || pageName.replace(/([a-z])([A-Z])/g, '$1-$2').replace(/([A-Z]+)([A-Z][a-z])/g, '$1-$2').toLowerCase()
}

function pageNameToLabel(pageName: string): string {
  return pageName
    .replace(/([a-z])([A-Z])/g, '$1 $2')
    .replace(/([A-Z]+)([A-Z][a-z])/g, '$1 $2')
    .replace(/^./, s => s.toUpperCase())
    .trim()
}

/**
 * Framework-aware view resolver for module routes.ts.
 * Tries the current UI framework first, falls back to element-plus.
 * Performs case-insensitive glob key matching to handle PascalCase directories
 * with lowercase module names in routes.ts (e.g. view('channel', ...) → /app/Modules/Channel/...).
 * Usage in routes.ts: view('customer', 'customers/CustomerList')
 */
export function view(moduleName: string, viewPath: string): () => Promise<any> {
  return () => {
    const fw = getFramework()
    for (const tryFw of [fw, 'element-plus']) {
      const key = `/src/Modules/${moduleName}/resources/console/ui/${tryFw}/views/${viewPath}.vue`
      const loader = resolveGlobLoader(projectModuleViewFiles, projectViewsCI, key)
      if (loader) return loader()

      const appKey = `/app/Modules/${moduleName}/resources/console/ui/${tryFw}/views/${viewPath}.vue`
      const appLoader = resolveGlobLoader(appModuleViewFiles, appViewsCI, appKey)
      if (appLoader) return appLoader()
    }
    // Last resort: runtime dynamic import (will 404 in production if path is wrong)
    return import(/* @vite-ignore */ `/src/Modules/${moduleName}/resources/console/ui/element-plus/views/${viewPath}.vue`)
  }
}

// SVG path data for common module icons (20x20 viewBox)
const ICON = {
  dashboard: 'M10.707 2.293a1 1 0 00-1.414 0l-7 7a1 1 0 001.414 1.414L4 10.414V17a1 1 0 001 1h2a1 1 0 001-1v-2a1 1 0 011-1h2a1 1 0 011 1v2a1 1 0 001 1h2a1 1 0 001-1v-6.586l.293.293a1 1 0 001.414-1.414l-7-7z',
  users: 'M9 6a3 3 0 11-6 0 3 3 0 016 0zM17 6a3 3 0 11-6 0 3 3 0 016 0zM12.93 17c.046-.327.07-.66.07-1a6.97 6.97 0 00-1.5-4.33A5 5 0 0119 16v1h-6.07zM6 11a5 5 0 015 5v1H1v-1a5 5 0 015-5z',
  tag: 'M7.5 1.5a1 1 0 011 0l5 3a1 1 0 01.5.87v5.26a1 1 0 01-.29.7L10 15.04a1 1 0 01-1.41 0l-3.7-3.7a1 1 0 01-.3-.71V5.37a1 1 0 01.5-.87l5-3z',
  chat: 'M2 5a2 2 0 012-2h12a2 2 0 012 2v6a2 2 0 01-2 2H7l-4 3V5z',
  share: 'M15 8a3 3 0 10-2.977-2.63l-4.94 2.47a3 3 0 100 4.319l4.94 2.47a3 3 0 10.895-1.789l-4.94-2.47a3.027 3.027 0 000-.74l4.94-2.47C13.456 7.68 14.19 8 15 8z',
  doc: 'M4 4a2 2 0 012-2h4.586A2 2 0 0112 2.586L15.414 6A2 2 0 0116 7.414V16a2 2 0 01-2 2H6a2 2 0 01-2-2V4z',
  gear: 'M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z',
  coin: 'M8.433 7.418c.155-.103.346-.196.567-.267v1.698a2.305 2.305 0 01-.567-.267C8.07 8.34 8 8.114 8 8c0-.114.07-.34.433-.582zM11 12.849v-1.698c.22.071.412.164.567.267.364.243.433.468.433.582 0 .114-.07.34-.433.582a2.305 2.305 0 01-.567.267zM10 18a8 8 0 100-16 8 8 0 000 16z',
  grid: 'M5 3a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2V5a2 2 0 00-2-2H5zM5 11a2 2 0 00-2 2v2a2 2 0 002 2h2a2 2 0 002-2v-2a2 2 0 00-2-2H5zM11 5a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V5zM11 13a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z',
}

function iconForLabel(label: string): string {
  const l = label.toLowerCase()
  if (l.includes('看板') || l.includes('dashboard') || l.includes('工作台')) return ICON.dashboard
  if (l.includes('客户') || l.includes('员工') || l.includes('成员') || l.includes('用户')) return ICON.users
  if (l.includes('标签') || l.includes('活码')) return ICON.tag
  if (l.includes('会话') || l.includes('消息') || l.includes('群发') || l.includes('欢迎') || l.includes('短信')) return ICON.chat
  if (l.includes('渠道') || l.includes('分销') || l.includes('裂变')) return ICON.share
  if (l.includes('素材') || l.includes('话术') || l.includes('知识') || l.includes('文档') || l.includes('海报') || l.includes('问卷') || l.includes('投票')) return ICON.doc
  if (l.includes('设置') || l.includes('配置') || l.includes('认证') || l.includes('注册') || l.includes('自动化') || l.includes('工作流')) return ICON.gear
  if (l.includes('积分') || l.includes('支付') || l.includes('信用') || l.includes('会员')) return ICON.coin
  return ICON.grid
}

// Module name → Chinese display label mapping
const MODULE_LABELS: Record<string, string> = {
  customer: '客户运营', ai: 'AI 能力', channel: '渠道与获客', community: '社群运营',
  content: '内容管理', marketing: '营销活动', membership: '会员运营', platform: '系统管理',
  analytics: '数据分析', staff: '团队管理', sms: '触达运营', product: '交易转化',
  knowledge: '知识库', lottery: '抽奖活动', distribution: '分销管理', coupon: '优惠券',
  voting: '投票活动',
  // Downstream project business modules (PascalCase — self-contained under app/Modules)
  Customer: '客户运营', Ai: 'AI 能力', AI: 'AI 能力', Channel: '渠道与获客', Community: '社群运营',
  Content: '内容管理', Marketing: '营销活动', Membership: '会员运营', Analytics: '数据分析',
  Staff: '团队管理', Product: '交易转化', Knowledge: '知识库', Lottery: '抽奖活动',
  Distribution: '分销管理', Coupon: '优惠券', Voting: '投票活动', Event: '活动管理',
  ChatArchive: '会话存档', Mcp: 'MCP 协议',
  // Vendor modules (PascalCase — framework standalone)
  User: '用户管理', Billing: '计费管理', Auth: '认证配置', ApiToken: 'API 管理',
  Payment: '支付配置', Platform: '平台管理', Sms: '短信配置', SSL: 'SSL 证书',
  Workflow: '工作流', Infrastructure: '基础设施', Ticket: '工单管理',
  // Vendor modules (kebab-case — consumed by downstream projects)
  'multi-tenant-saas-module-user': '用户管理',
  'multi-tenant-saas-module-billing': '计费管理',
  'multi-tenant-saas-module-auth': '认证配置',
  'multi-tenant-saas-module-api-token': 'API 管理',
  'multi-tenant-saas-module-payment': '支付配置',
  'multi-tenant-saas-module-platform': '平台管理',
  'multi-tenant-saas-module-sms': '短信配置',
  'multi-tenant-saas-module-ssl': 'SSL 证书',
  'multi-tenant-saas-module-workflow': '工作流',
  'multi-tenant-saas-module-infrastructure': '基础设施',
  'multi-tenant-saas-module-ticket': '工单管理',
}

// ---- Route loading ----

// Load custom routes from module routes.ts files
export async function loadModuleRoutes(): Promise<ModuleRoute[]> {
  const routes: ModuleRoute[] = []

  for (const [path, loader] of Object.entries({ ...moduleRoutesFiles, ...appModuleRoutesFiles })) {
    const moduleName = extractModuleName(path, 'local')
    if (!moduleName) continue

    try {
      const mod = await (loader as () => Promise<any>)()
      const moduleRoutes = mod.default || mod
      if (Array.isArray(moduleRoutes)) {
        // Inject module name into meta for sidebar grouping
        for (const route of moduleRoutes) {
          if (!route.meta) route.meta = {}
          if (!route.meta.module) route.meta.module = moduleName
        }
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

  type PageMap = Map<string, { path: string; type: SourceType }>
  const sources: Array<{ views: Record<string, unknown>; type: SourceType; fwMap: PageMap; bsMap: PageMap }> = [
    { views: localModuleViews, type: 'local', fwMap: new Map(), bsMap: new Map() },
    { views: appModuleViews, type: 'app', fwMap: new Map(), bsMap: new Map() },
    { views: frameworkModuleViews, type: 'vendor', fwMap: new Map(), bsMap: new Map() },
    { views: frameworkCoreModuleViews, type: 'vendor-core', fwMap: new Map(), bsMap: new Map() },
  ]

  for (const src of sources) {
    for (const [p] of Object.entries(src.views)) {
      const pn = extractPageName(p)
      const pf = extractFramework(p)
      if (!pn || pn === 'Login') continue
      if (pf === fw && !src.fwMap.has(pn)) src.fwMap.set(pn, { path: p, type: src.type })
      if (pf === 'bootstrap' && !src.bsMap.has(pn)) src.bsMap.set(pn, { path: p, type: src.type })
    }
  }

  const hasCustomRoutes = new Set<string>()
  for (const [path] of Object.entries({ ...moduleRoutesFiles, ...appModuleRoutesFiles })) {
    const name = extractModuleName(path, 'local')
    if (name) hasCustomRoutes.add(name)
  }

  const allSources = sources.flatMap(s => [s.fwMap, s.bsMap])
  const seen = new Set<string>()

  // Chinese title mapping for framework auto-discovered pages
  const pageTitleMap: Record<string, string> = {
    Members: '成员管理',
    Workflows: '工作流',
    ApiTokens: 'API Token',
    TenantSettings: '租户设置',
    Webhooks: 'Webhooks',
    SslCertificates: 'SSL 证书',
    OAuthSettings: '第三方登录',
    PaymentSettings: '支付配置',
    PointsManagement: '积分管理',
  }

  for (const source of allSources) {
    for (const [pageName, { path, type }] of source) {
      if (seen.has(pageName)) continue
      const moduleName = extractModuleName(path, type)
      if (!moduleName || hasCustomRoutes.has(moduleName)) continue
      seen.add(pageName)

      const views = type === 'local' ? localModuleViews
        : type === 'app' ? appModuleViews
        : type === 'vendor' ? frameworkModuleViews
        : frameworkCoreModuleViews
      const loader = views[path]
      if (!loader) continue

      routes.push({
        path: pageNameToPath(pageName),
        name: `${moduleName}${pageName}`,
        component: () => (loader as () => Promise<any>)(),
        meta: { title: pageTitleMap[pageName] || pageName, requiresAuth: true, module: moduleName },
      })
    }
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

  const allPages = new Map<string, { moduleName: string }>()

  const sources: Array<{ views: Record<string, () => Promise<any>>; type: SourceType }> = [
    { views: localModuleViews, type: 'local' },
    { views: appModuleViews, type: 'app' },
    { views: frameworkModuleViews, type: 'vendor' },
    { views: frameworkCoreModuleViews, type: 'vendor-core' },
  ]

  for (const fwName of [fw, 'bootstrap']) {
    for (const { views, type } of sources) {
      for (const [path] of Object.entries(views)) {
        const pageName = extractPageName(path)
        const pageFw = extractFramework(path)
        if (!pageName || pageName === 'Login' || pageFw !== fwName) continue
        const moduleName = extractModuleName(path, type)
        if (!moduleName) continue
        if (!allPages.has(pageName)) allPages.set(pageName, { moduleName })
      }
    }
  }

  for (const [pageName, { moduleName }] of allPages) {
    entries.push({
      moduleName,
      pageName,
      path: pageNameToPath(pageName),
      label: pageNameToLabel(pageName),
    })
  }

  return entries
}

/**
 * Build console navigation sections from discovered module routes.
 * Layouts call this to render sidebar menus — no hardcoded menu items needed.
 *
 * Sources (merged with dedup by route path):
 *   1. Module routes.ts files → custom routes with meta.title (Chinese labels)
 *   2. Glob auto-discovered views → framework-aware fallback pages
 */
export async function getConsoleNavSections(): Promise<NavSection[]> {
  const allRoutes = await getAllModuleRoutes()

  // Collect routes that should appear in the sidebar
  const items: Array<{ path: string; label: string; module: string }> = []
  const seenPaths = new Set<string>()

  for (const route of allRoutes) {
    const path = route.path as string
    if (!path || seenPaths.has(path)) continue

    const title = (route.meta?.title as string) || ''
    const name = route.name as string

    // Skip Login page
    if (name === 'Login') continue

    // Skip sub-pages: paths with params or nested segments (except known framework pages)
    const hasParam = path.includes(':')
    const isDeep = path.split('/').filter(Boolean).length > 2
    const isKnownFramework = path.startsWith('membership/') || path.startsWith('analytics/')
    if (hasParam || (isDeep && !isKnownFramework)) continue

    seenPaths.add(path)
    items.push({
      path,
      label: title || pageNameToLabel(name || path),
      module: (route.meta?.module as string) || '',
    })
  }

  // Group by module name
  const groups = new Map<string, Array<{ path: string; label: string }>>()
  for (const item of items) {
    const mod = item.module || 'other'
    if (!groups.has(mod)) groups.set(mod, [])
    groups.get(mod)!.push({ path: item.path, label: item.label })
  }

  // Build sections sorted by module name
  const sections: NavSection[] = []
  for (const [moduleName, groupItems] of [...groups.entries()].sort((a, b) => a[0].localeCompare(b[0]))) {
    const label = MODULE_LABELS[moduleName]
      || moduleName.replace(/^multi-tenant-saas-module-/, '').replace(/-/g, ' ').replace(/^./, s => s.toUpperCase())

    sections.push({
      label,
      items: groupItems.map(item => ({
        path: item.path,
        label: item.label,
        module: moduleName,
        icon: iconForLabel(item.label),
      })),
    })
  }

  return sections
}
