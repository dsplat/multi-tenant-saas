# 域名配置指南

**最后更新**: 2026-06-18

---

## 域名规划

### 推荐域名方案

| 用途 | 域名示例 | 说明 |
|------|----------|------|
| 系统后台 | `admin.lyt.com` | 独立域名，避免暴力破解 |
| 平台租户 | `ai.lyt.com` | 单域名模式 |
| 企业租户A | `ai.tenant1.local` | 多域名模式 |
| 企业租户B | `ai.tenant2.local` | 多域名模式 |

---

## 单域名模式

所有功能在同一域名下，通过路径区分：

```
ai.lyt.com/              → 用户前台/访客
ai.lyt.com/console/*     → 租户后台
ai.lyt.com/api/*         → API接口
```

### 优点

- 只需配置一个域名
- SSL证书管理简单
- DNS配置简单

### 缺点

- 品牌感弱
- 无法体现企业独立性

### Nginx 配置

```nginx
server {
    listen 80;
    server_name ai.lyt.com;
    root /path/to/public;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ^~ /console {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_X_ORIGINAL_HOST $host;
        include fastcgi_params;
    }
    
    location /api/ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_X_ORIGINAL_HOST $host;
        include fastcgi_params;
    }
}
```

---

## 多域名模式

租户使用独立域名：

```
ai.tenant1.local/           → 用户前台/访客
ai.tenant1.local/console/*  → 租户后台
```

### 优点

- 企业品牌独立
- 更专业的形象
- 灵活的域名选择

### 缺点

- 需要配置多个域名
- SSL证书管理复杂
- DNS配置复杂

### Nginx 配置

```nginx
# 企业租户域名
server {
    listen 80;
    server_name ai.tenant1.local;
    root /path/to/public;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ^~ /console {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_X_ORIGINAL_HOST $host;
        include fastcgi_params;
    }
}
```

---

## 子域名模式

使用子域名区分管理后台和用户前台：

```
ai-admin.tenant1.local/    → 租户后台
ai.tenant1.local/          → 用户前台
```

### Nginx 配置

```nginx
# 租户后台 - 子域名
server {
    listen 80;
    server_name ai-admin.tenant1.local;
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

# 用户前台
server {
    listen 80;
    server_name ai.tenant1.local;
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

### 使用白名单

```nginx
server {
    listen 80 default_server;
    server_name _;
    
    # 引入白名单
    include /etc/nginx/conf.d/allowed-domains.map;
    
    # 检查域名是否允许
    if ($domain_allowed = 0) {
        return 403;
    }
    
    # ... 其他配置
}
```

---

## 域名审核流程

### 1. 租户提交域名申请

```php
// 通过 API 提交
POST /api/v1/tenant/domains
{
    "domain": "ai.example.com",
    "icp_number": "京ICP备12345678号",
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

## SSL 证书配置

### Let's Encrypt 自动证书

```bash
# 安装 certbot
apt install certbot python3-certbot-nginx

# 申请证书
certbot --nginx -d admin.lyt.com -d ai.lyt.com

# 自动续期
0 0 1 * * certbot renew --quiet
```

### 自定义域名 SSL

```nginx
# SSL map 文件（由 TenantSslService 生成）
include /app/ssl-certs/ssl-map.conf;

server {
    listen 443 ssl http2;
    server_name _;
    
    ssl_certificate $ssl_cert_file;
    ssl_certificate_key $ssl_key_file;
    
    # ... 其他配置
}
```

### SSL map 文件格式

```nginx
# /app/ssl-certs/ssl-map.conf
map $ssl_server_name $ssl_cert_file {
    default /app/ssl-certs/default.crt;
    ai.tenant1.local /app/ssl-certs/ai.tenant1.local.crt;
    ai.tenant2.local /app/ssl-certs/ai.tenant2.local.crt;
}

map $ssl_server_name $ssl_key_file {
    default /app/ssl-certs/default.key;
    ai.tenant1.local /app/ssl-certs/ai.tenant1.local.key;
    ai.tenant2.local /app/ssl-certs/ai.tenant2.local.key;
}
```

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

# SSL证书路径
SSL_CERTS_PATH=/app/ssl-certs
SSL_NGINX_MAP_FILE=/app/ssl-certs/ssl-map.conf
```

---

## 本地开发配置

### hosts 文件配置

```bash
# /etc/hosts
127.0.0.1  admin.lyt.com
127.0.0.1  ai.lyt.com
127.0.0.1  ai.tenant1.local
127.0.0.1  ai-admin.tenant1.local
```

### Nginx 本地配置

```nginx
# /opt/homebrew/etc/nginx/servers/saas.conf

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

# ... 其他域名配置
```

---

**文档版本**: v1.0.0  
**最后更新**: 2026-06-18
