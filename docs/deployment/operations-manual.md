# 运维手册

**最后更新**: 2026-06-29
**面向对象**: 运维人员（SRE / DevOps）
**目标**: 提供可操作的生产环境运维指南，覆盖部署检查、环境要求、配置项、启动步骤与健康检查。

> 配套文档：
> - [发布检查清单](发布检查清单.md) — 逐项打勾式发布流程
> - [备份恢复流程](备份恢复流程.md) — 数据库备份与恢复
> - [故障应急手册](故障应急手册.md) — 常见故障、灰度发布、回滚
> - [监控告警配置](监控告警配置.md) — 监控指标与告警阈值
> - [部署指南](部署指南.md) — Docker / Kubernetes 部署架构

---

## 1. 部署检查清单

发布前请对照 [发布检查清单](发布检查清单.md) 逐项确认。核心要点：

- [ ] 当前分支为 `main`，工作区干净（`git status` 无未提交改动）
- [ ] `composer install --no-dev` 已执行
- [ ] `.env` 中 `APP_ENV=production`、`APP_DEBUG=false`
- [ ] 数据库迁移已预演（`php artisan migrate --pretend`）
- [ ] 维护模式已开启（`php artisan down`）
- [ ] 缓存已重建（config/route/view/event:cache）
- [ ] 队列与调度服务已重启
- [ ] 健康检查通过（`php artisan health:check`）

---

## 2. 环境要求确认

### 2.1 软件版本

| 组件 | 最低版本 | 推荐版本 | 说明 |
|------|----------|----------|------|
| PHP | 8.2 | 8.3+ | 需扩展：`bcmath ctype curl dom gd mbstring pdo pdo_mysql openssl redis zip fileinfo` |
| Laravel | 12.0 | 12.x | 框架已锁定 |
| MySQL | 8.0 | 8.0+ | 需 `utf8mb4` 字符集，`innodb` 引擎 |
| Redis | 6.0 | 7.0+ | 用于缓存 / 队列 / 限流 |
| Nginx | 1.20 | 1.24+ | 反向代理，需 `fastcgi` 支持 |
| Node.js | 18 | 20+ | 仅前端资源构建需要 |
| Composer | 2.6 | 2.7+ | 依赖管理 |

### 2.2 版本自检命令

```bash
# PHP 版本与扩展
php -v
php -m | grep -E 'bcmath|curl|gd|mbstring|pdo_mysql|redis|zip|fileinfo|openssl'

# Composer
composer --version

# MySQL
mysql --version

# Redis
redis-cli --version

# Nginx
nginx -v
```

### 2.3 系统资源

| 资源 | 最小（测试） | 推荐（生产） |
|------|--------------|--------------|
| CPU | 2 核 | 4 核+ |
| 内存 | 4 GB | 8 GB+ |
| 磁盘 | 40 GB SSD | 100 GB+ SSD |
| 网络 | 5 Mbps | 100 Mbps+ |

### 2.4 目录权限

```bash
# storage 与 bootstrap/cache 需可写
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache
```

---

## 3. 配置项清单

完整配置项见 `.env.example`（按模块分组并附注释）。生产环境需重点核对以下分组：

