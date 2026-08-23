# Nginx 与域名配置全景审计（2026-08-23）

> 目的：归拢 nginx/域名配置的代码、文档与现状事实，识别需要统一调整的地方。
> 范围：框架 multi_tenant_saas + 项目 scrm-platform + 前端 scrm-platform-front。

---

## 一、架构全景

### 1.1 双体系叠加

```
请求 → SLB(80 层，SSL 卸载) ──► nginx
        ├─ 平台域名（独立 vhost）  neihang.conf：www / admin / console（缺 app）
        │      └─ DOMAIN-PATH SEGREGATION（域名-路径双重绑定）
        └─ 租户域名（统一基桩）    dsplat-tenants.conf → stubs/tenant-server.conf
               └─ map 变量驱动：$domain_allowed / $seo_allowed / $block_ai_bot / $ssl_*
                    └─ PHP-FPM（直连，废除 9100 代理层）
                          └─ 中间件链：
                             IdentifyDomain(五域) → EnforceDomainSegregation
                             → IdentifyTenant(八级) → EnforceCanonicalEntry(301 收敛)
```

- **统一基桩模型**：所有租户域名（自定义域名 + `{slug}.{base}` + `{tenant_id}.{base}`）共用单一 default_server，行为全由 map 变量驱动，不为每域名写 vhost。
- **平台域名独立 vhost**：优先级高于 default_server，不受基桩影响。
- **双监听形态**：`nginx_listen_mode=https`（443 直连 + ssl.map/SNI）vs `http`（80 层，SLB 卸载）——**neihang 生产是后者**。
- **X-Original-Host 防伪**：fastcgi_param 以真实 `$host` 覆盖客户端伪造头，隔离门依赖此头。

### 1.2 平台五域模型（IdentifyDomain）

| 域 | 配置键 | neihang 生产值 |
|---|---|---|
| admin | `domain.platform_domains.admin` | admin.neihang.com |
| console | `domain.platform_domains.console` | console.neihang.com |
| default(main) | `domain.platform_domains.main` | www.neihang.com |
| api | `domain.platform_domains.api` | （未配置） |
| app（兜底） | 判定链最后 | app.neihang.com |

### 1.3 目标域名规范（2026-08-23 用户定义）

| 域名 | 职责 |
|---|---|
| www.neihang.com / neihang.com（裸域） | 平台品牌独立站 |
| admin.neihang.com | 平台管理 |
| console.neihang.com | 租户登录后台 |
| app.neihang.com | 用户终端 |

**租户入口等效规则**：

```
app.neihang.com/{slug}  等效  {slug}.neihang.com/
slug = {tenant_id | t-xxxxx | 自定义二级域名}
{slug}.neihang.com 与纯自定义域名（如 club.mtedu.com）等效，优先级倒过来：
    纯自定义域名 > {slug}.neihang.com > app.neihang.com/{slug}
以上任一租户入口后接 /console/ = console.neihang.com/console（租户后台）
```

**现状差距速览**（详见第五节）：
- ❌ `app.neihang.com/{slug}` 路径形态：IdentifyTenant L27-29 / EnforceCanonicalEntry L23-24 明示「架构约束：不支持 app 域路径前缀形态，租户共享入口一律为子域名」——**与目标规范直接冲突，需改造**
- ❌ app 域未入平台域白名单（neihang.conf / platformDomains() / tenancy.platform_domains）
- ❌ 裸域 neihang.com 无 vhost、无白名单 → 现网 444
- ✅ `{slug}.neihang.com` 子域形态（含 t-xxx/tenant_id）已支持
- ✅ 优先级收敛（自定义域名 > slug 二级域名 > tenant_id 二级域名）EnforceCanonicalEntry 已实现
- ✅ 租户入口 + `/console/`：隔离门 L26 放行租户接入域访问 console（但 app 路径形态缺 → app.neihang.com/{slug}/console/ 不可达）

---

## 二、代码清单

### 2.1 框架层（multi_tenant_saas）— 体系主体

