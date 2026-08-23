# 域名与路径设计总览（脑图）

> **文档性质**: 一图总览（Mermaid mindmap），权威细节见 `docs/tenant.md` §2.0-§2.5.2、§8.1 与 `docs/zh/deployment/nginx-guide.md`
> **最后更新**: 2026-08-23（对齐 app 域 SEO 路径形态双形态设计）

```mermaid
mindmap
  root((域名与路径设计))
    平台域名
      独立 vhost 优先于基桩
      www.neihang.com
        平台品牌站 default 面
        robots 与落地页
      admin.neihang.com
        平台后台 admin 面
        /admin SPA
        /api/v1/admin
      console.neihang.com
        租户后台 console 面
        /console SPA
        /api/v1/console
      app.neihang.com
        SEO 内容主域 app 面
        /slug 路径形态 纯 SEO 设计
        拒绝 console 一律 403
    租户入口
      统一基桩 default_server 承接
      自定义域名 approved
        club.mtedu.com 示例
        同域 /console 后台
      二级域名
        slug 子域 slug_status=active
        tenant_id 子域 16 位雪花直查
        t-xxxxxx 自动码 免费兜底
      识别链 IdentifyTenant 八级
        1 URL 参数 不可信
        2 X-Tenant-ID 不可信
        3 自定义域名 可信
        4 Cookie 不可信
        5 Session
        6 认证用户关联
        7 子域与 app 路径前缀解析
          16 位纯数字按 tenant_id 直查
          否则按 slug 查须 active
          app 域取路径第一段
        8 未识别返 403
    canonical 收敛
      规范入口优先级
        自定义域名 approved
        slug 二级域名 active
        tenant_id 二级域名 兜底
      非规范入口 301 收敛
      app 域显式排除
        不参与收敛
        内容页保持直出
    SEO 直出双形态
      子域根路径形态
        course-123.html
        product-123.html
        event-123.html
        sitemap.xml
      app 域路径形态
        slug/course-123.html
        slug/product-123.html
        slug/event-123.html
        slug/sitemap.xml
      列表页保留原路径
        h5/pages/course/index
        h5/pages/shop/index
        h5/pages/campaign/index
    console 拒绝面
      nginx 层 host_is_app map 403
      中间件层 EnforceDomainSegregation
        /console 前缀
        /slug/console 形态
        /slug/api/v1/console 形态
    SEO 收录分层
      可收录 seo_allowed 1
        平台域名
        自定义域名
        app 域路径形态
      禁收录 seo_allowed 0
        免费子域与付费二级域
        noindex 下发
        AI 爬虫 403
```

## 阅读顺序

1. **平台域名**：四个独立 vhost，`app.neihang.com` 是唯一带路径形态的（`/{slug}/...`），且是纯 SEO 面——console 双层拒绝（nginx `host_is_app` + PHP `isTenantSurface`）。
2. **租户入口**：统一基桩承接，识别链第 7 级同时覆盖「子域前缀」与「app 域路径第一段」两种形态（16 位雪花直查 / slug 须 `slug_status=active`）。
3. **SEO 双形态**：`{slug}.neihang.com/course-123.html` ⇔ `app.neihang.com/{slug}/course-123.html` 等价直出，但 app 域被 canonical 显式排除（内容页不 301，防止破坏爬虫积累）。
4. **两条横切规则**：canonical 收敛只作用于子域三形态；sitemap `contentBase()` 对 rejected slug 回退 tenant_id 防死链。

## 设计 → 实现映射

| 脑图分支 | 实现载体 |
|---|---|
| 平台域名 | `config/domain.php` `platform_domains` + `tenancy.platform_domains`；`IdentifyDomain` 五域判定 |
| 租户识别链 | `IdentifyTenant`（第 7 级 `resolveFromAppPath` / `resolveFromSlug`） |
| canonical 收敛 | `EnforceCanonicalEntry`（app 域 host 显式排除，`hash_equals` 比对） |
| console 拒绝面 | nginx `host_is_app` map + `EnforceDomainSegregation::isTenantSurface`（slug 形态正则） |
| SEO 双形态路由 | scrm `app/Modules/Seo/Routes/web.php`（`/{type}-{id}.html` + `/{slug}/{type}-{id}.html`） |
| sitemap 防死链 | scrm `SeoRenderService::contentBase()`（域感知 + slug 回退） |
| SEO 收录分层 | `NginxConfigService` 生成 `seo.map` / `bot.map`（`$seo_allowed` / `$block_ai_bot`） |
| 统一基桩 | `tenant-server.conf.stub`（map 驱动，`$domain_allowed` 白名单 444 断连） |
