# 故障应急手册

**最后更新**: 2026-06-29
**用途**: 定义常见生产故障的应急处理流程、灰度发布规范与回滚步骤，确保故障快速恢复。

> 配套：[运维手册](运维手册.md) ｜ [发布检查清单](发布检查清单.md) ｜ [监控告警配置](监控告警配置.md)

---

## 故障响应级别

| 级别 | 定义 | 响应时间 | 升级 |
|------|------|----------|------|
| **P0 严重** | 全站不可用 / 数据丢失 | 立即 | 通知 CTO + 全员 |
| **P1 高** | 核心功能不可用 / 多租户受影响 | 15 分钟 | 通知运维负责人 |
| **P2 中** | 单租户 / 非核心功能异常 | 30 分钟 | 通知值班运维 |
| **P3 低** | 性能下降 / 体验问题 | 2 小时 | 记录工单 |

---

## 1. 数据库故障

### 1.1 主库不可达

**现象**：应用大面积 500 错误，健康检查数据库项失败。

**排查**：
```bash
# 1. 检查连通性
mysql -h ${DB_HOST} -u ${DB_USERNAME} -p -e "SELECT 1;"

# 2. 检查进程
docker-compose ps mysql
kubectl get pod -n saas -l app=mysql

# 3. 检查 MySQL 日志
docker-compose logs --tail=200 mysql
# 或
tail -200 /var/log/mysql/error.log

# 4. 检查磁盘（磁盘满会导致 MySQL 崩溃）
df -h /var/lib/mysql
```

**处理**：
```bash
# 情况 A：MySQL 进程崩溃 -> 重启
docker-compose restart mysql
# 或
systemctl restart mysqld

# 情况 B：主库硬件故障 -> 切换从库
# 1. 提升从库为主库
mysql -h slave-host -u root -p -e "STOP SLAVE; RESET SLAVE ALL; SET GLOBAL read_only=OFF;"
# 2. 更新 .env DB_HOST 指向新主库
# 3. 重启应用
docker-compose restart app queue

# 情况 C：磁盘满 -> 见第 4 节
```

### 1.2 连接耗尽

**现象**：`SQLSTATE[08004] Too many connections`。

**排查**：
```sql
-- 查看连接数
SHOW STATUS LIKE 'Threads_connected';
SHOW VARIABLES LIKE 'max_connections';

-- 查看活跃连接来源
SHOW PROCESSLIST;
-- 杀掉长事务
KILL <id>;
```

**处理**：
```bash
# 临时调大连接数
mysql -u root -p -e "SET GLOBAL max_connections = 500;"

# 永久调整：修改 my.cnf
# [mysqld]
# max_connections = 500

# 重启应用释放连接
docker-compose restart app
```

### 1.3 慢查询拖垮

**现象**：接口响应缓慢，CPU / IO 飙升。

**处理**：
```sql
-- 查看当前慢查询
SHOW PROCESSLIST;
-- 杀掉耗时查询
KILL <id>;

-- 检查缺失索引
EXPLAIN SELECT ... FROM <table> WHERE tenant_id = ?;
```

```bash
# 开启慢查询日志（临时）
mysql -u root -p -e "
  SET GLOBAL slow_query_log = 'ON';
  SET GLOBAL long_query_time = 1;
"
```

---

## 2. Redis 故障

### 2.1 Redis 不可达

**现象**：缓存失效，请求穿透到数据库，响应变慢。

**排查**：
```bash
# 1. 检查连通性
redis-cli -h ${REDIS_HOST} -a ${REDIS_PASSWORD} ping

# 2. 检查进程
docker-compose ps redis
kubectl get pod -n saas -l app=redis

# 3. 检查日志
docker-compose logs --tail=200 redis
```

**处理**：
```bash
# 重启 Redis
docker-compose restart redis
# 或
systemctl restart redis

# 临时切换缓存驱动到 database（降级）
# 修改 .env: CACHE_STORE=database
php artisan config:cache
docker-compose restart app
```

### 2.2 Redis 内存满

**现象**：`OOM command not allowed when used memory > maxmemory`。

**处理**：
```bash
# 查看内存使用
redis-cli -a ${REDIS_PASSWORD} INFO memory

# 查看内存策略
redis-cli -a ${REDIS_PASSWORD} CONFIG GET maxmemory-policy

# 手动清理过期/低频 key
redis-cli -a ${REDIS_PASSWORD} --scan --pattern 'session:*' | head -1000 | xargs redis-cli del

# 调大 maxmemory
redis-cli -a ${REDIS_PASSWORD} CONFIG SET maxmemory 2gb
```

---

## 3. 队列积压

**现象**：异步任务（通知 / 导出 / Webhook）延迟，队列积压超阈值。

**排查**：
```bash
# 1. 查看积压
php artisan horizon:status          # Horizon
php artisan queue:failed            # 失败任务

# 2. 查看 worker 状态
docker-compose ps queue
kubectl get pod -n saas -l app=saas-queue

# 3. 查看 worker 日志
docker-compose logs --tail=200 queue
```

**处理**：
```bash
# 情况 A：worker 崩溃 -> 重启
docker-compose restart queue
kubectl rollout restart deploy/saas-queue -n saas

# 情况 B：worker 不足 -> 横向扩容
docker-compose up -d --scale queue=4
kubectl scale deploy/saas-queue --replicas=8 -n saas

# 情况 C：大量失败任务 -> 排查失败原因后批量重试
php artisan queue:retry all

# 情况 D：死信任务 -> 手动处理
php artisan queue:flush   # 清空失败任务（谨慎）
```

---

## 4. 磁盘满

**现象**：写入失败，日志报 `No space left on device`，MySQL / Redis 可能崩溃。

