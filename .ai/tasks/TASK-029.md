# TASK-029: 数据驻留与租户克隆

**Sprint:** sprint-007  
**状态:** READY  
**依赖:** TASK-027（IsolationService）、TASK-028（TenantKeyService）  
**Auto-split:** ON  
**人工确认:** OFF

---

## 目标

实现数据驻留管理（区域配置、跨区域迁移）和租户克隆（模板创建、快照导入导出、父-子租户关系）。

---

## 范围

**只允许修改：**
- `src/Services/DataResidencyService.php`（新建）
- `src/Services/TenantCloneService.php`（新建）
- `src/Services/CrossTenantService.php`（新建）
- `src/Models/TenantHierarchy.php`（新建）
- `database/migrations/` 下新增 tenant_hierarchies 迁移
- `config/tenancy.php`（追加驻留和克隆配置）
- `lang/zh_CN/tenant.php`、`lang/en/tenant.php`（追加翻译 key）
- `tests/DataResidencyServiceTest.php`（新建）
- `tests/TenantCloneServiceTest.php`（新建）
- `tests/TestCase.php`（追加新表 schema）

**禁止修改：**
- `.ai/scripts/` 下所有文件
- `.ai/prompts/` 下所有文件
- `app/` 应用层代码
- `resources/` 前端资源
- `public/` 公共入口
- `src/` 下除上述允许文件外的其他文件

---

## 具体内容

### DataResidencyService

1. 区域配置（CN/US/EU/APAC）
2. 数据存储区域限制
3. 跨区域迁移（租户数据从一个区域迁移到另一个）
4. 合规校验（数据是否在指定区域）

### TenantCloneService

1. 从模板创建租户（复制配置、角色、权限等）
2. 租户快照（完整导出配置为 JSON）
3. 配置导入导出
4. 克隆验证

### CrossTenantService

1. 父-子租户关系（企业集团场景）
2. 资源共享池
3. 层级计费（父租户统一付费）
4. 跨租户资源访问授权

### 数据模型

`tenant_hierarchies` 表: 父租户ID、子租户ID、关系类型、权限范围

---

## 验收标准

- [ ] 区域配置正常
- [ ] 数据存储区域限制正常
- [ ] 跨区域迁移正常
- [ ] 合规校验正常
- [ ] 模板创建租户正常
- [ ] 租户快照导入导出正常
- [ ] 父-子租户关系正常
- [ ] 层级计费正常
- [ ] TestCase 追加新表 schema，phpunit 全绿
- [ ] 新增翻译 key 无缺失

---

## 给 AI 的补充说明

- TenantHierarchy 模型 use HasTenantScope（父租户视角）
- 跨区域迁移使用 IsolationService 的迁移工具
- 租户快照包含：配置、角色、权限、品牌设置、AI 配置（不含业务数据）
- 层级计费与现有 SubscriptionService 集成
- 克隆验证：比对源租户和目标租户的配置一致性

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