| 文件 | 职责 | 行数 |
|---|---|---|
| `src/Modules/Domain/Services/NginxConfigService.php` | **核心生成器**：一键生成全部产物（白名单 map / SNI map / SEO map / bot map / 基桩 / 软链接 / 顶层 include） | 725 |
| `src/Modules/Domain/deploy/nginx/tenant-server.conf.stub` | 基桩模板：`{{SEO_DIRECT_OUT_LOCATIONS}}` 占位符、SEO/AI 爬虫分流、/h5/ /console/ SPA、`location = /` → 302 /h5/ | 119 |
| `src/Modules/Domain/Config/domain.php` | 域名配置：platform_domains / wildcard_base / nginx_listen_mode / seo_direct_out_paths（框架默认空）/ reserved_slugs | 204 |
| `src/Modules/Domain/Commands/GenerateNginxDeploy.php` | `php artisan domains:generate-nginx {--path=} {--reload}` | 91 |
| `src/Modules/Infrastructure/Http/Middleware/IdentifyTenant.php` | 八级识别链：URL 参数 → Header → 自定义域名 → Cookie → Session → 认证用户 → 通配子域名 → 不兜底 403 | 322 |
| `src/Modules/Infrastructure/Http/Middleware/IdentifyDomain.php` | 五域判定：admin → console → default → api → 路径声明 → app 兜底 | 117 |
| `config/tenancy.php` | `platform_domains`：localhost/127.0.0.1/main/admin/console/api（**缺 app**） | 314 |
| `src/Modules/AiStreaming/deploy/nginx.conf.stub` | SSE location 片段（ai-streaming:nginx 渲染，代理 Node 9200） | 31 |

关键实现事实：
- `NginxConfigService::platformDomains()` **只取 admin + console**（L153-159），不含 main/api/app。
- `generateSeoMap()`：平台域名 + 自定义域名 = 1（可收录）；二级域名 default 0。
- `generateBotMap()`：22 种 AI 爬虫 UA；`"$is_ai_bot:$seo_allowed"` → `"1:0"` 时 `return 403`（仅对非收录域名拦 AI 爬虫）。
- 二级域名白名单**精确放行**（`{tenant_id}.{base}` 全体 active + `{slug}.{base}` 仅 slug_status=active），不做通配。

### 2.2 项目层（scrm-platform）— 平台 vhost + SEO 直出

| 文件 | 职责 |
|---|---|
| `deploy/nginx/neihang.conf` | 平台 vhost：`server_name www/admin/console.neihang.com`（**缺 app**）；DOMAIN-PATH SEGREGATION；非 index.php 的 .php 一律 404；X-Original-Host 防伪 |
| `deploy/nginx/00-platform-domain-maps.conf` | `$host_is_console` / `$host_is_admin` map（neihang.conf 依赖） |
| `config/domain.php` | `seo_direct_out_paths`：h5/pages/course/detail、course/index、shop/detail、shop/index、event/detail、campaign/index、sitemap.xml |
| `app/Modules/Seo/`（ServiceProvider + Routes/web.php） | SEO 直出模块：7 条直出路由与 seo_direct_out_paths 一一对应 |
| `deploy/config.env` | 生产：SERVER_HOST=192.168.100.11，WEB_ROOT=/data/app/neihang.com |
| `.env` | 域名块 L8-13 + **L483-484 重复定义（example.com 占位符残留）** |

### 2.3 前端（scrm-platform-front）

| 文件 | 职责 |
|---|---|
| `apps/h5/src/pages.json` | H5 真实路由（index/campaign/course/shop/event/checkin/member/auth 等）——nginx `/h5/` SPA 回退的对照表 |

---

## 三、文档清单

