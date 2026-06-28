# TASK-032: 运维手册与发布检查清单

**Sprint:** sprint-008  
**状态:** READY  
**依赖:** TASK-031  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

完成运维手册、备份恢复流程、故障应急预案和发布检查清单，确保生产环境可运维。

---

## 范围

**只允许修改：**
- `docs/deployment/运维手册.md`（新建）
- `docs/deployment/发布检查清单.md`（新建）
- `docs/deployment/备份恢复流程.md`（新建）
- `docs/deployment/故障应急手册.md`（新建）
- `docs/deployment/监控告警配置.md`（新建）
- `.env.example`（追加新配置项）
- `README.md`（更新）

**禁止修改：**
- `.ai/scripts/` 下所有文件
- `.ai/prompts/` 下所有文件
- `src/` 下所有文件
- `app/` 下所有文件
- `database/` 数据库迁移
- `config/` 配置文件
- `routes/` 路由文件
- `resources/` 前端资源
- `public/` 公共入口

---

## 具体内容

### 运维手册

1. 部署检查清单
2. 环境要求确认（PHP/MySQL/Redis/Nginx 版本）
3. 配置项清单
4. 启动步骤
5. 健康检查

### 备份恢复

1. 数据库备份策略（全量/增量）
2. 恢复流程
3. 恢复验证

### 故障应急

1. 常见故障及处理（数据库故障、Redis 故障、队列积压、磁盘满）
2. 灰度发布流程
3. 回滚步骤

### 监控告警

1. 推荐监控指标
2. 告警阈值
3. 通知渠道配置

### .env.example 补全

补全所有新增配置项（AI、MFA、SSO、Webhook、事件总线、功能开关、指标监控、成本追踪、通知中心、隔离、白标等）

---

## 验收标准

- [ ] 运维手册完整
- [ ] 发布检查清单完整
- [ ] 备份恢复流程完整
- [ ] 故障应急手册完整
- [ ] 监控告警配置完整
- [ ] .env.example 补全所有新增配置项
- [ ] README.md 已更新

---

## 给 AI 的补充说明

- 所有文档使用 Markdown 格式
- 运维手册面向运维人员，需可操作
- 发布检查清单为逐项打勾式
- 备份恢复流程包含命令示例
- .env.example 按模块分组，添加注释说明

---

## 全局规范声明

> **⚠ 严格遵守全局约束 — 此部分适用于本任务的所有子任务（a/b/c/d...），无例外**

### 1. 禁止修改的文件

- **`.ai/scripts/` 目录下任何文件**（loop-run.sh、parallel-run.sh、loop-watch.sh、plan-task.sh、lib.sh 等）
- **`.ai/prompts/` 目录下任何文件**（dev-prompt.md、review-prompt.md、plan-prompt.md 等）
- 如 AI 在执行过程中发现需要修改上述文件，应**停止并向用户报告**，而不是自行修改

### 2. 编码规范

- 遵循 **PSR-12** 规范，使用 **Laravel 最佳实践**
- 所有 Controller 必须使用 **API Resource** 返回数据，禁止直接返回模型或数组
- 敏感字段（password/token/secret/key）**永不返回**，手机号脱敏
- 所有方法参数必须有**类型声明**，所有方法必须有**返回值类型声明**
- 使用 PHP 8.1+ 特性（枚举、只读属性等）
- 使用中文注释 + PHPDoc

### 3. 多语言规范

- 使用 `trans()` / `__()` 函数实现多语言，**禁止硬编码中文字符串**
- 新增翻译 key 必须同时添加到 `lang/zh_CN/` 和 `lang/en/` 两个目录

### 4. 数据库规范

- 迁移文件命名接续现有序号（查看 `database/migrations/` 最大序号后 +1）
- 新建模型 use `HasTenantScope` trait 实现租户隔离
- Service 类通过 `TenancyServiceProvider` 注册为 singleton

### 5. 响应格式

- 统一用 `ApiResponse::success()` 和 `ApiResponse::error()`
- 错误码标准化，HTTP 状态码正确

### 6. 测试规范

- 每个新建 Service 必须有对应的 Test 文件
- 测试继承 `tests/TestCase.php`，如需新表 schema 在 TestCase.php 中追加
- `php vendor/bin/phpunit` 全绿（预存在的失败除外，但不得新增失败）
