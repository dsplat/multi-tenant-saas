# TASK-027: 数据库级隔离

**Sprint:** sprint-007  
**状态:** READY  
**依赖:** 无  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

实现数据库级隔离策略（共享数据库/独立数据库/独立 Schema），支持租户创建时自动初始化和迁移工具。

---

## 范围

**只允许修改：**
- `src/Services/IsolationService.php`（新建）
- `src/Contracts/IsolationStrategyContract.php`（新建）
- `src/Isolation/SharedDatabaseStrategy.php`（新建）
- `src/Isolation/DatabasePerTenantStrategy.php`（新建）
- `src/Isolation/SchemaPerTenantStrategy.php`（新建）
- `src/Context/TenantContext.php`（追加隔离策略）
- `config/tenancy.php`（追加隔离配置）
- `database/migrations/` 下新增 tenant_isolation 迁移（Tenant 表追加 isolation_type、database_name、schema_name 字段）
- `lang/zh_CN/tenant.php`、`lang/en/tenant.php`（追加翻译 key）
- `tests/IsolationServiceTest.php`（新建）
- `tests/TestCase.php`（追加新字段 schema）

**禁止修改：**
- `.ai/scripts/` 下所有文件
- `.ai/prompts/` 下所有文件
- `app/` 应用层代码
- `resources/` 前端资源
- `public/` 公共入口
- `src/` 下除上述允许文件外的其他文件

---

## 具体内容

### IsolationStrategyContract

统一接口：`getConnection($tenant)`、`setupDatabase($tenant)`、`teardownDatabase($tenant)`、`migrate($tenant)`

### SharedDatabaseStrategy

当前已有策略（共享数据库 + TenantScope 行级隔离），封装为策略实现

### DatabasePerTenantStrategy

每个租户独立数据库，动态切换 connection，自动创建/迁移数据库

### SchemaPerTenantStrategy

每个租户独立 Schema（PostgreSQL），动态切换 search_path。**注: 仅适用于 PostgreSQL，MySQL 8.0 不支持 Schema 级隔离**。如项目仅用 MySQL，可省略或保留为未来 PG 迁移预留接口

### IsolationService

1. 策略选择
2. 租户创建时自动初始化
3. 租户删除时清理
4. 连接池管理
5. 迁移工具：`IsolationService::migrate($tenantId, $fromStrategy, $toStrategy)` — 将现有租户从 shared 迁移到 database-per-tenant（导出 → 创建库 → 迁移 → 导入 → 切换连接 → 验证）

### 数据模型

Tenant 表追加：`isolation_type`(shared/database/schema)、`database_name`、`schema_name`

---

## 验收标准

- [ ] SharedDatabaseStrategy 封装正常
- [ ] DatabasePerTenantStrategy 独立库创建/切换/迁移正常
- [ ] IsolationService 策略选择正常
- [ ] 租户创建时自动初始化正常
- [ ] 租户删除时清理正常
- [ ] 迁移工具（shared → database-per-tenant）正常
- [ ] TestCase 追加新字段，phpunit 全绿
- [ ] 新增翻译 key 无缺失

---

## 给 AI 的补充说明

- IsolationService 注册为 singleton
- DatabasePerTenantStrategy 需要数据库管理员权限（CREATE DATABASE）
- 迁移工具是核心功能，企业客户最关心
- SchemaPerTenantStrategy 如果 MySQL 环境可只保留接口不实现
- 动态连接切换使用 Config::set('database.connections.xxx')

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
