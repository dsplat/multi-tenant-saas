## Architecture

模板数据与服务逻辑分离（`BuiltinAgentTemplates` vs `AgentService`）是合理的。`BuiltinAgentTemplates` 作为纯数据类标记 `final` 也恰当。但有两个问题：

1. **缺失依赖未解决** — `AgentServiceContract` 接口文件和 `Agent` Eloquent Model 均不存在于代码库中。`TenancyServiceProvider` 注册了 `AgentServiceContract` 绑定，运行时会抛出 `Error: Class not found`。同理 `AiTextServiceContract` 和 `AiTextService` 也不存在。TASK-041 声明依赖 TASK-040，但这些文件当前缺失，代码无法通过 autoloader 加载。
2. **`@implements` 注解无效** — `AgentService.php:22` 声明 `@implements AgentServiceContract`，但该接口不存在，PHPStan/Psalm 会报错，且实际上类没有实现任何契约方法（contract 不存在，无法验证方法签名一致性）。

## Code Quality

- **命名规范**：`template_key` / `template_id` / `role` 命名清晰一致，符合项目风格。
- **`BuiltinAgentTemplates::find()` 和 `findByKey()` 重复遍历**：每次调用都重新执行 `definitions()` 构建全部模板再线性查找。虽然数据量小（8条）无性能问题，但 `all()` 已经返回 Collection，`find()` 可复用它而非再次调用 `definitions()`。不过考虑到底层是静态纯数据，这是设计取舍，不算严重。
- **`cloneFromTemplate` 中 `tenant_id` 被设置两次**：`AgentService.php:61` 在 `array_merge` 中已设 `tenant_id`，第 77 行又强制覆盖。第 76-77 行的 `is_builtin` 和 `tenant_id` 强制覆盖是防御性编程，但注释只解释了 `is_builtin`，`tenant_id` 的覆盖意图不明——如果 `$overrides` 传入了不同的 `tenant_id` 会被静默丢弃，可能引起困惑。

## Type Safety

- **PHPDoc 类型标注完整**：`BuiltinAgentTemplates::definitions()` 使用了精确的 `list<array{...}>` 形状类型，`defaultModelConfig()` 也有完整的返回类型。质量高。
- **`Agent::create($attributes)` 返回类型无法验证**：因 `Agent` Model 不存在，无法确认 `$attributes` 中的字段是否与 Model 的 `$fillable` 匹配，也无法验证返回类型是否确实是 `Agent`。
- **`$overrides` 缺少形状约束**：`cloneFromTemplate` 的 `$overrides` 参数类型为裸 `array`，没有限制允许覆盖的字段。调用方可传入任意 key（如 `tenant_id`、`is_builtin`、`id`），虽然 `tenant_id` 和 `is_builtin` 会被第 76-77 行覆盖，但其他非法字段会直接进入 `Agent::create()`。

## Security

- **无 SQL 注入风险**：使用 Eloquent `create()`，参数化处理。
- **无 XSS 风险**：纯后端逻辑，不涉及视图渲染。
- **`$overrides` 未做白名单过滤**：`array_merge` 后直接传入 `Agent::create()`，如果调用方传入敏感字段（如 `id`、`created_by`、`is_builtin`），可绕过预期约束。`is_builtin` 和 `tenant_id` 有硬编码覆盖保护，但其他字段没有。
- **无鉴权检查**：`cloneFromTemplate` 不验证 `$tenantId` 是否合法/当前用户是否有权限操作该租户，但考虑到这是 Service 层而非 Controller，鉴权应由上层处理，可接受。

## Performance

- **无 N+1 问题**：纯内存操作，无数据库查询（`cloneFromTemplate` 中仅一次 `create`）。
- **`definitions()` 每次调用都重建完整数组**：`find()`、`findByKey()`、`all()` 每次都调用 `definitions()`，数据量小无可感知影响，但可考虑静态缓存。

## Potential Bugs

1. **`AgentServiceContract` 和 `Agent` Model 缺失**（阻塞）：代码引用了不存在的类，运行时必崩。这是 TASK-040 未完成的遗留问题。
2. **`$overrides` 可注入 `id` 字段**：`AgentService.php:60-73` 的 `array_merge` 后无字段白名单，恶意调用方可通过 `$overrides = ['id' => 123]` 指定主键，可能导致主键冲突或覆盖已有记录。
3. **`$overrides` 可注入 `created_at` / `updated_at` 等时间戳字段**：同上，无白名单保护。
4. **`find()` 严格类型比较 `===`**：`BuiltinAgentTemplates.php:210` 使用 `===` 比较 `template_id`，如果调用方传入字符串 `"1"` 而非整数 `1`，会匹配不到。`cloneFromTemplate` 的参数类型为 `int`，所以从正常调用链来看不会出问题，但 `find()` 作为 public 方法的调用方可能传入字符串。

## Verdict

**FAIL**

【必须修复】：
1. `AgentServiceContract` 接口文件不存在 — `AgentService` 声明实现该契约，`TenancyServiceProvider` 注册该绑定，但文件缺失，运行时 fatal error。需由 TASK-040 先落地或手动创建桩接口。
2. `Agent` Eloquent Model 文件不存在 — `AgentService::cloneFromTemplate()` 调用 `Agent::create()`，但 Model 类缺失，运行时 fatal error。
3. `AiTextServiceContract` 和 `AiTextService` 文件不存在 — `TenancyServiceProvider:112-115` 注册了绑定，但两个类均缺失，运行时 fatal error。
4. `cloneFromTemplate()` 的 `$overrides` 缺少字段白名单 — 可注入 `id`、`is_builtin`、`created_at` 等非预期字段，应限制为仅允许 `name`/`avatar`/`tools`/`kb_ids`/`feature_keys`/`model_config`/`enabled`/`description`。