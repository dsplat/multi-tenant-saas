# 部署指南

**最后更新**: 2026-06-29

---

## 部署架构

```
                    【接入层】
                       │
            ┌──────────┴──────────┐
            │                     │
       域名解析(DNS)         SSL卸载
            │                     │
       CDN加速              WAF防护
            │                     │
            └──────────┬──────────┘
                       │
                 【负载均衡层】
                       │
         ┌─────────────┼─────────────┐
         │             │             │
    Nginx (SLB)   Nginx (SLB)   Nginx (SLB)
         │             │             │
         └─────────────┴─────────────┘
                       │
                  【应用层】
                       │
         ┌─────────────┼─────────────┐
         │             │             │
    Laravel App   Laravel App   Laravel App
    (Octane)      (Octane)      (Octane)
    Port:8000     Port:8000     Port:8000
         │             │             │
         └─────────────┴─────────────┘
                       │
            ┌──────────┴──────────┐
            │                     │
        【数据层】            【缓存层】
            │                     │
    ┌───────┴───────┐       Redis Cluster
    │               │       (主从+哨兵)
MySQL Master   MySQL Slave       │
(读写)        (只读副本)   ┌──────┴──────┐
    │               │       │      │      │
    └───────┬───────┘    Master  Slave  Slave
            │
        【存储层】
            │
    ┌───────┴───────┐
    │               │
 对象存储(OSS)   本地文件系统
```

---

## 服务器规划

### 最小配置（开发/测试）

```
服务器：2台
├─ App + MySQL + Redis (4核8G)
└─ 备份服务器 (2核4G)

总成本：约 ¥300/月
```

### 生产环境推荐配置

```
Web应用层：3台 (4核8G)
  ├─ Laravel Octane容器
  └─ Nginx反向代理

数据库层：2台 (8核16G)
  ├─ MySQL主库 (Master)
  └─ MySQL从库 (Slave)

缓存层：3台 (2核4G)
  ├─ Redis Master
  └─ Redis Slave × 2

负载均衡：1台 (2核4G)
  └─ Nginx / HAProxy

总成本：约 ¥2000/月
```

---

## Docker 部署

### docker-compose.yml

```yaml
version: '3.8'

services:
  # Laravel应用
  app:
    build:
      context: .
      dockerfile: Dockerfile
    container_name: saas-app
    restart: unless-stopped
    working_dir: /var/www/html
    volumes:
      - ./:/var/www/html
      - ./storage:/var/www/html/storage
    environment:
      - DB_HOST=mysql
      - REDIS_HOST=redis
      - QUEUE_CONNECTION=redis
    networks:
      - saas-network
    depends_on:
      - mysql
      - redis

  # Nginx
  nginx:
    image: nginx:alpine
    container_name: saas-nginx
    restart: unless-stopped
    ports:
      - "80:80"
      - "443:443"
    volumes:
      - ./:/var/www/html
      - ./docker/nginx/conf.d:/etc/nginx/conf.d
      - ./docker/nginx/ssl:/etc/nginx/ssl
    networks:
      - saas-network
    depends_on:
      - app

  # MySQL
  mysql:
    image: mysql:8.0
    container_name: saas-mysql
    restart: unless-stopped
    environment:
      MYSQL_ROOT_PASSWORD: ${DB_PASSWORD}
      MYSQL_DATABASE: ${DB_DATABASE}
      MYSQL_USER: ${DB_USERNAME}
      MYSQL_PASSWORD: ${DB_PASSWORD}
    ports:
      - "3306:3306"
    volumes:
      - mysql-data:/var/lib/mysql
      - ./docker/mysql/my.cnf:/etc/mysql/conf.d/my.cnf
    networks:
      - saas-network

  # Redis
  redis:
    image: redis:7-alpine
    container_name: saas-redis
    restart: unless-stopped
    ports:
      - "6379:6379"
    volumes:
      - redis-data:/data
    networks:
      - saas-network

  # 队列工作进程
  queue:
    build:
      context: .
      dockerfile: Dockerfile
    container_name: saas-queue
    restart: unless-stopped
    working_dir: /var/www/html
    volumes:
      - ./:/var/www/html
    command: php artisan queue:work --sleep=3 --tries=3
    networks:
      - saas-network
    depends_on:
      - mysql
      - redis

  # 定时任务
  scheduler:
    build:
      context: .
      dockerfile: Dockerfile
    container_name: saas-scheduler
    restart: unless-stopped
    working_dir: /var/www/html
    volumes:
      - ./:/var/www/html
    command: >
      sh -c "while true; do
        php artisan schedule:run --verbose --no-interaction &
        sleep 60
      done"
    networks:
      - saas-network
    depends_on:
      - mysql
      - redis

networks:
  saas-network:
    driver: bridge

volumes:
  mysql-data:
    driver: local
  redis-data:
    driver: local
```