| 文档 | 状态 | 说明 |
|---|---|---|
| `docs/zh/deployment/nginx-guide.md`（框架，266 行） | ✅ 现网形态 | SLB 拓扑锁定、统一基桩模型、产物目录、map 变量表、双形态说明、SEO/GEO 分层、验证矩阵。**默认写 443 直连形态，与生产 80 层形态表述有出入** |
| `docs/tenant.md`（框架，759 行） | ✅ 主体准确 | §2.5.1 域名类型分类模型、§2.6 Slug 治理、§2.7 配置项、§2.8 Nginx 生成、§八 SEO/GEO 分层 |
| `docs/deploy/nginx.conf.template`（框架） | ❌ **旧版** | 每租户独立 vhost（`~^(?<tenant>[^.]+)\.example\.com$` 正则通配），与统一基桩模型**冲突**，应废弃 |
| `docs/zh/deployment/deployment-guide.md`（框架） | ⚠️ 通用 | Docker 部署指南，与域名体系无关 |
| `deploy_neihang.md`（项目 §3.4） | ❌ **旧版** | 无 SEGREGATION/基桩的单 vhost；但部署事实表（L385-391）与现网一致：/etc/nginx/conf.d/neihang.conf |
| `docs/deploy/nginx-vhost.conf`（项目） | ❌ **旧版** | social.dsplat.com 模板，无隔离、无基桩 |
| `docs/deploy/192.168.100.11-deployment-report.md`（项目） | ❌ **历史** | sites-available/scrm-platform 旧路径，与现网 conf.d/neihang.conf 不符 |

---

## 四、配置事实（.env 现状）

### 4.1 scrm-platform/.env

```
L8-13   PLATFORM_MAIN_DOMAIN=www.neihang.com
        ADMIN_DOMAIN=admin.neihang.com
        PLATFORM_APP_DOMAIN=app.neihang.com
        PLATFORM_CONSOLE_DOMAIN=console.neihang.com
        PLATFORM_ADMIN_DOMAIN=admin.neihang.com
L462    SANCTUM_STATEFUL_DOMAINS=localhost,localhost:5173,localhost:5174,127.0.0.1,
        127.0.0.1:8000,www.neihang.com,console.neihang.com,admin.neihang.com,
        app.neihang.com,192.168.100.11
L483-484 ⚠️ 重复定义：PLATFORM_ADMIN_DOMAIN=admin.example.com / PLATFORM_APP_DOMAIN=app.example.com
         （dotenv 后者覆盖前者 → 实际生效值疑似 admin.example.com / app.example.com）
L487-494 DOMAIN_NGINX_MAP_FILE=/etc/nginx/conf.d/allowed-domains.map（旧路径）
```

**缺失**：`NGINX_LISTEN_MODE`、`NGINX_FASTCGI_PASS`、`NGINX_LOG_DIR`、`PLATFORM_WILDCARD_BASE`、`PLATFORM_API_DOMAIN`。

### 4.2 multi_tenant_saas/.env

```
L7      ADMIN_DOMAIN=admin.lyt.com（本地开发）
L58     ⚠️ 重复定义：ADMIN_DOMAIN=admin.dsplat.com（后者覆盖 → admin.dsplat.com）
L56-58  无 PLATFORM_MAIN_DOMAIN / PLATFORM_CONSOLE_DOMAIN / PLATFORM_API_DOMAIN
```

### 4.3 生产（deploy/config.env + deploy_neihang.md 事实表）

- 服务器：192.168.100.11，WEB_ROOT=/data/app/neihang.com
- Nginx 配置：/etc/nginx/conf.d/neihang.conf（手工放置，非 deploy.py 管理）
- 基桩产物：服务器端 `domains:generate-nginx` 生成（本地 deploy/ 无产物）
- 形态：**80 层（SLB 卸载 SSL）**，即 `nginx_listen_mode=http`

---

## 五、不一致点清单（需统一）

### 5.1 目标规范差距（用户 2026-08-23 定义）

