# 主键机制铁律（生成代码时必须遵守）

本规则定义全系统唯一的主键生成方案，杜绝 snowflake/UUID/自增 ID 等平行方案。

## 1. 唯一主键方案：16 位随机数字全局 ID

| 属性 | 值 |
|---|---|
| 形态 | 16 位纯数字，`bigint unsigned` 存储 |
| 范围 | [1000000000000000, 9007199254740991]（JS `Number.MAX_SAFE_INTEGER` 安全） |
| 唯一性 | 全系统所有表共用一个 ID 空间，全局唯一 |
| 有序性 | 完全随机无序，无法推测业务增长 |
| 生成器 | `MultiTenantSaas\Contracts\IdGeneratorContract`（实现：`Modules/Infrastructure/Services/IdGenerator`，容器单例） |
| 配置 | `config/id.php`（id_length=16、min_value、max_value） |

## 2. 新表/新模型接入方式

- 迁移：主键列 `<entity>_id bigint unsigned NOT NULL`，注释注明「IdGenerator 全局ID」（参照 AI 模块迁移风格）
- 模型：`use MultiTenantSaas\Concerns\HasGlobalId`（自动在 creating 时生成 ID、关闭自增、声明 int 键类型）+ `$primaryKey='<entity>_id'`
- 禁止手动 `$model->id = Str::uuid()` / `time()` 拼接等自造 ID

## 3. 禁止事项

- ❌ snowflake ID（64 位超出 JS 安全整数、引入机器位/时钟依赖，与框架方案直接冲突）
- ❌ UUID/ULID 作主键（字符串主键，破坏全系统 bigint 统一约定）
- ❌ 自增主键（泄露业务量、分库分表与多租户合并场景冲突）
- ❌ 外部系统返回的 ID（如第三方 provider 的 task_id）作本地主键——只能作为独立 varchar 字段存储（参照 ai_requests 对 Kling/Runway task_id 的处理）

## 4. 例外

- `guest_user_id`（=1）等极少数配置型固定 ID 见 `config/id.php`，属预置数据而非生成方案
