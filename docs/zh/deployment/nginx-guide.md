# Nginx 配置指南

**最后更新**: 2026-08-08

本指南描述租户域名接入层的 nginx 产物与部署方式。所有产物由框架 `NginxConfigService` 自动生成，**无需手写租户域名配置**。模块设计详见 `docs/tenant.md` 第二节与第八节。

---

## 〇、部署拓扑：SLB 层架构（锁定）

```
用户 → SLB 层（如 mt_aiserv） → 80 → 应用服务器 nginx → php-fpm
      ├ 443 终结 / SSL 卸载
      ├ 证书落盘（通配证书 + 自定义域名证书）
      └ 请求分发（透传真实 Host）
```

这是架构事实：即使 SLB 与应用部署在同一台设备上，也保留这一层（性能几乎无损，换来拓扑灵活性）。由此推出两条工程约束：

1. **应用服务器仅监听 80**：不在回源侧引入 443/证书逻辑；基桩的 443 形态（ssl.map/SNI）在 SLB 卸载模式下不启用，证书与 ssl.map 同步到 SLB 侧维护（或对 SSL 直透不卸载）。
2. **Host 防伪**：应用侧 `IdentifyDomain` 优先读 `X-Original-Host`。该头必须由 SLB 注入，回源层 nginx 需先无条件覆盖再赋值，防止客户端伪造：

```nginx
server {
    listen 80;
    # 先覆盖客户端传入的伪造头，再以真实 Host 赋值
    set $real_host $host;
    proxy_set_header X-Original-Host $host;   # 若经 SLB 转发，则用 $http_x_original_host 的受信值
    ...
}
```

> 当 SLB 与应用同机时，80 层基桩直接承接 SLB 回源；分离部署时，回源链路需额外 `set_real_ip_from` 信任 SLB 网段。

---

## 一、架构总览：统一基桩模型

所有租户域名——**自定义域名**（如 `scrm.lanyantu.com`）与**二级域名**（如 `lanyantu.dsplat.com`、自动码 `t-xxxxxx.dsplat.com`、同质的 `{tenant_id}.dsplat.com`）——共用**同一个 default_server 基桩**承接，不为每个域名手写 vhost。

- 系统 nginx 仅在 `http{}` 层 include 一次顶层文件 `dsplat-tenants.conf`。
- 基桩行为全部由 `map` 变量驱动（白名单 / SNI 证书 / SEO/GEO / AI 爬虫）。
- 平台域名（admin/app/console）保留**独立精确 vhost**，nginx 优先级「精确 > 通配 > default_server」，故平台域名不受基桩影响。
- `tenants-enabled/` 下每域名一个软链接指向基桩，仅作**巡检清单**，不驱动路由（路由由 Host → map 决定）。

### 产物目录结构

```
{nginx_deploy_path}/                 # 默认 base_path('deploy/nginx')，可由 domain.nginx_deploy_path 配置
├── dsplat-tenants.conf              # 顶层 include（系统 nginx 仅 include 本文件一次）
├── maps/
│   ├── tenant-auth.map              # map $host $domain_allowed（白名单，default 0）
│   ├── ssl.map                      # map $ssl_server_name $ssl_cert_file/$ssl_key_file（SNI 动态证书）
│   ├── seo.map                      # map $host $seo_allowed + map $seo_allowed $x_robots_tag
│   └── bot.map                      # map $http_user_agent $is_ai_bot + map "$is_ai_bot:$seo_allowed" $block_ai_bot
├── stubs/
│   └── tenant-server.conf           # 基桩：唯一 default_server，catch-all 所有租户域名（443 直连形态；SLB 卸载时为 80 层形态）
├── certs/                           # 受管证书目录（domain.ssl_certs_path）
│   ├── default.crt / default.key    # 通配证书（*. wildcard_base）
│   └── {domain}.crt / {domain}.key  # 自定义域名证书（可为软链接）
└── tenants-enabled/                 # 每域名一个软链接 → 基桩（巡检清单）
```

---

## 二、生成产物

```bash
# 一键生成全部 nginx 产物（白名单/SNI/SEO/GEO/AI爬虫 map + 基桩 + 软链接 + 顶层 include）
php artisan domains:generate-nginx
```

命令从数据库（`tenants` 表的 `domain`/`slug`/`slug_status`/`ssl_uploaded_at`）与 `config/domain.php` 读取数据，幂等生成。新增/变更租户域名后重新执行即可。

---

## 三、系统 nginx 接入

系统 `nginx.conf` 的 `http{}` 块内仅需一行：

```nginx
http {
    # ... 其他全局配置 ...

    include {nginx_deploy_path}/dsplat-tenants.conf;   # 租户域名接入层（ maps + 基桩 ）
    include vhost/*.conf;                              # 平台域名独立 vhost（admin/app/console）
}
```

