# 项目框架规范（开发必读）

## 1. 代码规范

- PHP 8.2+，严格类型声明
- 命名空间：`MultiTenantSaas\`
- 代码风格：Laravel 规范，PSR-12
- 所有模型使用 `HasGlobalId` trait 生成 16 位数字主键
- 服务类通过构造函数注入依赖

## 2. 架构规范

- `src/Models/` - Eloquent 模型
- `src/Services/` - 业务服务（扁平结构，按功能命名）
- `src/Contracts/` - 接口契约
- `src/Events/` - 事件类
- `src/Http/Controllers/` - API 控制器
- `src/Middleware/` - 中间件
- 服务注册在 `src/TenancyServiceProvider.php`

## 3. 数据库规范

- 主键：`unsignedBigInteger`，由 `IdGenerator` 生成（非自增）
- 迁移文件命名：`YYYY_MM_DD_NNNNNN_description.php`
- 租户隔离：所有业务表包含 `tenant_id` 字段
- JSON 字段用于存储灵活配置

## 4. 安全规范

- 多租户数据隔离（TenantContext）
- API 通过 Sanctum 认证
- 所有 ID 使用 IdGenerator 生成

## 5. 禁止事项

- ❌ 不要修改现有迁移文件
- ❌ 不要使用自增 ID
- ❌ 不要修改 `src/Concerns/HasGlobalId.php`
- ❌ 不要修改 `src/Contracts/IdGeneratorContract.php`
- ❌ 不要探索过多参考文件（只读本文件 + 需求文档即可）
# 项目框架规范（开发必读）

> **此文件为模板。请在项目初始化后，根据实际项目规范修改此文件。**
> **AI 开发时只读此文件，不要探索其他文档。**

---

## 1. 代码规范

[在此填写项目的代码规范，例如：]
- 语言版本要求
- 代码风格（命名规范、缩进、注释格式）
- 文件组织结构

---

## 2. 架构规范

[在此填写项目的架构规范，例如：]
- 模块划分
- 依赖关系
- 目录结构

---

## 3. 数据库规范

[在此填写数据库相关规范，例如：]
- 主键策略
- 命名规范
- 迁移规则

---

## 4. 安全规范

[在此填写安全相关规范，例如：]
- 认证/授权机制
- 数据加密
- 输入验证

---

## 5. 禁止事项

[在此填写项目特定的禁止事项，例如：]
- ❌ 不要使用某技术/库
- ❌ 不要修改某核心文件
- ❌ 不要探索过多参考文件（只读本文件 + 1 个示例即可）

---

## 注意

此文件是 AI 开发时唯一的项目规范参考。
请在项目初始化后立即修改此文件，填写实际的项目规范。
未填写的部分 AI 将使用通用最佳实践。