### Dockerfile

```dockerfile
FROM php:8.2-fpm-alpine

WORKDIR /var/www/html

# 安装系统依赖
RUN apk add --no-cache \
    git \
    curl \
    libpng-dev \
    libzip-dev \
    zip \
    unzip \
    mysql-client \
    supervisor

# 安装PHP扩展
RUN docker-php-ext-install \
    pdo_mysql \
    mysqli \
    zip \
    bcmath \
    opcache

# 安装Redis扩展
RUN pecl install redis && docker-php-ext-enable redis

# 安装Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 复制应用代码
COPY . .

# 安装依赖
RUN composer install --no-dev --optimize-autoloader --no-interaction

# 设置权限
RUN chown -R www-data:www-data /var/www/html/storage /var/www/html/bootstrap/cache

# Laravel优化
RUN php artisan config:cache && \
    php artisan route:cache && \
    php artisan view:cache

EXPOSE 9000
CMD ["php-fpm"]
```

---

## Kubernetes 部署

除 Docker Compose 外，生产环境可使用 Kubernetes 编排。以下清单覆盖应用、队列、调度器、数据库与缓存。

### 1. Namespace

```yaml
# k8s/namespace.yaml
apiVersion: v1
kind: Namespace
metadata:
  name: saas
```

### 2. ConfigMap 与 Secret

```yaml
# k8s/config.yaml
apiVersion: v1
kind: ConfigMap
metadata:
  name: saas-config
  namespace: saas
data:
  APP_ENV: "production"
  APP_DEBUG: "false"
  APP_URL: "https://ai.lyt.com"
  DB_CONNECTION: "mysql"
  DB_HOST: "saas-mysql.saas.svc.cluster.local"
  DB_PORT: "3306"
  DB_DATABASE: "saas_production"
  REDIS_HOST: "saas-redis.saas.svc.cluster.local"
  CACHE_DRIVER: "redis"
  SESSION_DRIVER: "redis"
  QUEUE_CONNECTION: "redis"
  ADMIN_DOMAIN: "admin.lyt.com"
---
apiVersion: v1
kind: Secret
metadata:
  name: saas-secret
  namespace: saas
type: Opaque
stringData:
  APP_KEY: "base64:xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx"
  DB_USERNAME: "saas_user"
  DB_PASSWORD: "secure_password"
  REDIS_PASSWORD: "secure_password"
```

### 3. 应用 Deployment + Service

```yaml
# k8s/app.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: saas-app
  namespace: saas
spec:
  replicas: 3
  selector:
    matchLabels: { app: saas-app }
  template:
    metadata:
      labels: { app: saas-app }
    spec:
      containers:
        - name: app
          image: registry.example.com/saas-app:1.0.0
          ports: [{ containerPort: 9000 }]
          envFrom:
            - configMapRef: { name: saas-config }
            - secretRef: { name: saas-secret }
          readinessProbe:
            httpGet: { path: /api/v1/health, port: 9000 }
            initialDelaySeconds: 10
            periodSeconds: 10
          livenessProbe:
            httpGet: { path: /api/v1/health, port: 9000 }
            initialDelaySeconds: 30
            periodSeconds: 30
          resources:
            requests: { cpu: "250m", memory: "512Mi" }
            limits: { cpu: "1000m", memory: "1Gi" }
          volumeMounts:
            - { name: storage, mountPath: /var/www/html/storage }
      volumes:
        - name: storage
          persistentVolumeClaim: { claimName: saas-storage }
---
apiVersion: v1
kind: Service
metadata:
  name: saas-app
  namespace: saas
spec:
  selector: { app: saas-app }
  ports: [{ port: 9000, targetPort: 9000 }]
```

### 4. 队列 Worker（Deployment）

```yaml
# k8s/queue.yaml
apiVersion: apps/v1
kind: Deployment
metadata:
  name: saas-queue
  namespace: saas
spec:
  replicas: 2
  selector:
    matchLabels: { app: saas-queue }
  template:
    metadata:
      labels: { app: saas-queue }
    spec:
      containers:
        - name: queue
          image: registry.example.com/saas-app:1.0.0
          command: ["php", "artisan", "queue:work", "--sleep=3", "--tries=3"]
          envFrom:
            - configMapRef: { name: saas-config }
            - secretRef: { name: saas-secret }
          resources:
            requests: { cpu: "250m", memory: "256Mi" }
            limits: { cpu: "500m", memory: "512Mi" }
```

### 5. 定时任务（CronJob）