`dsplat-tenants.conf` 内部展开为：

```nginx
include {nginx_deploy_path}/maps/*.map;        # 所有 map 必须在 http 层定义
include {nginx_deploy_path}/stubs/tenant-server.conf;
```

> 443 直连形态下，HTTP(80)→HTTPS 重定向由系统 nginx.conf 的 80 端口 default_server 统一处理；SLB 卸载形态下应用服务器无 443，重定向与证书均由 SLB 层负责（见第〇节）。

---

## 四、map 变量说明

所有 map 均在 `http{}` 层定义（由 `maps/*.map` 提供），基桩引用派生变量。

| 变量 | 定义文件 | 键源 | 作用 |
|---|---|---|---|
| `$domain_allowed` | tenant-auth.map | `$host` | 域名白名单，`default 0`。`0` → 基桩 `return 444` 断连（恶意/未配置域名，零带宽零泄露）|
| `$seo_allowed` | seo.map | `$host` | 平台域名 + 自定义域名 = `1`（可收录）；二级域名（含 t-xxxxxx 与 `{tenant_id}` 兜底形态）走 default `0`（禁收录）|
| `$x_robots_tag` | seo.map | `$seo_allowed` | `0` → `noindex, nofollow`；`1` → 空（nginx 空值不下发该头）|
| `$is_ai_bot` | bot.map | `$http_user_agent` | 识别 22 种主流 AI 爬虫（GPTBot/ClaudeBot/Bytespider/CCBot/PerplexityBot/Google-Extended 等，`~*` 不区分大小写）|
| `$block_ai_bot` | bot.map | `"$is_ai_bot:$seo_allowed"` | `"1:0"` → `1`：仅「是 AI 爬虫 且 非收录域名」拦截；自定义域名（seo_allowed=1）放行（GEO 开放）|
| `$ssl_cert_file` / `$ssl_key_file` | ssl.map | `$ssl_server_name` | SNI 动态选证书，`default` 指向通配证书 |

### 白名单精确性

二级域名**精确放行**，不做通配：全体 active 租户的 `{tenant_id}.{wildcard_base}`（兜底形态）+ `slug_status=active` 的 `{slug}.{wildcard_base}`。恶意子域名（如 `evil-random.dsplat.com`）不在列表 → `default 0` → `return 444`。

---

## 五、基桩（stubs/tenant-server.conf）

基桩有两种监听形态，行为（白名单/SEO/GEO/AI 爬虫）完全一致，差别仅在 SSL 归属：

- **443 直连形态**（无 SLB 或 SLB 直透）：基桩自持证书，启用 ssl.map/SNI；
- **80 层形态**（SLB 卸载，当前生产架构）：基桩 `listen 80 default_server`，删除 `ssl_certificate*` 两行，其余不变。

443 直连形态示例：

```nginx
server {
    listen 443 ssl http2 default_server;
    server_name _;

    # SNI 动态证书
    ssl_certificate     $ssl_cert_file;
    ssl_certificate_key $ssl_key_file;

    # ① 域名合法性拦截：未在白名单直接断连
    if ($domain_allowed = 0) { return 444; }

    # ② GEO 防护：仅非收录域名拦截 AI 爬虫（自定义域名放行）
    if ($block_ai_bot) { return 403; }

    # ③ SEO 隔离：非收录域名下发 noindex（收录域名置空不下发）
    add_header X-Robots-Tag $x_robots_tag always;

    root {public_path};

    location /api/ { try_files $uri /index.php?$query_string; }   # 直连 fastcgi 9001

    # robots.txt 按 $seo_allowed 动态下发 Allow/Disallow
    location = /robots.txt {
        default_type "text/plain";
        add_header X-Robots-Tag $x_robots_tag always;
        if ($seo_allowed = 1) { return 200 "User-agent: *\nAllow: /\n"; }
        return 200 "User-agent: *\nDisallow: /\n";
    }

    location ~ [^/]\.php(/|$) {
        fastcgi_pass 127.0.0.1:9001;        # domain.nginx_fastcgi_port
        include fastcgi.conf;
        fastcgi_param HTTPS on;
    }

    # /h5/ /console/ SPA —— 各自补充 X-Robots-Tag
    # （nginx add_header 继承规则：location 内另有 add_header 时不继承 server 级）
    location ^~ /h5/      { add_header X-Robots-Tag $x_robots_tag always; alias {public_path}/h5/;      try_files $uri $uri/ /h5/index.html; }
    location ^~ /console/ { add_header X-Robots-Tag $x_robots_tag always; alias {public_path}/console/; try_files $uri $uri/ /console/index.html; }

    location = / { return 302 /h5/; }
}
```

---

## 六、SEO/GEO 分层模型

平台无法控制租户内容，故按域名层级差异化收录策略：

