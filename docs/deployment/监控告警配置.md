# 监控告警配置

**最后更新**: 2026-06-29
**用途**: 定义生产环境推荐监控指标、告警阈值与通知渠道配置，确保故障可发现、可告警。

> 配套：[运维手册](运维手册.md) ｜ [故障应急手册](故障应急手册.md)

---

## 1. 监控架构

```
┌──────────────┐   ┌──────────────┐   ┌──────────────┐
│  应用层指标   │   │  基础设施指标 │   │  业务指标     │
│ (Laravel/PHP)│   │ (MySQL/Redis) │   │ (租户/计费)   │
└──────┬───────┘   └──────┬───────┘   └──────┬───────┘
       │                  │                  │
       └──────────┬───────┴──────────┬───────┘
                  │                  │
          ┌───────▼────────┐  ┌──────▼────────┐
          │  Prometheus    │  │  Sentry       │
          │  (指标采集)     │  │  (错误追踪)    │
          └───────┬────────┘  └──────┬────────┘
                  │                  │
          ┌───────▼──────────────────▼───────┐
          │         Alertmanager              │
          │         (告警路由与抑制)            │
          └───────┬───────────────────────────┘
                  │
     ┌────────────┼────────────┐
     │            │            │
  Slack         邮件         短信/电话
```

### 1.1 监控组件

| 组件 | 用途 | 部署方式 |
|------|------|----------|
| spatie/laravel-health | 应用健康检查 | 框架内置 |
| Sentry | 错误追踪与聚合 | `SENTRY_LARAVEL_DSN` |
| Prometheus + Grafana | 指标采集与可视化 | 独立部署 |
| Alertmanager | 告警路由 | 独立部署 |
| Horizon | 队列监控（开发） | `/horizon` |

---

## 2. 推荐监控指标

### 2.1 应用层指标

| 指标 | 采集方式 | 说明 |
|------|----------|------|
| HTTP 请求量(QPS) | Nginx 日志 / Prometheus | 按 status code 分维度 |
| HTTP 5xx 错误率 | Nginx 日志 | 5xx / 总请求 |
| API P95 / P99 延迟 | APM / 中间件 | 按路由分组 |
| PHP-FPM 进程数 | php-fpm status | active / idle |
| PHP 内存使用 | php-fpm status | peak memory |
| Octane 内存泄漏 | 进程 RSS | 长期增长告警 |

### 2.2 数据库指标

| 指标 | 采集方式 | 说明 |
|------|----------|------|
| 连接数 | `SHOW STATUS LIKE 'Threads_connected'` | 占 max_connections 比例 |
| 慢查询数 | slow_query_log | 每分钟新增数 |
| 主从延迟 | `SHOW SLAVE STATUS` | Seconds_Behind_Master |
| 查询吞吐 QPS | `SHOW STATUS LIKE 'Questions'` | 每秒查询数 |
| 表锁等待 | `SHOW STATUS LIKE 'Table_locks_waited'` | 锁竞争 |

### 2.3 Redis 指标

| 指标 | 采集方式 | 说明 |
|------|----------|------|
| 内存使用 | `INFO memory` | used_memory / max_memory |
| 命中率 | `INFO stats` | keyspace_hits / total |
| 连接数 | `INFO clients` | connected_clients |
| 命令延迟 | `--latency` | P99 延迟 |
| 主从同步 | `INFO replication` | offset 差值 |

### 2.4 队列指标

| 指标 | 采集方式 | 说明 |
|------|----------|------|
| 队列积压 | ` Horizon` / Redis LLEN | 待处理任务数 |
| 失败任务数 | `failed_jobs` 表 | 累计与新增 |
| 任务执行耗时 | Horizon / 日志 | P95 执行时间 |
| Worker 存活 | 进程监控 | 存活 worker 数 |

### 2.5 基础设施指标

| 指标 | 采集方式 | 说明 |
|------|----------|------|
| CPU 使用率 | node_exporter | 1/5/15 分钟 |
| 内存使用率 | node_exporter | used / total |
| 磁盘使用率 | node_exporter | 各挂载点 |
| 磁盘 IO | node_exporter | read/write IOPS |
| 网络流量 | node_exporter | 入/出带宽 |
| 磁盘 inode | node_exporter | inode 使用率 |

