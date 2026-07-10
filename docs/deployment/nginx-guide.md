# Nginx 配置指南

**最后更新**: 2026-06-18

---

## 配置文件结构

```
/etc/nginx/
├── nginx.conf              # 主配置
├── conf.d/
│   ├── saas-platform.conf  # 平台域名配置
│   ├── saas-custom.conf    # 自定义域名配置
│   └── allowed-domains.map # 域名白名单
└── ssl/
    ├── default.crt         # 默认 SSL 证书
    ├── default.key         # 默认 SSL 密钥
    ├── ai.tenant1.local.crt
    ├── ai.tenant1.local.key
    └── ssl-map.conf        # SSL map 文件
```

---

## 平台域名配置

### 系统后台 - admin.lyt.com

```nginx
# /etc/nginx/conf.d/saas-admin.conf

server {
    listen 80;
    server_name admin.lyt.com;
    root /var/www/html/public;
    index index.php;
    
    # 日志
    access_log /var/log/nginx/admin.lyt.com-access.log;
    error_log /var/log/nginx/admin.lyt.com-error.log;
    
    # 主路由
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    # PHP-FPM
    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_X_ORIGINAL_HOST $host;
        include fastcgi_params;
    }
    
    # 禁止访问隐藏文件
    location ~ /\.(?!well-known).* {
        deny all;
    }
    
    # 静态资源缓存
    location ~* \.(js|css|png|jpg|jpeg|gif|ico|svg)$ {
        expires 1y;
        add_header Cache-Control "public, immutable";
    }
}
```

### 平台租户 - ai.lyt.com

```nginx
# /etc/nginx/conf.d/saas-platform.conf

server {
    listen 80;
    server_name ai.lyt.com;
    root /var/www/html/public;
    index index.php;
    
    # 日志
    access_log /var/log/nginx/ai.lyt.com-access.log;
    error_log /var/log/nginx/ai.lyt.com-error.log;
    
    # 主路由
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    # 租户后台 - /console
    location ^~ /console {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_X_ORIGINAL_HOST $host;
        include fastcgi_params;
    }
    
    # API - /api
    location /api/ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_X_ORIGINAL_HOST $host;
        include fastcgi_params;
    }
    
    # PHP-FPM
    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_X_ORIGINAL_HOST $host;
        include fastcgi_params;
    }
    
    # 禁止访问隐藏文件
    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

---

## 自定义域名配置

### 域名白名单

```nginx
# /etc/nginx/conf.d/allowed-domains.map

map $host $domain_allowed {
    default 0;  # 默认拒绝
    
    # 平台域名
    admin.lyt.com       1;
    ai.lyt.com          1;
    
    # 内部服务通信
    127.0.0.1           1;
    localhost           1;
    
    # 企业自定义域名（从数据库自动生成）
    # AUTO_GENERATED_DOMAINS_START
    ai.tenant1.local    1;
    ai.tenant2.local    1;
    # AUTO_GENERATED_DOMAINS_END
}
```

### 自定义域名 catch-all

```nginx
# /etc/nginx/conf.d/saas-custom.conf

# 引入域名白名单
include /etc/nginx/conf.d/allowed-domains.map;

server {
    listen 80 default_server;
    server_name _;
    root /var/www/html/public;
    index index.php;
    
    # 域名白名单检查
    if ($domain_allowed = 0) {
        return 403;
    }
    
    # 日志
    access_log /var/log/nginx/custom-domains-access.log;
    error_log /var/log/nginx/custom-domains-error.log;
    
    # 主路由
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    # 租户后台 - /console
    location ^~ /console {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_X_ORIGINAL_HOST $host;
        include fastcgi_params;
    }
    
    # API - /api
    location /api/ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_X_ORIGINAL_HOST $host;
        include fastcgi_params;
    }
    
    # PHP-FPM
    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_X_ORIGINAL_HOST $host;
        include fastcgi_params;
    }
    
    # 禁止访问隐藏文件
    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

---

## SSL 配置

### SSL map 文件

