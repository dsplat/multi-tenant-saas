# 本地开发环境

**最后更新**: 2026-06-18

---

## 环境要求

- PHP 8.2+
- MySQL 8.0+
- Redis 7.0+
- Nginx
- Composer

---

## 安装步骤

### 1. 克隆代码

```bash
git clone https://github.com/dsplat/multi-tenant-saas.git
cd multi-tenant-saas
```

### 2. 安装依赖

```bash
composer install
```

### 3. 环境配置

```bash
cp .env.example .env
php artisan key:generate
```

### 4. 配置数据库

```env
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=multi_tenant_saas
DB_USERNAME=root
DB_PASSWORD=
```

### 5. 运行迁移

```bash
php artisan migrate
```

### 6. 创建测试数据

```bash
php artisan tinker
```

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

## PHP-FPM 配置

### 安装 PHP-FPM

```bash
brew install php
brew services start php
```

### 配置 PHP-FPM

```ini
; /opt/homebrew/etc/php/8.2/php-fpm.d/www.conf
[www]
user = arthur
group = staff
listen = 127.0.0.1:9000
pm = dynamic
pm.max_children = 5
pm.start_servers = 2
pm.min_spare_servers = 1
pm.max_spare_servers = 3
```

---

## 测试验证

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

## 测试账号

| 账号 | 邮箱 | 密码 | 角色 |
|------|------|------|------|
| 系统管理员 | admin@example.com | password | super_admin |
| 租户管理员 | admin@tenant1.local | password | tenant_admin |
| 普通用户 | user@tenant1.local | password | end_user |

---

## 常见问题

### Q: 502 Bad Gateway

A: 检查 PHP-FPM 是否运行：

```bash
brew services list | grep php
brew services restart php
```

### Q: 数据库连接失败

A: 检查 MySQL 是否运行：

```bash
brew services list | grep mysql
brew services restart mysql
```

### Q: 租户识别失败

A: 检查 hosts 配置和 Nginx 配置：

```bash
# 检查域名解析
nslookup ai.example.com

# 检查 Nginx 配置
nginx -t

# 检查 Laravel 日志
tail -f storage/logs/laravel.log
```

### Q: 权限被拒绝

A: 检查用户角色和租户关联：

```bash
php artisan tinker
```

```php
use MultiTenantSaas\Models\User;
use MultiTenantSaas\Models\TenantUser;

$user = User::where('email', 'admin@example.com')->first();
$user->role; // 应该是 'super_admin'

$tenantUser = TenantUser::where('user_id', $user->id)->first();
$tenantUser->role; // 应该是 'tenant_admin'
```

---

## 开发工具

### Laravel Telescope（调试）

```bash
composer require laravel/telescope --dev
php artisan telescope:install
php artisan migrate
```

访问：`http://ai.example.com/telescope`

### Laravel Horizon（队列监控）

```bash
composer require laravel/horizon
php artisan horizon:install
php artisan horizon
```

访问：`http://ai.example.com/horizon`

---

**文档版本**: v1.0.0  
**最后更新**: 2026-06-18