### 2.6 业务指标

| 指标 | 采集方式 | 说明 |
|------|----------|------|
| 活跃租户数 | 业务表统计 | status=active |
| 租户配额用量 | `TenantCreditService` | 占比 > 80% 告警 |
| AI 调用量 | `ai_requests` 表 | token / 张 / 秒 |
| 支付成功率 | 支付日志 | 失败率 > 5% 告警 |
| 登录失败率 | `login_logs` 表 | 暴力破解检测 |

---

## 3. 告警阈值

### 3.1 严重告警（立即通知，P0/P1）

| 指标 | 阈值 | 持续时间 | 级别 | 通知方式 |
|------|------|----------|------|----------|
| 站点不可达 | 连续 3 次健康检查失败 | 1 分钟 | P0 | 电话 + 短信 + Slack |
| HTTP 5xx 错误率 | > 5% | 2 分钟 | P0 | 电话 + 短信 + Slack |
| MySQL 主库不可达 | 连接失败 | 1 分钟 | P0 | 电话 + 短信 + Slack |
| Redis 不可达 | 连接失败 | 1 分钟 | P0 | 电话 + 短信 + Slack |
| 磁盘使用率 | > 95% | 即时 | P0 | 电话 + 短信 + Slack |
| 数据丢失 / 损坏 | 检测到 | 即时 | P0 | 电话 + 短信 + Slack |

### 3.2 警告告警（30 分钟内处理，P2）

| 指标 | 阈值 | 持续时间 | 级别 | 通知方式 |
|------|------|----------|------|----------|
| HTTP 5xx 错误率 | > 1% | 5 分钟 | P2 | 短信 + Slack |
| API P95 延迟 | > 800ms | 5 分钟 | P2 | 短信 + Slack |
| 队列积压 | > 1000 任务 | 5 分钟 | P2 | 短信 + Slack |
| MySQL 连接数 | > 80% max | 5 分钟 | P2 | 短信 + Slack |
| MySQL 主从延迟 | > 30 秒 | 5 分钟 | P2 | 短信 + Slack |
| Redis 内存使用 | > 80% | 10 分钟 | P2 | 短信 + Slack |
| Redis 命中率 | < 80% | 10 分钟 | P2 | 短信 + Slack |
| 磁盘使用率 | > 85% | 30 分钟 | P2 | 短信 + Slack |
| CPU 使用率 | > 85% | 10 分钟 | P2 | 短信 + Slack |
| 内存使用率 | > 90% | 10 分钟 | P2 | 短信 + Slack |
| 队列失败率 | > 5% | 5 分钟 | P2 | 短信 + Slack |
| 支付失败率 | > 5% | 5 分钟 | P2 | 短信 + Slack |

### 3.3 提示告警（记录工单，P3）

| 指标 | 阈值 | 持续时间 | 级别 | 通知方式 |
|------|------|----------|------|----------|
| 租户配额用量 | > 80% | 即时 | P3 | Slack + 邮件 |
| 慢查询数 | > 50/分钟 | 10 分钟 | P3 | Slack |
| SSL 证书到期 | < 30 天 | 每日检查 | P3 | 邮件 |
| Sentry 新增错误 | 单类 > 100 次 | 1 小时 | P3 | Slack |
| 调度任务未执行 | 超时未运行 | 10 分钟 | P3 | Slack |

---

## 4. 通知渠道配置

### 4.1 Slack

```bash
# .env 配置
HEALTH_SLACK_WEBHOOK_URL=https://hooks.slack.com/services/YOUR/WEBHOOK/URL
```

Alertmanager Slack 配置（`alertmanager.yml`）：
```yaml
route:
  group_by: ['alertname', 'severity']
  group_wait: 30s
  group_interval: 5m
  repeat_interval: 4h
  receiver: 'slack'
  routes:
    - match:
        severity: critical
      receiver: 'slack-critical'
      continue: true
    - match:
        severity: warning
      receiver: 'slack-warning'

receivers:
  - name: 'slack'
    slack_configs:
      - api_url: 'https://hooks.slack.com/services/...'
        channel: '#saas-alerts'
  - name: 'slack-critical'
    slack_configs:
      - api_url: 'https://hooks.slack.com/services/...'
        channel: '#saas-critical'
  - name: 'slack-warning'
    slack_configs:
      - api_url: 'https://hooks.slack.com/services/...'
        channel: '#saas-alerts'
```