| 层级 | 形态 | seo_allowed | SEO/GEO | AI 爬虫 |
|---|---|---|---|---|
| 免费兜底（自动）| `t-xxxxxx.{wildcard_base}` | 0 | 禁止（noindex + Disallow robots）| 403 |
| 付费二级域名 | `{slug}.{wildcard_base}` | 0 | 禁止 | 403 |
| 自定义域名 | `{tenant_domain}` | 1 | 开放 | 放行 |
| 平台域名 | admin/app/console | 1 | 开放 | 放行 |

policy 由单一 `$seo_allowed` 变量驱动：基桩同一条 `if ($block_ai_bot) return 403` 据此对子域名禁 GEO、对自定义域名开放 GEO。

---

## 七、自定义域名接入基桩

自定义域名经统一基桩承接，**无需独立 vhost**。SNI 证书须先纳入 ssl.map（`generateSslMap` 仅收录「`domain` 非空 + `ssl_uploaded_at` 非空 + `certs/{domain}.crt` 存在」的租户）：

```bash
# 1. 证书软链接进受管目录（命名严格匹配 {domain}.crt/.key；软链接避免副本，续签原地生效）
ln -sf /etc/ssl/<x>/fullchain.pem {nginx_deploy_path}/certs/{domain}.crt
ln -sf /etc/ssl/<x>/privkey.pem   {nginx_deploy_path}/certs/{domain}.key

# 2. 置租户 ssl_uploaded_at（tenants 表无 ssl_status 列，仅设此字段）
php artisan tinker --execute="MultiTenantSaas\Modules\Infrastructure\Models\Tenant::find(<tenant_id>)->update(['ssl_uploaded_at' => now()]);"

# 3. 重生成并确认 ssl.map 含该域名证书映射
php artisan domains:generate-nginx
grep {domain} {nginx_deploy_path}/maps/ssl.map

# 4. 校验并重载
nginx -t && nginx -s reload
```

若该域名此前有手写 vhost，停用之（脱离 `include vhost/*.conf` glob，保留备份供回滚）：

```bash
mv vhost/{domain}.conf vhost/{domain}.conf.disabled
nginx -t && nginx -s reload
```

---

## 八、验证与排障

### 校验 / 重载

```bash
nginx -t                 # 语法 + 自定义变量解析检查（map 未声明会报 unknown variable）
nginx -s reload          # 平滑重载
```

### curl 验证矩阵

服务器本地验证须 `--noproxy "*"` + `--resolve` 直指回环（避免代理污染）：

```bash
D=scrm.lanyantu.com
# SNI 证书
echo | openssl s_client -connect 127.0.0.1:443 -servername $D 2>/dev/null | openssl x509 -noout -subject
# 普通浏览器 / AI 爬虫 / noindex 头 / robots
curl -sk --noproxy "*" --resolve $D:443:127.0.0.1 -o /dev/null -w "%{http_code}\n" https://$D/h5/
curl -sk --noproxy "*" --resolve $D:443:127.0.0.1 -A "GPTBot/1.0" -o /dev/null -w "%{http_code}\n" https://$D/h5/
curl -sk --noproxy "*" --resolve $D:443:127.0.0.1 -D - -o /dev/null https://$D/h5/ | grep -i x-robots
curl -sk --noproxy "*" --resolve $D:443:127.0.0.1 https://$D/robots.txt
```

预期：

| 域名 | 普通 | GPTBot | noindex 头 | robots |
|---|---|---|---|---|
| 自定义域名（seo_allowed=1）| 200 | 200（GEO 开放）| 无 | `Allow: /` |
| 二级域名 / t-xxxxxx（seo_allowed=0）| 200 | 403 | `noindex, nofollow` | `Disallow: /` |
| 恶意域名（domain_allowed=0）| 444 断连 | — | — | — |

### 常见问题

| 现象 | 原因 / 处理 |
|---|---|
| `nginx -t` 报 `unknown "xxx" variable` | 对应 map 未被 include（检查 `maps/*.map` 是否齐全、顶层 include 是否在 http 层）|
| `nginx -t` 报 `conflicting parameter` | map 键重复（平台域名重叠 / 自定义域名撞二级域名）；生成器已去重，若手改过 map 请重新 `domains:generate-nginx` |
| 自定义域名 SNI 返回通配证书 | ssl.map 无该域名条目：检查证书软链接命名（`{domain}.crt`）与 `ssl_uploaded_at` 是否置位，再重生成 |
| 自定义域名 AI 爬虫被 403 | 该域名未列入 seo.map（`seo_allowed=0`）：确认 `tenants.domain` 已设且租户 `status=active`，重生成 |
| 恶意子域名被服务 | 白名单误用通配；本架构精确放行 slug，重新 `domains:generate-nginx` 覆盖手改 |

---

**文档版本**: v2.0.0
**最后更新**: 2026-08-02
