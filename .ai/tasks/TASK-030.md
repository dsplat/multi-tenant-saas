# TASK-030: 负载测试与性能优化

**Sprint:** sprint-008  
**状态:** READY  
**依赖:** 所有前置阶段完成  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

完成负载测试和性能优化，建立性能基线指标（P95 < 200ms、P99 < 500ms）。

---

## 范围

**只允许修改：**
- `tests/LoadTest.php`（新建）
- `tests/PerformanceTest.php`（新建）
- `src/Services/` 下所有已存在的 Service 文件（性能优化，不改变功能）
- `config/queue.php`（队列调优）
- `config/database.php`（数据库连接池调优）
- `config/cache.php`（缓存策略调优）

**禁止修改：**
- `.ai/scripts/` 下所有文件
- `.ai/prompts/` 下所有文件
- `app/` 应用层代码
- `resources/` 前端资源
- `public/` 公共入口
- `src/` 下除 Services/ 外的其他文件
- `database/` 数据库迁移
- `routes/` 路由文件

---

## 具体内容

### 负载测试

1. 模拟 1000+ 租户、10000+ 并发请求
2. 使用 Laravel 的压力测试工具
3. 测试场景：API 并发、数据库查询、缓存命中率、队列吞吐

### 性能优化

1. 查询 N+1 问题排查（使用 Laravel Telescope / Debugbar）
2. 索引优化（分析慢查询日志）
3. 缓存策略优化（热数据预热、缓存标签）
4. 队列并发调优
5. 数据库连接池优化

### 性能基线

建立性能基线指标：
- P95 < 200ms
- P99 < 500ms
- 错误率 < 0.1%
- 数据库连接池利用率 < 80%

---

## 验收标准

- [ ] 负载测试脚本可执行
- [ ] 1000+ 租户场景下系统稳定
- [ ] N+1 查询问题已排查并修复
- [ ] 慢查询索引已优化
- [ ] 缓存策略已优化
- [ ] 队列并发配置已调优
- [ ] 性能基线指标达标（P95 < 200ms、P99 < 500ms）
- [ ] phpunit 全绿

---

## 给 AI 的补充说明

- 负载测试不真实调用外部 API（mock）
- 性能优化只修改已有 Service 的实现，不改变接口
- N+1 排查使用 with() 预加载
- 缓存使用 Redis（如可用），降级使用数组缓存
- 连接池配置参考 MySQL max_connections

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