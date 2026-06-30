# 发布检查清单

**最后更新**: 2026-06-29
**用途**: 每次生产环境发布前逐项打勾确认，全部通过后方可执行发布。

> 配套：[运维手册](运维手册.md) ｜ [故障应急手册](故障应急手册.md)（含回滚步骤）

---

## 一、发布前准备

### 1.1 代码与分支

- [ ] 当前分支为 `main`，已拉取最新代码（`git pull origin main`）
- [ ] 工作区干净，无未提交改动（`git status` 输出为空）
- [ ] 已确认发布版本号 / Tag（`git tag -l "v*"`）
- [ ] CHANGELOG 已更新对应版本条目
- [ ] 已通知相关人员（产品 / 客服 / 运维）发布窗口

### 1.2 依赖与构建

- [ ] `composer install --no-dev --optimize-autoloader --no-interaction` 已执行
- [ ] `composer audit` 无高危漏洞（预存在漏洞已记录）
- [ ] 前端资源已构建（如涉及）：`npm run build`
- [ ] `php artisan storage:link` 已执行

### 1.3 数据库迁移预演

- [ ] `php artisan migrate --pretend --force` 输出已审查
- [ ] 新建迁移文件已确认序号接续（无重复）
- [ ] 危险操作（ DROP / ALTER 大表）已评估执行时间
- [ ] 已确认回滚迁移可用（`migrate:rollback` 测试通过）

### 1.4 配置核对

- [ ] `.env` 中 `APP_ENV=production`
- [ ] `.env` 中 `APP_DEBUG=false`
- [ ] `APP_KEY` 已设置且未泄露
- [ ] 数据库连接配置正确（`DB_HOST` / `DB_DATABASE` / `DB_USERNAME`）
- [ ] Redis 连接配置正确（`REDIS_HOST` / `REDIS_PASSWORD`）
- [ ] `ADMIN_DOMAIN` 已设置并解析
- [ ] 各模块密钥已配置（AI / 支付 / OAuth / SSO 等）
- [ ] `.env` 文件权限 `640`（`chmod 640 .env`）

---

## 二、发布执行

### 2.1 维护模式

- [ ] 已开启维护模式：`php artisan down --message="系统升级中，请稍后" --retry=60`
- [ ] 维护页面可正常访问

### 2.2 部署代码

- [ ] 代码已同步到所有应用节点
- [ ] `composer install --no-dev --optimize-autoloader` 在所有节点执行

### 2.3 数据库迁移

- [ ] `php artisan migrate --force` 执行成功
- [ ] 迁移日志无报错
- [ ] 数据种子已执行（如需）：`php artisan db:seed --force`

### 2.4 缓存重建

- [ ] `php artisan config:cache`
- [ ] `php artisan route:cache`
- [ ] `php artisan view:cache`
- [ ] `php artisan event:cache`
- [ ] `php artisan cache:clear`（清空业务缓存）

### 2.5 重启服务

- [ ] 队列 worker 已重启：`php artisan queue:restart`
- [ ] 应用进程已重启（`docker-compose restart app` / `systemctl restart php-fpm`）
- [ ] 队列进程已重启（`docker-compose restart queue` / `systemctl restart saas-queue`）
- [ ] 调度器已重启（`systemctl restart saas-scheduler`）

---

## 三、发布后验证

### 3.1 健康检查

- [ ] `php artisan health:check` 全部通过
- [ ] `curl -s https://ai.lyt.com/api/v1/health` 返回正常
- [ ] 管理后台 `https://admin.lyt.com` 可访问
- [ ] 租户前台 `https://ai.tenant1.local` 可访问

### 3.2 功能冒烟测试

- [ ] 登录功能正常（管理员 / 租户用户）
- [ ] 租户切换 / 域名识别正常
- [ ] API 接口返回正常（抽样 3-5 个核心接口）
- [ ] 队列任务可正常消费（投递测试任务）
- [ ] 定时任务列表正确：`php artisan schedule:list`

### 3.3 监控确认

- [ ] 错误率无突增（Sentry / 日志无新报错）
- [ ] 响应延迟正常（P95 < 800ms）
- [ ] 队列积压正常（< 1000）
- [ ] 数据库连接数正常（< 80% 上限）

### 3.4 上线维护模式

- [ ] 全部验证通过后关闭维护模式：`php artisan up`
- [ ] 确认站点恢复正常访问

---

## 四、回滚预案

> 若发布后验证失败，立即执行回滚，详见 [故障应急手册 - 回滚步骤](故障应急手册.md#6-回滚步骤)。

- [ ] 回滚决策已确认（影响范围评估）
- [ ] 代码回滚：`git revert <commit>` 或 `git reset --hard <prev-tag>`
- [ ] 数据库回滚：`php artisan migrate:rollback --step=N --force`
- [ ] 缓存重建 + 服务重启
- [ ] 回滚后健康检查通过
- [ ] 回滚事件已记录并通知团队

---

## 五、发布记录

| 项目 | 内容 |
|------|------|
| 发布版本 | v______ |
| 发布时间 | ______ |
| 执行人 | ______ |
| 变更摘要 | ______ |
| 迁移文件 | ______ |
| 回滚是否执行 | 是 / 否 |
| 备注 | ______ |

---

**文档版本**: v1.0.0