```yaml
# k8s/scheduler.yaml
apiVersion: batch/v1
kind: CronJob
metadata:
  name: saas-scheduler
  namespace: saas
spec:
  schedule: "* * * * *"
  jobTemplate:
    spec:
      template:
        spec:
          containers:
            - name: scheduler
              image: registry.example.com/saas-app:1.0.0
              command: ["php", "artisan", "schedule:run", "--no-interaction"]
              envFrom:
                - configMapRef: { name: saas-config }
                - secretRef: { name: saas-secret }
          restartPolicy: OnFailure
```

### 6. Ingress

```yaml
# k8s/ingress.yaml
apiVersion: networking.k8s.io/v1
kind: Ingress
metadata:
  name: saas-ingress
  namespace: saas
  annotations:
    nginx.ingress.kubernetes.io/ssl-redirect: "true"
    cert-manager.io/cluster-issuer: letsencrypt
spec:
  tls:
    - hosts: [admin.lyt.com, ai.lyt.com]
      secretName: saas-tls
  rules:
    - host: admin.lyt.com
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service: { name: saas-app, port: { number: 9000 } }
    - host: ai.lyt.com
      http:
        paths:
          - path: /
            pathType: Prefix
            backend:
              service: { name: saas-app, port: { number: 9000 } }
```

### 7. 数据库与缓存（StatefulSet）

生产环境推荐使用托管 MySQL/Redis 或 StatefulSet + 持久卷。示例使用 StatefulSet 部署 MySQL：

```yaml
# k8s/mysql.yaml
apiVersion: apps/v1
kind: StatefulSet
metadata:
  name: saas-mysql
  namespace: saas
spec:
  serviceName: saas-mysql
  replicas: 1
  selector:
    matchLabels: { app: saas-mysql }
  template:
    metadata:
      labels: { app: saas-mysql }
    spec:
      containers:
        - name: mysql
          image: mysql:8.0
          env:
            - { name: MYSQL_ROOT_PASSWORD, valueFrom: { secretKeyRef: { name: saas-secret, key: DB_PASSWORD } } }
            - { name: MYSQL_DATABASE, value: saas_production }
          ports: [{ containerPort: 3306 }]
          volumeMounts:
            - { name: data, mountPath: /var/lib/mysql }
  volumeClaimTemplates:
    - metadata: { name: data }
      spec:
        accessModes: ["ReadWriteOnce"]
        resources: { requests: { storage: 50Gi } }
---
apiVersion: v1
kind: Service
metadata:
  name: saas-mysql
  namespace: saas
spec:
  selector: { app: saas-mysql }
  ports: [{ port: 3306 }]
```

### 8. 部署与迁移

```bash
# 应用清单
kubectl apply -f k8s/namespace.yaml
kubectl apply -f k8s/config.yaml
kubectl apply -f k8s/mysql.yaml
kubectl apply -f k8s/app.yaml
kubectl apply -f k8s/queue.yaml
kubectl apply -f k8s/scheduler.yaml
kubectl apply -f k8s/ingress.yaml

# 数据库迁移（一次性 Job）
kubectl run saas-migrate --rm -it --restart=Never \
  --image=registry.example.com/saas-app:1.0.0 \
  --namespace=saas \
  --env-from=configmap/saas-config \
  --env-from=secret/saas-secret \
  --command -- php artisan migrate --force

# 配置缓存
kubectl exec -n saas deploy/saas-app -- php artisan config:cache
kubectl exec -n saas deploy/saas-app -- php artisan route:cache
```

### 9. 滚动更新与回滚

```bash
# 滚动更新镜像
kubectl set image deploy/saas-app app=registry.example.com/saas-app:1.1.0 -n saas
kubectl set image deploy/saas-queue queue=registry.example.com/saas-app:1.1.0 -n saas

# 查看发布状态
kubectl rollout status deploy/saas-app -n saas

# 回滚
kubectl rollout undo deploy/saas-app -n saas
kubectl rollout undo deploy/saas-queue -n saas
```

> 生产建议启用 HPA（水平自动伸缩）与 `cert-manager` 自动签发 TLS 证书。详见 [运维手册](运维手册.md)。

---

## Nginx 配置

### 平台域名配置

```nginx
# /etc/nginx/conf.d/saas-platform.conf

# 系统后台 - admin.lyt.com
server {
    listen 80;
    server_name admin.lyt.com;
    root /var/www/html/public;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_X_ORIGINAL_HOST $host;
        include fastcgi_params;
    }
    
    location ~ /\.(?!well-known).* {
        deny all;
    }
}

# 平台租户 - ai.lyt.com
server {
    listen 80;
    server_name ai.lyt.com;
    root /var/www/html/public;
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_X_ORIGINAL_HOST $host;
        include fastcgi_params;
    }
    
    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

### 自定义域名配置

```nginx
# /etc/nginx/conf.d/saas-custom-domains.conf

