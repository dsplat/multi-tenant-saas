# TASK-031: 安全审计与文档补全

**Sprint:** sprint-008  
**状态:** READY  
**依赖:** 所有前置阶段完成  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

完成安全审计（OWASP Top 10）和全量文档补全，确保正式发布条件。

---

## 范围

**只允许修改：**
- `docs/` 下所有文档（新建/更新）
- `tests/SecurityTest.php`（新建）
- `README.md`（更新）
- `CHANGELOG.md`（更新）

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

### 安全审计

1. OWASP Top 10 扫描
2. 依赖漏洞检查（composer audit）
3. SQL 注入测试
4. XSS 测试
5. CSRF 测试
6. 敏感数据泄露检查

### 文档补全

1. 架构文档更新（系统架构概览、数据模型设计、租户隔离架构、设计决策）
2. API 参考（所有端点，含 AI 模块）
3. 部署指南（含 Docker/K8s）
4. 运维手册
5. 快速入门（5 分钟上手）
6. AI 模块使用指南
7. 计费配置指南

### SDK 示例代码

1. PHP SDK 使用示例
2. REST API 调用示例

---

## 验收标准

- [ ] OWASP Top 10 扫描通过（0 高危）
- [ ] composer audit 通过
- [ ] SQL 注入测试通过
- [ ] XSS/CSRF 测试通过
- [ ] 敏感数据泄露检查通过
- [ ] 架构文档已更新
- [ ] API 参考文档完整
- [ ] 部署指南完整（含 Docker/K8s）
- [ ] 快速入门文档完整
- [ ] AI 模块使用指南完整
- [ ] SDK 示例代码完整
- [ ] README.md 已更新
- [ ] CHANGELOG.md 已更新
- [ ] phpunit 全绿

---

## 给 AI 的补充说明

- 安全审计使用 composer audit 和手动测试
- 文档使用 Markdown 格式
- API 参考基于 OpenAPI/Swagger 规范
- 部署指南包含 Docker Compose 和 Kubernetes 两种方式
- SDK 示例代码放在 docs/examples/ 目录

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
