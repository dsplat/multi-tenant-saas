# 备份恢复流程

**最后更新**: 2026-06-29
**用途**: 定义数据库备份策略、恢复流程与恢复验证标准，确保数据可恢复。

> 配套：[运维手册](运维手册.md) ｜ [故障应急手册](故障应急手册.md)

---

## 1. 备份策略

### 1.1 备份对象

| 对象 | 备份方式 | 频率 | 保留时长 |
|------|----------|------|----------|
| MySQL 主库 | 全量 + 增量 | 见下文 | 30 天 |
| Redis | RDB 快照 | 每日 | 7 天 |
| 应用 storage | 文件归档 | 每日 | 30 天 |
| `.env` 配置 | 加密归档 | 每次变更 | 永久 |
| Nginx 配置 | 版本控制 | 每次变更 | 永久 |

### 1.2 MySQL 备份策略

| 类型 | 频率 | 时间 | 保留 | 说明 |
|------|------|------|------|------|
| 全量备份 | 每日 | 03:00 | 30 天 | `mysqldump` 或 `xtrabackup` |
| 增量备份 | 每 6 小时 | 06/12/18/24 点 | 7 天 | binlog 或 `xtrabackup --incremental` |
| 周度全量 | 每周日 | 02:00 | 90 天 | 完整物理备份 |

### 1.3 备份存储

- **本地**：保留最近 3 天备份（快速恢复）
- **异地**：所有备份同步至对象存储（OSS / S3），保留 30 天
- **加密**：异地备份使用 AES-256 加密传输与存储

---

## 2. 备份操作

### 2.1 全量备份（mysqldump）

```bash
#!/bin/bash
# 全量备份脚本：/opt/saas/scripts/backup-full.sh

BACKUP_DIR="/data/backups/mysql"
DB_NAME="saas_production"
DB_USER="root"
DB_PASS="${DB_PASSWORD}"
DATE=$(date +%Y%m%d_%H%M%S)
BACKUP_FILE="${BACKUP_DIR}/${DB_NAME}_full_${DATE}.sql.gz"

# 创建备份目录
mkdir -p ${BACKUP_DIR}

# 全量导出（单事务保证一致性）
mysqldump \
  --single-transaction \
  --routines \
  --triggers \
  --events \
  --set-gtid-purged=OFF \
  -u${DB_USER} -p${DB_PASS} ${DB_NAME} | gzip > ${BACKUP_FILE}

# 校验
if [ $? -eq 0 ]; then
  echo "[$(date)] 全量备份成功: ${BACKUP_FILE}"
  # 同步到对象存储
  aws s3 cp ${BACKUP_FILE} s3://saas-backups/mysql/ --sse AES256
else
  echo "[$(date)] 全量备份失败" >&2
  exit 1
fi

# 清理本地旧备份（保留 3 天）
find ${BACKUP_DIR} -name "${DB_NAME}_full_*.sql.gz" -mtime +3 -delete
```

### 2.2 增量备份（binlog）

```bash
#!/bin/bash
# 增量备份脚本：/opt/saas/scripts/backup-incremental.sh

BINLOG_DIR="/data/backups/binlog"
MYSQL_DATA="/var/lib/mysql"
DB_PASS="${DB_PASSWORD}"

mkdir -p ${BINLOG_DIR}

# 刷新 binlog 生成新文件
mysql -u root -p${DB_PASS} -e "FLUSH BINARY LOGS;"

# 复制新的 binlog 到备份目录
cp ${MYSQL_DATA}/mysql-bin.* ${BINLOG_DIR}/

# 同步到对象存储
aws s3 sync ${BINLOG_DIR}/ s3://saas-backups/binlog/ --sse AES256

# 清理 7 天前的 binlog 备份
find ${BINLOG_DIR} -name "mysql-bin.*" -mtime +7 -delete
```

### 2.3 Kubernetes 环境备份

```bash
# 从 Pod 导出
kubectl exec -n saas saas-mysql-0 -- \
  mysqldump --single-transaction -u root -p${DB_PASSWORD} saas_production \
  > backup_$(date +%Y%m%d).sql

# 或使用 Velero 备份 PV
velero backup create saas-mysql-$(date +%Y%m%d) \
  --include-namespaces saas \
  --include-resources persistentvolumeclaims,persistentvolumes
```

### 2.4 Redis 备份

```bash
# 手动触发 RDB 快照
redis-cli -a ${REDIS_PASSWORD} BGSAVE

# 等待完成
redis-cli -a ${REDIS_PASSWORD} LASTSAVE

# 复制 RDB 文件
cp /var/lib/redis/dump.rdb /data/backups/redis/dump_$(date +%Y%m%d).rdb
aws s3 cp /data/backups/redis/dump_$(date +%Y%m%d).rdb s3://saas-backups/redis/
```

### 2.5 定时任务（crontab）