| # | 差距 | 严重度 | 现状证据 |
|---|---|---|---|
| A1 | **app.neihang.com/{slug} 路径形态不支持** | 🔴 高 | IdentifyTenant L27-29「架构约束：不支持 app 域路径前缀（/{slug}/、/{tenant_id}/）形态」；EnforceCanonicalEntry L23-24 同 |
| A2 | **app.neihang.com 不在任何平台域清单** | 🔴 高 | neihang.conf server_name 缺；`platformDomains()` 只取 admin+console；tenancy.platform_domains 缺 PLATFORM_APP_DOMAIN；domain.platform_domains 无 app 键 → 落基桩 444 |
| A3 | **裸域 neihang.com 不可达** | 🟠 中 | neihang.conf 无裸域；IdentifyDomain main 精确匹配 www；platform_domains 无裸域 → 落基桩 444 |
| A4 | **app.neihang.com/{slug}/console/ 不可达** | 🟠 中 | 路径形态缺失 → IdentifyTenant 解析不到租户 → EnsureTenantContext 403 |
| A5 | **RejectPlatformDomain 与文档不一致** | 🟡 低 | 代码只拒 admin+main（L36-39），deploy_neihang.md L297 声称拒 www/admin/app 三域；app 域若进平台域清单需确认拒绝策略（app 裸域应拒、app/{slug} 应放行） |
| A6 | **IdentifyDomain 无 app 精确匹配** | 🟡 低 | app 目前靠「其余全部归为 app」兜底（L97-98），与租户域同类型；app 裸域与租户入口在域类型上无法区分（后续路径形态改造时需细分） |

### 5.2 既有不一致点（扫描发现）

| # | 不一致点 | 严重度 | 说明 |
|---|---|---|---|
| 1 | **neihang.conf 缺 app.neihang.com** | 🔴 高 | server_name 只有 www/admin/console；app 域名落基桩，但 tenant-auth.map 平台域名区块也不含 app → 可能 444 断连 |
| 2 | **scrm-platform/.env L483-484 占位符残留** | 🔴 高 | 重复定义 example.com 值覆盖 L9-13 正确值 → PLATFORM_ADMIN_DOMAIN/PLATFORM_APP_DOMAIN 实际生效疑似错误，可能是 admin.neihang.com 403 的根因之一 |
| 3 | **NginxConfigService::platformDomains() 只取 admin+console** | 🟠 中 | tenant-auth.map / seo.map 平台域名区块缺 main/api/app |
| 4 | **tenancy.platform_domains 缺 PLATFORM_APP_DOMAIN** | 🟠 中 | IdentifyTenant::isPlatformDomain() 不认 app.neihang.com |
| 5 | **sitemap.xml 404**（冒烟实测） | 🟠 中 | seo_direct_out_paths 已配置但生产基桩疑似未重新生成，或 seo.map 平台域名区块不含 main |
| 6 | **本地 .env 缺 NGINX_* 全套** | 🟡 低 | LISTEN_MODE/FASTCGI_PASS/LOG_DIR/DEPLOY_PATH 均无 → 本地生成的基桩形态与生产不一致 |
| 7 | **本地无 PLATFORM_WILDCARD_BASE** | 🟡 低 | 默认 dsplat.com；生产若为 neihang.com 则本地生成的基桩与生产不同（需上生产确认） |
| 8 | **deploy.py 不管理 nginx 产物** | 🟡 低 | DEPLOY_DIRS=app/database/config/routes；deploy/nginx 不同步；neihang.conf 靠手工放置，基桩靠服务器端命令生成（新增租户域名后漏跑 → 444 断连） |
| 9 | **旧文档 3 份** | 🟡 低 | nginx.conf.template（vhost 正则通配，与基桩模型冲突）、nginx-vhost.conf（social.dsplat.com）、192.168.100.11-deployment-report.md（sites-available 路径） |
| 10 | **框架 .env ADMIN_DOMAIN 重复**（lyt.com/dsplat.com） | 🟢 提示 | 本地开发，影响小但应清理 |
| 11 | **nginx-guide.md 默认形态表述** | 🟢 提示 | 文档默认 443 直连，生产 80 层；需补一句"neihang 生产锁定 http 形态" |

---

## 六、待确认项（需上生产核实）