| 模块 | 关键配置项 | 说明 |
|------|-----------|------|
| **应用基础** | `APP_ENV` `APP_KEY` `APP_DEBUG` `APP_URL` | 生产必须 `APP_DEBUG=false` |
| **数据库** | `DB_*` | 主库连接、连接池上限 |
| **Redis** | `REDIS_HOST` `REDIS_PASSWORD` `REDIS_PORT` | 缓存与队列共用 |
| **队列** | `QUEUE_CONNECTION` `QUEUE_HIGH/DEFAULT/LOW` | 三级队列优先级 |
| **多租户** | `ADMIN_DOMAIN` `TENANCY_ISOLATION_DEFAULT` | 后台域名与隔离策略 |
| **AI 网关** | `AI_DEFAULT_PROVIDER` `AI_OPENAI_API_KEY` 等 | 各提供商密钥 |
| **MFA** | `MFA_ENABLED` `MFA_TOTP_ISSUER` | 多因素认证 |
| **SSO** | `SAML_SP_ENTITY_ID` `SAML_REQUIRE_SIGNED` | SAML 单点登录 |
| **支付** | `WECHAT_PAY_*` `ALIPAY_*` | 支付网关 |
| **OAuth** | `WECHAT/DINGTALK/FEISHU/GITHUB/GOOGLE_*` | 第三方登录 |
| **Webhook** | `WEBHOOK_MAX_RETRIES` `WEBHOOK_QUEUE` | Webhook 投递 |
| **事件总线** | `EVENT_BUS_QUEUE` `EVENT_BUS_MAX_RETRIES` | 异步事件分发 |
| **功能开关** | `FEATURE_FLAG_CACHE_TTL` `FEATURE_FLAG_AUTO_SEED` | 灰度发布 |
| **指标监控** | `RESOURCE_*` `CACHE_HIT_RATE_THRESHOLD` | 资源阈值 |
| **成本追踪** | `COST_DEFAULT_CURRENCY` `COST_FORECAST_MONTHS` | 成本分摊 |
| **通知中心** | `NOTIFICATION_DIGEST_ENABLED` | 通知聚合 |
| **租户加密** | `APP_MASTER_KEY` `TENANT_KEY_CIPHER` | 密钥轮换 |
| **白标** | `BRANDING_*` | 品牌定制 |
| **数据驻留** | `RESIDENCY_DEFAULT_REGION` `RESIDENCY_*_DISK` | 合规区域 |
| **健康检查** | `HEALTH_SLACK_WEBHOOK_URL` `SLA_*` | SLA 监控 |
| **错误追踪** | `SENTRY_LARAVEL_DSN` `SENTRY_SAMPLE_RATE` | Sentry 集成 |

> **安全提醒**：生产环境的 `.env` 文件权限应设为 `640`，且不纳入版本控制。
> ```bash
> chmod 640 .env
> ```

---

## 4. 启动步骤

### 4.1 首次部署

```bash
# 1. 拉取代码
git clone <repo-url> /var/www/saas
cd /var/www/saas
git checkout main

# 2. 安装依赖（生产环境去除 dev 依赖）
composer install --no-dev --optimize-autoloader --no-interaction

# 3. 配置环境
cp .env.example .env
php artisan key:generate
# 编辑 .env：配置 DB / REDIS / ADMIN_DOMAIN / 各模块密钥

# 4. 目录权限
chown -R www-data:www-data storage bootstrap/cache
chmod -R 775 storage bootstrap/cache

# 5. 数据库迁移与填充
php artisan migrate --force
php artisan db:seed --force --class=DatabaseSeeder

# 6. 缓存重建
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
php artisan storage:link

# 7. 启动服务（Docker Compose 示例）
docker-compose up -d
# 或 systemd：
#   systemctl start php8.3-fpm nginx
#   systemctl start saas-queue
#   systemctl start saas-scheduler

# 8. 健康检查
php artisan health:check
curl -s https://ai.lyt.com/api/v1/health | jq
```

### 4.2 日常启动（服务重启）

```bash
# Docker Compose
docker-compose up -d app queue scheduler nginx

# Kubernetes
kubectl rollout restart deploy/saas-app deploy/saas-queue -n saas

# systemd
systemctl restart php8.3-fpm
systemctl restart saas-queue saas-scheduler
```

### 4.3 队列与调度

```bash
# 队列 worker（生产建议用 supervisor / systemd 守护）
php artisan queue:work --queue=high,default,low --sleep=3 --tries=3 --max-time=3600

# 调度器（每分钟执行一次）
php artisan schedule:run
# 或守护进程：
php artisan schedule:work

# Horizon（开发环境监控）
php artisan horizon
```

---

## 5. 健康检查

### 5.1 应用健康

```bash
# HTTP 健康端点
curl -s https://ai.lyt.com/api/v1/health | jq

# Laravel 健康检查（spatie/laravel-health）
php artisan health:check

# 健康检查结果（JSON）
php artisan tinker
>>> app(\MultiTenantSaas\Services\HealthService::class)->summary();
```

### 5.2 健康检查项