# 域名白名单
include /etc/nginx/conf.d/allowed-domains.map;

# 企业自定义域名 - catch-all
server {
    listen 80 default_server;
    server_name _;
    root /var/www/html/public;
    
    # 域名白名单检查
    if ($domain_allowed = 0) { return 403; }
    
    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }
    
    location ~ \.php$ {
        fastcgi_pass app:9000;
        fastcgi_index index.php;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_param HTTP_X_ORIGINAL_HOST $host;
        include fastcgi_params;
    }
    
    location ~ /\.(?!well-known).* {
        deny all;
    }
}
```

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
# /etc/nginx/conf.d/saas-ssl.conf

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

---

## 环境变量

### 生产环境 .env

```env
APP_NAME="Multi-Tenant SaaS"
APP_ENV=production
APP_KEY=base64:xxx
APP_DEBUG=false
APP_URL=https://ai.lyt.com

# 数据库
DB_CONNECTION=mysql
DB_HOST=mysql-master
DB_PORT=3306
DB_DATABASE=saas_production
DB_USERNAME=saas_user
DB_PASSWORD=secure_password

# Redis
REDIS_HOST=redis-master
REDIS_PASSWORD=secure_password
REDIS_PORT=6379

# 缓存
CACHE_DRIVER=redis
SESSION_DRIVER=redis
QUEUE_CONNECTION=redis

# 域名
ADMIN_DOMAIN=admin.lyt.com

# 日志
LOG_CHANNEL=stack
LOG_LEVEL=error
```

---

## 部署步骤

### 1. 服务器准备

```bash
# 更新系统
apt update && apt upgrade -y

# 安装 Docker
curl -fsSL https://get.docker.com -o get-docker.sh
sh get-docker.sh

# 安装 Docker Compose
apt install docker-compose -y
```

### 2. 代码部署

```bash
# 克隆代码
git clone https://github.com/dsplat/multi-tenant-saas.git /var/www/saas

# 进入目录
cd /var/www/saas

# 复制环境文件
cp .env.example .env

# 编辑环境变量
vi .env

# 启动服务
docker-compose up -d

# 运行迁移
docker-compose exec app php artisan migrate --force

# 优化
docker-compose exec app php artisan config:cache
docker-compose exec app php artisan route:cache
docker-compose exec app php artisan view:cache
```

### 3. Nginx 配置

```bash
# 复制 Nginx 配置
cp docker/nginx/conf.d/*.conf /etc/nginx/conf.d/

# 测试配置
nginx -t

# 重启 Nginx
systemctl restart nginx
```

### 4. SSL 证书

```bash
# 安装 certbot
apt install certbot python3-certbot-nginx -y

# 申请证书
certbot --nginx -d admin.lyt.com -d ai.lyt.com

# 测试自动续期
certbot renew --dry-run
```

---

## 监控

### Laravel Horizon（队列监控）

```bash
# 安装
composer require laravel/horizon

# 发布配置
php artisan horizon:install

# 启动
php artisan horizon
```

### Laravel Telescope（调试）

```bash
# 安装（仅开发环境）
composer require laravel/telescope --dev

# 发布配置
php artisan telescope:install

# 运行迁移
php artisan migrate
```

### Sentry（错误追踪）

```bash
# 安装
composer require sentry/sentry-laravel

# 配置
php artisan sentry:publish --dsn=your_dsn
```

---

## 备份

### 数据库备份

```bash
#!/bin/bash
# backup.sh

DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_DIR="/data/backups/mysql"
DB_NAME="saas_production"

# 全量备份
mysqldump -u root -p${DB_PASSWORD} ${DB_NAME} | gzip > ${BACKUP_DIR}/${DB_NAME}_${DATE}.sql.gz

# 删除7天前的备份
find ${BACKUP_DIR} -name "*.sql.gz" -mtime +7 -delete

# 上传到 OSS
ossutil cp ${BACKUP_DIR}/${DB_NAME}_${DATE}.sql.gz oss://backups/mysql/
```

### 定时备份

```bash
# 添加到 crontab
0 2 * * * /path/to/backup.sh >> /var/log/backup.log 2>&1
```

---

## 故障排除

### 常见问题

**1. 502 Bad Gateway**
```bash
# 检查 PHP-FPM
systemctl status php-fpm

# 检查 Nginx 配置
nginx -t
```

**2. 数据库连接失败**
```bash
# 检查 MySQL 状态
systemctl status mysql

# 检查数据库配置
cat .env | grep DB_
```

**3. 租户识别失败**
```bash
# 检查域名解析
nslookup ai.lyt.com

# 检查 Nginx 配置
nginx -t

# 检查 Laravel 日志
tail -f storage/logs/laravel.log
```

---

**文档版本**: v1.0.0  
**最后更新**: 2026-06-29