1. 生产 .env 是否有 `NGINX_LISTEN_MODE=http` / `NGINX_FASTCGI_PASS` / `PLATFORM_WILDCARD_BASE` / `PLATFORM_API_DOMAIN`？
2. 生产 /etc/nginx/conf.d/ 实际文件清单（neihang.conf + 00-platform-domain-maps.conf + dsplat-tenants.conf + maps/？）
3. 生产基桩生成时间（sitemap.xml 404 是否因未重新生成）
4. club.mtedu.com（t-93kjb7 自定义域名）当前由基桩承接的确认
5. app.neihang.com 现网实际响应（基桩承接 or 444/403）

---

## 七、建议统一动作（待用户确认后执行）

> **2026-08-23 已全部实施（识别链路 + 内容面 + 生产）**——config 补 app 域、IdentifyDomain 精确匹配、
> IdentifyTenant 新增第 7 级 app 路径前缀识别（resolveFromAppPath + resolveFromSlug 抽取）、
> EnforceCanonicalEntry 放开约束（app 形态不收敛）、NginxConfigService::platformDomains() 补全、
> 测试全过；scrm .env 修占位符 + 补 WILDCARD_BASE/NGINX_*；neihang.conf 补 app/裸域；
> 直出路由 `{type}-{id}.html` 双形态落地（scrm d2b1bb2）；app 域拒 console（框架 6399e722）；
> sitemap 域感知 + slug 回退防死链（scrm 84473ce）；生产基桩重生成 + neihang.conf/maps 手工同步
> + .env 补 PLATFORM_APP_DOMAIN + 全验证矩阵通过。

### 7.1 目标规范落地（对应 5.1 差距）

1. ✅ **新增 app 域路径形态**（2026-08-23 全部完成）：IdentifyTenant 第 7 级
   `resolveFromAppPath()`（app 域 + 路径第一段 slug，16 位 tenant_id 直查 / slug 查询，
   须 slug_status=active），`resolveFromSlug()` 与子域解析共用；EnforceCanonicalEntry 显式
   排除 app 域 host（内容页保持直出，框架 6399e722）；scrm Seo 模块直出路由
   `/{type}-{id}.html` + `/{slug}/{type}-{id}.html` 双形态落地（scrm d2b1bb2）；
   sitemap 域感知 contentBase 对 rejected slug 回退 tenant_id 防死链（scrm 84473ce）。
2. ✅ **app 域入平台清单**：config/domain.php platform_domains 补 `app` 键；tenancy.platform_domains
   补 PLATFORM_APP_DOMAIN；NginxConfigService::platformDomains() 补 main/api/app；
   neihang.conf server_name 补 app.neihang.com + 裸域 neihang.com。
3. ✅ **app 域拒 console（2026-08-23 实施）**：app 裸域与 `app/{slug}/` 路径形态一律拒绝
   console（nginx `host_is_app` map 403 + EnforceDomainSegregation `isTenantSurface` 补
   slug 形态正则，框架 6399e722），`app/{slug}/` 纯 SEO 内容放行。
4. ✅ **修 .env**：scrm/.env 删除 L483-484 占位符重复定义（2026-08-23），补 PLATFORM_WILDCARD_BASE
   与 NGINX_*；框架/.env 删除 ADMIN_DOMAIN 重复（lyt.com）。
5. ✅ **生产重生成基桩（2026-08-23 完成）**：服务器 `domains:generate-nginx --reload` +
   neihang.conf/maps 手工同步 + `.env` 补 PLATFORM_APP_DOMAIN + config:cache，全验证矩阵通过。
6. 🟡 **清理旧文档**：废弃 nginx.conf.template / nginx-vhost.conf，更新 deploy_neihang.md §3.4、
   nginx-guide.md 形态表述、RejectPlatformDomain 注释、tenant.md §2.0 路径形态约束表述
   （已重新启用 app 域路径前缀）。

### 7.2 既有问题修复（对应 5.2）

- 修 .env 占位符（L483-484）；platformDomains() 补 main/api；统一基桩形态配置；deploy.py 纳入 nginx 产物管理或明确服务器端生成流程。