### 4.2 邮件

```bash
# .env 配置
HEALTH_MAIL_TO=ops@example.com
MAIL_MAILER=smtp
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=alert@example.com
MAIL_PASSWORD=
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=alert@example.com
MAIL_FROM_NAME="${APP_NAME} Monitor"
```

### 4.3 Sentry 错误追踪

```bash
# .env 配置
SENTRY_ENABLED=true
SENTRY_LARAVEL_DSN=https://xxxxxxxx@oXXXXXX.ingest.sentry.io/XXX
SENTRY_SAMPLE_RATE=1.0
SENTRY_ENVIRONMENT=production
```

### 4.4 短信 / 电话（P0 升级）

通过 Alertmanager webhook 触发第三方告警平台（如 PagerDuty / 阿里云监控 / 腾讯云监控）：

```yaml
receivers:
  - name: 'phone'
    webhook_configs:
      - url: 'https://events.pagerduty.com/integration/XXX/enqueue'
        send_resolved: true
```

---

## 5. 告警抑制与静默

### 5.1 抑制规则（避免告警风暴）

```yaml
# alertmanager.yml
inhibit_rules:
  # MySQL 宕机时抑制其衍生告警
  - source_match:
      alertname: 'MysqlDown'
    target_match_re:
      alertname: 'MysqlSlowQueries|MysqlConnectionsHigh'
    equal: ['instance']
  # 主机宕机时抑制该主机所有告警
  - source_match:
      alertname: 'HostDown'
    target_match_re:
      alertname: '.*'
    equal: ['instance']
```

### 5.2 维护期静默

```bash
# 发布期间静默告警（Alertmanager API）
curl -X POST http://alertmanager:9093/api/v2/silences \
  -H 'Content-Type: application/json' \
  -d '{
    "matchers": [{"name": "environment", "value": "production", "isRegex": false}],
    "startsAt": "2026-06-29T10:00:00Z",
    "endsAt": "2026-06-29T11:00:00Z",
    "createdBy": "ops",
    "comment": "计划内发布"
  }'
```

---

## 6. 应用内告警系统

框架内置 `AlertService` 与 `PerformanceService`，可通过 tinker 查看：

```bash
php artisan tinker

# 查看告警规则
>>> app(\MultiTenantSaas\Services\AlertService::class)->getRules();

# 查看触发的告警
>>> app(\MultiTenantSaas\Services\AlertService::class)->getActiveAlerts();

# 查看性能指标
>>> app(\MultiTenantSaas\Services\PerformanceService::class)->getMetrics();
```

资源监控阈值在 `.env` 中配置：

```bash
RESOURCE_DB_CONN_THRESHOLD=100        # 数据库连接数告警阈值
RESOURCE_QUEUE_THRESHOLD=1000         # 队列积压告警阈值
CACHE_HIT_RATE_THRESHOLD=80.0         # 缓存命中率告警阈值（%）
STORAGE_USAGE_THRESHOLD_MB=10240      # 存储用量告警阈值（MB）
```

---

## 7. 仪表盘建议

Grafana 推荐仪表盘：

| 仪表盘 | 包含面板 | 数据源 |
|--------|----------|--------|
| 应用总览 | QPS / 5xx 率 / P95 延迟 / 活跃租户 | Prometheus |
| 数据库 | 连接数 / QPS / 慢查询 / 主从延迟 | MySQL Exporter |
| Redis | 内存 / 命中率 / 连接数 / 命令延迟 | Redis Exporter |
| 队列 | 积压 / 失败数 / 执行耗时 / Worker 数 | Horizon / Redis |
| 基础设施 | CPU / 内存 / 磁盘 / 网络 / IO | Node Exporter |
| 业务 | 租户增长 / AI 用量 / 支付成功率 | 业务 API |

---

**文档版本**: v1.0.0