| 检查项 | 说明 | 异常处理 |
|--------|------|----------|
| 数据库 | 主库连通性 | 见 [故障应急手册](故障应急手册.md#1-数据库故障) |
| Redis | 缓存连通性 | 见 [故障应急手册](故障应急手册.md#2-redis-故障) |
| 队列 | worker 存活 | 见 [故障应急手册](故障应急手册.md#3-队列积压) |
| 缓存 | 读写正常 | 检查 Redis 连接 |
| 调度器 | 最近运行时间 | 检查 cron / scheduler 守护 |
| 环境 | `APP_ENV=production` | 核对 `.env` |
| 调试模式 | `APP_DEBUG=false` | 核对 `.env` |
| 优化状态 | config/route 已缓存 | 重建缓存 |
| 磁盘空间 | 使用率 < 80% | 见 [故障应急手册](故障应急手册.md#4-磁盘满) |

### 5.3 关键服务状态

```bash
# Docker Compose
docker-compose ps

# Kubernetes
kubectl get pods,deploy,svc,ingress -n saas

# systemd
systemctl status php8.3-fpm nginx saas-queue saas-scheduler
```

### 5.4 队列与调度

```bash
# 队列状态
php artisan horizon:status          # Horizon
php artisan queue:failed            # 失败任务
php artisan queue:failed --queue=default

# 重试失败任务
php artisan queue:retry all
php artisan queue:retry <id>

# 调度任务
php artisan schedule:list
```

---

## 6. 日志管理

### 6.1 日志位置

| 日志 | 路径 | 说明 |
|------|------|------|
| 应用日志 | `storage/logs/laravel.log` | Laravel 默认 |
| AI 日志 | `storage/logs/ai.log` | AI 网关调用 |
| 结构化日志 | `structured_logs` 表 | 带租户/用户上下文 |
| 审计日志 | `audit_logs` 表 | 关键操作审计 |
| 登录日志 | `login_logs` 表 | 登录地理位置/设备 |
| Nginx 访问 | `/var/log/nginx/access.log` | 访问日志 |
| Nginx 错误 | `/var/log/nginx/error.log` | 错误日志 |

### 6.2 日志查看

```bash
# 实时应用日志
tail -f storage/logs/laravel.log

# Docker
docker-compose logs -f app
docker-compose logs -f queue

# Kubernetes
kubectl logs -n saas -l app=saas-app --tail=200 -f

# 按级别过滤
grep -E '"level":(error|critical)' storage/logs/laravel.log
```

### 6.3 日志轮转

```bash
# /etc/logrotate.d/saas
/var/www/saas/storage/logs/*.log {
    daily
    rotate 14
    compress
    missingok
    notifempty
    create 644 www-data www-data
}
```

---

## 7. 缓存运维

```bash
# 清空应用缓存
php artisan cache:clear

# 清空配置/路由/视图缓存（部署前）
php artisan config:clear
php artisan route:clear
php artisan view:clear

# 重建缓存（部署后）
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache

# Redis 直连
redis-cli -h redis-master -a ${REDIS_PASSWORD}
> KEYS tenant:*
> SCARD tenant:domains
> FLUSHDB   # 谨慎，仅非生产
```

---

## 8. 数据库运维

> 备份与恢复见 [备份恢复流程](备份恢复流程.md)。

### 8.1 慢查询排查

```sql
-- 查看慢查询日志
SHOW VARIABLES LIKE 'slow_query_log%';
SHOW VARIABLES LIKE 'long_query_time';

-- 当前运行查询
SHOW PROCESSLIST;

-- 租户隔离索引检查
EXPLAIN SELECT * FROM tenants WHERE tenant_id = 1234567890123456;
```

### 8.2 迁移

```bash
# 生产迁移（强制，不交互）
php artisan migrate --force --pretend   # 预演
php artisan migrate --force             # 执行

# 回滚
php artisan migrate:rollback --step=1 --force
```

---

## 9. 租户运维

### 9.1 租户状态管理

```bash
php artisan tinker

# 暂停租户（清除其全部 Token）
>>> $svc = app(\MultiTenantSaas\Services\TenantService::class);
>>> $svc->suspend(1234567890123456);

# 恢复租户
>>> $svc->activate(1234567890123456);
```

### 9.2 配额与用量

```bash
# 租户配额
>>> app(\MultiTenantSaas\Services\TenantCreditService::class)->getAccount(1234567890123456);

# AI 用量
>>> app(\MultiTenantSaas\Services\AiUsageService::class)->getUsageSummary();
```

---

## 10. 安全运维

### 10.1 依赖漏洞扫描

```bash
composer audit
```

### 10.2 Token 与 API Key 审计

```bash
php artisan tinker

# 吊销某用户全部 Token
>>> $user = \MultiTenantSaas\Models\User::find($userId);
>>> $user->tokens()->delete();

# 查看 API Key（密文）
>>> \MultiTenantSaas\Models\UserApiToken::where('user_id', $userId)->get();
```

### 10.3 IP 白名单

```bash
>>> app(\MultiTenantSaas\Services\IpWhitelistService::class)->list();
```

---

**文档版本**: v2.0.0