```cron
# /var/spool/cron/www-data
0 3 * * * /opt/saas/scripts/backup-full.sh >> /var/log/saas-backup.log 2>&1
0 */6 * * * /opt/saas/scripts/backup-incremental.sh >> /var/log/saas-backup.log 2>&1
0 4 * * * /opt/saas/scripts/backup-redis.sh >> /var/log/saas-backup.log 2>&1
```

---

## 3. 恢复流程

### 3.1 恢复前准备

1. **确认故障范围**：确定需要恢复的时间点（PITR）或最近全量
2. **停止写入**：将应用切到维护模式，停止队列 worker
   ```bash
   php artisan down --message="数据恢复中" --retry=60
   php artisan queue:restart
   ```
3. **备份当前数据**：恢复前先对当前（损坏）数据库做一次快照，防止误操作
   ```bash
   mysqldump -u root -p${DB_PASSWORD} saas_production > /tmp/saas_before_restore_$(date +%s).sql
   ```

### 3.2 全量恢复

```bash
# 1. 解压并恢复全量备份
gunzip < /data/backups/mysql/saas_production_full_20260629_030000.sql.gz \
  | mysql -u root -p${DB_PASSWORD} saas_production

# 2. 验证恢复（见第 4 节）
```

### 3.3 增量恢复（PITR - 时间点恢复）

```bash
# 1. 先恢复最近的全量备份（见 3.2）

# 2. 应用 binlog 到指定时间点
#    STOP-DATETIME 为目标恢复时间
mysqlbinlog \
  --stop-datetime="2026-06-29 10:30:00" \
  /data/backups/binlog/mysql-bin.000123 \
  /data/backups/binlog/mysql-bin.000124 \
  | mysql -u root -p${DB_PASSWORD} saas_production

# 3. 验证恢复（见第 4 节）
```

### 3.4 Kubernetes 环境恢复

```bash
# 从备份文件恢复
kubectl cp backup_20260629.sql saas/saas-mysql-0:/tmp/backup.sql
kubectl exec -n saas saas-mysql-0 -- \
  mysql -u root -p${DB_PASSWORD} saas_production < /tmp/backup.sql

# 或使用 Velero 恢复 PV
velero restore create --from-backup saas-mysql-20260629
```

### 3.5 Redis 恢复

```bash
# 1. 停止 Redis
systemctl stop redis
# 或 docker-compose stop redis

# 2. 替换 RDB 文件
cp /data/backups/redis/dump_20260629.rdb /var/lib/redis/dump.rdb
chown redis:redis /var/lib/redis/dump.rdb

# 3. 启动 Redis
systemctl start redis
# 或 docker-compose start redis

# 4. 验证
redis-cli -a ${REDIS_PASSWORD} INFO keyspace
```

### 3.6 恢复后操作

```bash
# 1. 清空应用缓存（避免脏数据）
php artisan cache:clear
php artisan config:cache
php artisan route:cache

# 2. 重启队列 worker
php artisan queue:restart
docker-compose restart queue

# 3. 关闭维护模式
php artisan up
```

---

## 4. 恢复验证

### 4.1 数据完整性校验

```bash
# 1. 表数量与行数核对
mysql -u root -p${DB_PASSWORD} saas_production -e "
  SELECT TABLE_NAME, TABLE_ROWS
  FROM information_schema.TABLES
  WHERE TABLE_SCHEMA = 'saas_production'
  ORDER BY TABLE_NAME;
"

# 2. 关键表抽样核对
mysql -u root -p${DB_PASSWORD} saas_production -e "
  SELECT COUNT(*) AS tenants FROM tenants;
  SELECT COUNT(*) AS users FROM users;
  SELECT COUNT(*) AS orders FROM orders;
"

# 3. 最近时间戳核对（确认恢复到了目标时间点）
mysql -u root -p${DB_PASSWORD} saas_production -e "
  SELECT MAX(created_at) AS latest_record FROM audit_logs;
"
```

### 4.2 应用层验证

- [ ] 健康检查通过：`php artisan health:check`
- [ ] 管理后台可登录
- [ ] 租户前台可访问
- [ ] 核心业务接口返回正常
- [ ] 队列可正常消费
- [ ] 缓存命中率正常

### 4.3 恢复报告

| 项目 | 内容 |
|------|------|
| 恢复原因 | ______ |
| 恢复时间点 | ______ |
| 使用的备份文件 | ______ |
| 数据丢失范围 | ______ |
| 恢复耗时 | ______ |
| 验证结果 | 通过 / 失败 |
| 执行人 | ______ |
| 备注 | ______ |

---

## 5. 备份恢复演练

> 建议每季度执行一次恢复演练，验证备份可用性。

1. 在隔离环境（staging）执行全量恢复
2. 模拟时间点恢复（PITR）
3. 执行第 4 节验证流程
4. 记录演练报告，更新本流程

---

**文档版本**: v1.0.0