**排查**：
```bash
# 1. 查看磁盘使用
df -h

# 2. 定位大文件
du -sh /var/www/saas/storage/* | sort -rh | head -20
du -sh /var/log/* | sort -rh | head -20
du -sh /var/lib/mysql/* | sort -rh | head -20
```

**处理**：
```bash
# 1. 清理旧日志
find /var/www/saas/storage/logs -name "*.log" -mtime +7 -delete
# 或手动截断大日志（不删除，保留句柄）
truncate -s 0 /var/www/saas/storage/logs/laravel.log

# 2. 清理 MySQL binlog（确认已备份后）
mysql -u root -p -e "PURGE BINARY LOGS BEFORE NOW() - INTERVAL 3 DAY;"

# 3. 清理 Docker 无用资源
docker system prune -af --volumes

# 4. 清理临时文件
rm -rf /tmp/saas-*

# 5. 扩容磁盘（长期方案）
# 云平台在线扩容磁盘
```

---

## 5. 灰度发布流程

### 5.1 灰度策略

| 方式 | 适用场景 | 实现手段 |
|------|----------|----------|
| **功能开关** | 新功能灰度 | `FeatureFlagService` 按 `rollout_percentage` 灰度 |
| **租户灰度** | 指定租户先行 | 按租户 ID 哈希 / 白名单租户 |
| **流量灰度** | 按比例放量 | Nginx `split_clients` / K8s Canary |
| **Canary 部署** | 新版本灰度 | K8s 多 Deployment + Service 路由 |

### 5.2 功能开关灰度

```bash
php artisan tinker

# 1. 创建灰度开关（0% 放量）
>>> $svc = app(\MultiTenantSaas\Services\FeatureFlagService::class);
>>> $svc->create([
...     'name' => 'new_dashboard',
...     'description' => '新版控制台',
...     'scope' => 'tenant',
...     'status' => 'active',
...     'rollout_percentage' => 0,
... ]);

# 2. 逐步放量：10% -> 30% -> 50% -> 100%
>>> $svc->updateRollout('new_dashboard', 10);
>>> $svc->updateRollout('new_dashboard', 30);
>>> $svc->updateRollout('new_dashboard', 100);

# 3. 指定租户白名单先行
>>> $svc->enableForTenant('new_dashboard', 1234567890123456);
```

### 5.3 Kubernetes Canary 部署

```bash
# 1. 部署 Canary 版本（10% 副本）
kubectl set image deploy/saas-app app=saas:v2.1.0-canary -n saas
kubectl scale deploy/saas-app --replicas=1 -n saas  # canary
# 主版本保持 9 个副本

# 2. 通过 Service 级联路由（Istio / Nginx Ingress canary-weight）
kubectl annotate ingress saas-ingress \
  nginx.ingress.kubernetes.io/canary-weight="10" -n saas

# 3. 观察 30 分钟，无异常则全量
kubectl set image deploy/saas-app app=saas:v2.1.0 -n saas
kubectl scale deploy/saas-app --replicas=10 -n saas
kubectl annotate ingress saas-ingress nginx.ingress.kubernetes.io/canary-weight- -n saas
```

### 5.4 灰度观察指标

灰度期间持续观察以下指标，异常立即回滚：

- HTTP 5xx 错误率（< 1%）
- API P95 延迟（< 800ms）
- 队列失败率
- Sentry 新增错误数
- 业务核心转化率

---

## 6. 回滚步骤

### 6.1 回滚决策

满足以下任一条件立即回滚：

- 灰度期间 5xx 错误率 > 5%
- 核心功能不可用且 15 分钟内无法修复
- 数据出现异常（脏数据 / 丢失）
- 响应延迟 P95 > 2s 持续 5 分钟

### 6.2 代码回滚

```bash
# 1. 开启维护模式
php artisan down --message="回滚中，请稍后" --retry=60

# 2. 回滚代码到上一稳定版本
# 方式 A：revert 最新提交
git revert HEAD --no-edit

# 方式 B：reset 到上一 Tag
git fetch --tags
git checkout v2.0.0   # 上一稳定版本

# 3. 重新安装依赖
composer install --no-dev --optimize-autoloader --no-interaction

# 4. 重建缓存
php artisan config:cache
php artisan route:cache
php artisan view:cache
php artisan event:cache
```

### 6.3 数据库回滚

```bash
# 1. 回滚迁移（按步数）
php artisan migrate:rollback --step=1 --force

# 2. 如需回滚多步
php artisan migrate:rollback --step=3 --force

# 3. 如需恢复数据（从备份）
# 见 备份恢复流程.md 第 3 节
```

### 6.4 重启服务

```bash
# 1. 重启队列
php artisan queue:restart
docker-compose restart app queue scheduler

# 或
kubectl rollout restart deploy/saas-app deploy/saas-queue -n saas

# 2. 关闭维护模式
php artisan up

# 3. 健康检查
php artisan health:check
curl -s https://ai.lyt.com/api/v1/health | jq
```

### 6.5 回滚后动作

- [ ] 确认站点恢复正常访问
- [ ] 确认队列正常消费
- [ ] 通知团队回滚完成 + 原因
- [ ] 创建事故复盘文档，记录根因与改进措施
- [ ] 修复问题后在 staging 验证，再择机重新发布

---

## 7. 联系矩阵

| 角色 | 职责 | 联系方式 |
|------|------|----------|
| 运维值班 | 一线响应 | ______ |
| 运维负责人 | P0/P1 升级 | ______ |
| 后端负责人 | 代码级排查 | ______ |
| DBA | 数据库操作 | ______ |
| 产品负责人 | 业务影响评估 | ______ |
| CTO | P0 升级 | ______ |

---

**文档版本**: v1.0.0