```nginx
# /etc/nginx/ssl/ssl-map.conf

map $ssl_server_name $ssl_cert_file {
    default /etc/nginx/ssl/default.crt;
    ai.tenant1.local /etc/nginx/ssl/ai.tenant1.local.crt;
    ai.tenant2.local /etc/nginx/ssl/ai.tenant2.local.crt;
}

map $ssl_server_name $ssl_key_file {
    default /etc/nginx/ssl/default.key;
    ai.tenant1.local /etc/nginx/ssl/ai.tenant1.local.key;
    ai.tenant2.local /etc/nginx/ssl/ai.tenant2.local.key;
}
```

### SSL 服务器配置

```nginx
# /etc/nginx/conf.d/saas-ssl.conf

# 引入 SSL map
include /etc/nginx/ssl/ssl-map.conf;

server {
    listen 443 ssl http2 default_server;
    server_name _;
    root /var/www/html/public;
    index index.php;
    
    # SSL 证书（动态）
    ssl_certificate $ssl_cert_file;
    ssl_certificate_key $ssl_key_file;
    
    # SSL 配置
    ssl_protocols TLSv1.2 TLSv1.3;
    ssl_ciphers ECDHE-RSA-AES256-GCM-SHA512:DHE-RSA-AES256-GCM-SHA512;
    ssl_prefer_server_ciphers off;
    ssl_session_cache shared:SSL:10m;
    ssl_session_timeout 1d;
    
    # 域名白名单检查
    include /etc/nginx/conf.d/allowed-domains.map;
    if ($domain_allowed = 0) {
        return 403;
    }
    
    # 主路由
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    # 租户后台 - /console
    location ^~ /console {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_X_ORIGINAL_HOST $host;
        fastcgi_param HTTPS on;
        include fastcgi_params;
    }
    
    # API - /api
    location /api/ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_X_ORIGINAL_HOST $host;
        fastcgi_param HTTPS on;
        include fastcgi_params;
    }
    
    # PHP-FPM
    location ~ \.php$ {
        fastcgi_pass 127.0.0.1:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_X_ORIGINAL_HOST $host;
        fastcgi_param HTTPS on;
        include fastcgi_params;
    }
    
    # 禁止访问隐藏文件
    location ~ /\.(?!well-known).* {
        deny all;
    }
}

# HTTP 重定向到 HTTPS
server {
    listen 80;
    server_name _;
    return 301 https://$host$request_uri;
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
    
    # 内部服务通信
    127.0.0.1           1;
    localhost           1;
    
    # 企业自定义域名（从数据库自动生成）
    # AUTO_GENERATED_DOMAINS_START
    ai.tenant1.local    1;
    ai.tenant2.local    1;
    # AUTO_GENERATED_DOMAINS_END
}
```

---

## 测试配置

### 测试 Nginx 配置

```bash
nginx -t
```

### 重启 Nginx

```bash
# macOS
brew services restart nginx

# Linux
sudo systemctl restart nginx
```

### 重新加载配置

```bash
# macOS
brew services reload nginx

# Linux
sudo nginx -s reload
```

---

## 常见问题

### Q: 502 Bad Gateway

A: 检查 PHP-FPM 是否运行：

```bash
# 检查 PHP-FPM 状态
brew services list | grep php

# 重启 PHP-FPM
brew services restart php
```

### Q: 域名无法访问

A: 检查 hosts 配置：

```bash
# 检查域名解析
nslookup ai.lyt.com

# 检查 hosts 文件
cat /etc/hosts | grep lyt
```

### Q: SSL 证书错误

A: 检查 SSL 证书：

```bash
# 检查证书有效期
openssl x509 -in /etc/nginx/ssl/ai.tenant1.local.crt -noout -dates

# 检查证书匹配
openssl x509 -in /etc/nginx/ssl/ai.tenant1.local.crt -noout -modulus | openssl md5
openssl rsa -in /etc/nginx/ssl/ai.tenant1.local.key -noout -modulus | openssl md5
```

### Q: 域名白名单不生效

A: 检查白名单文件：

```bash
# 检查白名单文件
cat /etc/nginx/conf.d/allowed-domains.map

# 重新生成白名单
php artisan domains:generate-nginx-map --reload
```

---

**文档版本**: v1.0.0  
**最后更新**: 2026-06-18
