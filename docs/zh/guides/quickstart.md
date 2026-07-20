# 快速开始（5 分钟上手）

**最后更新**: 2026-07-19

> 目标：5 分钟内完成安装、配置、迁移并验证服务可用。

---

## 安装

### 方式一：创建新项目（推荐）

```bash
composer create-project dsplat/multi-tenant-saas my-saas-app
cd my-saas-app
```

### 方式二：在现有项目中安装

```bash
composer require dsplat/multi-tenant-saas
```

---

## 环境配置

### 1. 复制环境文件

```bash
cp .env.example .env
```

### 2. 生成应用密钥

```bash
php artisan key:generate
```

### 3. 配置数据库

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=multi_tenant_saas
DB_USERNAME=your_username
DB_PASSWORD=your_password
```

### 4. 配置域名

```env
# 系统后台域名
ADMIN_DOMAIN=admin.example.com

# 平台租户域名
PLATFORM_DOMAIN=ai.example.com
```

---

## 数据库迁移与初始化

### 一键初始化（推荐）

```bash
php artisan migrate
php artisan platform:init --email=admin@example.com --password=your-password
```

`platform:init` 自动完成：
- 创建平台默认租户
- 创建系统角色和权限
- 创建超级管理员账号
- 部署 server.php（PHP 内置服务器 SPA 路由修复）

### 手动创建测试数据（可选）

```php
use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Models\TenantUser;

// 创建系统管理员
$admin = User::create([
    'name' => '系统管理员',
    'email' => 'admin@example.com',
    'password' => bcrypt('password'),
    'role' => 'super_admin',
]);

// 创建平台租户
$platformTenant = Tenant::create([
    'tenant_id' => 9007199254740991,
    'name' => '平台默认租户',
    'slug' => 'platform',
    'custom_domain' => 'ai.example.com',
    'status' => 'active',
    'is_platform_default' => true,
]);

// 创建企业租户
$tenant = Tenant::create([
    'name' => '示例企业',
    'slug' => 'example',
    'custom_domain' => 'ai.example.local',
    'status' => 'active',
]);

// 关联用户到租户
TenantUser::create([
    'tenant_id' => $tenant->tenant_id,
    'user_id' => $admin->id,
    'role' => 'tenant_admin',
    'is_active' => true,
]);
```

---

## Nginx 配置

### 1. 配置 hosts

```bash
# /etc/hosts
127.0.0.1  admin.example.com
127.0.0.1  ai.example.com
127.0.0.1  ai.example.local
```

### 2. 创建 Nginx 配置

```nginx
# /opt/homebrew/etc/nginx/servers/saas.conf

# 系统后台
server {
    listen 80;
    server_name admin.example.com;
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

# 平台租户
server {
    listen 80;
    server_name ai.example.com;
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

# 企业租户
server {
    listen 80;
    server_name ai.example.local;
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

### 3. 测试并重启 Nginx

```bash
nginx -t
brew services restart nginx
```

---

## 验证安装

### 访问测试

```bash
# 系统后台
curl http://admin.example.com/admin

# 平台租户
curl http://ai.example.com

# 企业租户
curl http://ai.example.local

# 租户后台
curl http://ai.example.local/console
```

### 预期响应

```json
{
    "message": "Multi-Tenant SaaS 测试页面",
    "domain_type": "app",
    "host": "ai.example.com",
    "tenant_id": "8094539620840357",
    "tenant_name": "平台默认租户"
}
```

---

## 下一步

- [四重访问架构](四重访问架构.md) - 了解 admin/console/app/guest 四层架构
- [域名配置指南](域名配置指南.md) - 配置单域名和多域名模式
- [权限控制指南](权限控制指南.md) - 了解角色和权限系统
- [数据模型设计](../architecture/数据模型设计.md) - 了解数据表结构

---

**文档版本**: v1.0.0  
**最后更新**: 2026-06-29
