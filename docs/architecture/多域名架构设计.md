# 多域名架构设计

**最后更新**: 2026-06-18

---

## 域名规划

### 四重访问架构

| 层级 | 域名示例 | 路径 | 角色要求 | 说明 |
|------|----------|------|----------|------|
| 系统后台 | `admin.lyt.com` | `/*` | `super_admin` | 独立域名，避免暴力破解 |
| 租户后台 | `ai.lyt.com` | `/console/*` | `tenant_admin` | 路径区分或自定义域名 |
| 用户前台 | `ai.tenant1.local` | `/*` | `end_user` | 租户自定义域名 |
| 访客 | 同用户前台 | `/*` | 未登录 | 登录状态区分 |

### 域名类型常量

```php
IdentifyDomain::DOMAIN_ADMIN   = 'admin';    // 系统后台
IdentifyDomain::DOMAIN_CONSOLE = 'console';  // 租户后台
IdentifyDomain::DOMAIN_API     = 'api';      // API接口
IdentifyDomain::DOMAIN_APP     = 'app';      // 用户前台
IdentifyDomain::DOMAIN_DEFAULT = 'default';  // 默认
```

---

## 域名识别逻辑

### IdentifyDomain 中间件

```php
protected function identifyDomainType(string $host, string $path = '/'): string
{
    // 1. 测试环境 localhost
    if (app()->environment('testing') && $host === 'localhost') {
        // 路径区分
    }

    // 2. Admin域名精确匹配
    $adminDomain = config('app.admin_domain') ?? config('tenancy.admin_domain');
    if ($adminDomain && $host === $adminDomain) {
        return self::DOMAIN_ADMIN;
    }

    // 3. 路径区分
    if (str_starts_with($path, '/console')) {
        return self::DOMAIN_CONSOLE;
    }
    if (str_starts_with($path, '/api')) {
        return self::DOMAIN_API;
    }

    return self::DOMAIN_APP;
}
```

### 租户识别优先级

```php
protected function resolveTenantId(Request $request): ?string
{
    // 1. URL参数 ?tenant_id= 或 ?tid=
    // 2. Header X-Tenant-ID
    // 3. 自定义域名 (X-Original-Host → 数据库查询)
    // 4. Cookie tenant_id
    // 5. Session tenant_id
    // 6. 认证用户的 current_tenant_id
    // 7. 默认租户 config('tenancy.default_tenant_id')
}
```

---

## 域名配置方案

### 单域名模式

平台统一域名，通过路径区分不同功能：

```
ai.lyt.com/              → 用户前台
ai.lyt.com/console/*     → 租户后台
ai.lyt.com/api/*         → API接口
```

**优点**：
- 只需配置一个域名
- SSL证书管理简单
- DNS配置简单

**缺点**：
- 品牌感弱
- 无法体现企业独立性

### 多域名模式

租户使用独立域名，增强品牌感：

```
ai.tenant1.local/           → 用户前台
ai.tenant1.local/console/*  → 租户后台
ai-admin.tenant1.local/     → 租户后台（子域名方案）
```

**优点**：
- 企业品牌独立
- 更专业的形象
- 灵活的域名选择

**缺点**：
- 需要配置多个域名
- SSL证书管理复杂
- DNS配置复杂

---

## Nginx 配置

### 平台域名配置

```nginx
# 系统后台 - admin.lyt.com
server {
    listen 80;
    server_name admin.lyt.com;
    root /path/to/public;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_X_ORIGINAL_HOST $host;
        include fastcgi_params;
    }
}

# 平台租户 - ai.lyt.com
server {
    listen 80;
    server_name ai.lyt.com;
    root /path/to/public;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_X_ORIGINAL_HOST $host;
        include fastcgi_params;
    }
}
```

### 自定义域名配置

```nginx
# 企业自定义域名 - catch-all
server {
    listen 80 default_server;
    server_name _;
    root /path/to/public;
    
    # 域名白名单检查
    include /etc/nginx/conf.d/allowed-domains.map;
    if ($domain_allowed = 0) { return 403; }
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_X_ORIGINAL_HOST $host;
        include fastcgi_params;
    }
}
```

---

## 域名白名单管理

### 生成域名白名单

```bash
# 从数据库生成域名白名单
php artisan domains:generate-nginx-map

# 指定输出路径
php artisan domains:generate-nginx-map --output=/etc/nginx/conf.d/allowed-domains.map

# 生成后自动 reload Nginx
php artisan domains:generate-nginx-map --reload
```

### 白名单文件格式

```nginx
# /etc/nginx/conf.d/allowed-domains.map
map $host $domain_allowed {
    default 0;  # 默认拒绝
    
    # 平台域名
    admin.lyt.com       1;
    ai.lyt.com          1;
    
    # 企业自定义域名
    ai.tenant1.local    1;
    ai.tenant2.local    1;
}
```

---

## 域名审核流程

### 1. 租户提交域名申请

```php
// 通过 API 或后台提交
POST /api/v1/tenant/domains
{
    "domain": "ai.example.com",
    "icp_number": "京ICP备12345678号",  // 备案号（可选）
    "contact_email": "admin@example.com"
}
```

### 2. 系统验证

- 域名格式验证
- 域名唯一性检查
- 备案信息验证（如果启用）

### 3. 管理员审核

```bash
# 通过系统后台审核
# 或使用命令行
php artisan tenant:approve-domain {tenant_id} {domain}
```

### 4. 生效

- 更新数据库 `tenants.custom_domain`
- 生成 Nginx 配置
- 申请/上传 SSL 证书
- Reload Nginx

---

## 环境变量配置

```env
# 系统后台域名
ADMIN_DOMAIN=admin.lyt.com

# 平台租户域名
PLATFORM_DOMAIN=ai.lyt.com

# 备案要求
ICP_CHECK_ENABLED=false
ICP_CHECK_API_URL=https://api.example.com/icp/check

# 域名白名单路径
NGINX_DOMAIN_MAP_PATH=/etc/nginx/conf.d/allowed-domains.map
```

---

**文档版本**: v1.0.0  
**最后更新**: 2026-06-18
