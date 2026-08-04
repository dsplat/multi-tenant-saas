# 框架设计原则与常见陷阱

> 从四轮代码审查和实际架构中提炼的设计约束、易犯错误、反模式。
> **最后更新**: 2026-08-04
> **关联文档**: `docs/zh/architecture/framework-internals.md`、`docs/zh/development/coding-standards.md`

---

## 一、租户隔离 — 最高优先级约束

### 1.1 Fail-closed 原则

**规则**: 无租户上下文时，查询返回零行（`WHERE 1=0`），不返回全量数据。

```php
// TenantScope::apply() 核心逻辑
if ($tenantId) {
    $builder->where($model->getTable() . '.tenant_id', $tenantId);
} elseif (!static::$unscopedAllowed) {
    $builder->whereRaw('1 = 0');  // ← fail-closed
}
```

**常见陷阱**:

| 错误写法 | 为什么错 | 正确做法 |
|---------|---------|---------|
| `Tenant::all()` 在队列 Job 中 | 无 HTTP 上下文，tenant_id 为 null → 返回空 | 用 `TenantScope::allowUnscoped(fn() => Tenant::all())` |
| `Model::withoutGlobalScope(TenantScope::class)` | 绕过隔离 | 仅在 admin 域名下可用，其他场景抛 DomainException |
| 手动 `where('tenant_id', $id)` 不用 trait | 新模型忘记加 BelongsToTenant → 数据泄露 | 所有租户级模型**必须** use `BelongsToTenant` |

### 1.2 三种隔离策略

| 策略 | 实现 | 适用场景 |
|------|------|---------|
| `SharedDatabaseStrategy` | 行级隔离（`WHERE tenant_id`） | 默认，大多数场景 |
| `SchemaPerTenantStrategy` | 独立 Schema | 中等隔离需求 |
| `DatabasePerTenantStrategy` | 独立数据库 | 高隔离需求（金融、医疗） |

**约束**: 隔略策略在 `tenants.isolation_type` 字段声明，创建后不可更改。

### 1.3 `tenant_id` 类型一致性

**陷阱**: `Tenant.tenant_id` 是 `bigint`（雪花 ID），但 `TenantContext::getId()` 返回 `string`。比较时需显式转换。

```php
// 错误：类型不一致
if ($task->tenant_id !== TenantContext::getId()) { ... }

// 正确：统一为 int 比较
if ((int) $task->tenant_id !== (int) TenantContext::getId()) { ... }
```

---

## 二、异常体系 — 禁止裸 RuntimeException

### 2.1 异常层级

```
DomainException (extends RuntimeException, implements HttpExceptionInterface)
  ├── NotFoundException          → 404
  ├── ConflictException          → 409
  ├── ServiceUnavailableException → 503
  ├── QuotaExceededException     → 429
  ├── PermissionDeniedException  → 403
  ├── InsufficientCreditsException → 402
  ├── StorageException           → 500
  ├── SummaryGenerationException → 502
  └── TenantNotFoundException    → 404
```

### 2.2 为什么不能用裸 RuntimeException

| 问题 | 说明 |
|------|------|
| HTTP 状态码错误 | `\RuntimeException` 被当作未知异常 → 500，但业务校验失败应返回 422 |
| 生产环境泄露 | 500 级错误的消息会被脱敏为"服务器内部错误"，422 不会 |
| 无法差异化处理 | catch 时无法区分"资源不存在"(404) 和"业务冲突"(409) |
| pre-commit 拦截 | `scripts/architecture_guard.py` 会阻止新增 RuntimeException |

### 2.3 选择正确的异常

| 场景 | 异常 | 状态码 |
|------|------|--------|
| 资源不存在 | `NotFoundException` | 404 |
| 重复创建、乐观锁冲突 | `ConflictException` | 409 |
| 依赖服务不可用（支付、短信） | `ServiceUnavailableException` | 503 |
| 配额/频率超限 | `QuotaExceededException` | 429 |
| 权限不足、跨租户访问 | `PermissionDeniedException` | 403 |
| 积分不足 | `InsufficientCreditsException` | 402 |
| 业务校验失败 | `DomainException`（默认 422） | 422 |
| 存储层故障 | `StorageException` | 500 |

### 2.4 自定义领域异常

```php
use MultiTenantSaas\Exceptions\DomainException;

// 只需继承并声明 statusCode
class PaymentDeclinedException extends DomainException
{
    protected int $statusCode = 402;
}
```

---

## 三、模块系统 — 约定大于配置

### 3.1 模块生命周期

```
ModuleRegistry（磁盘扫描）
  → ModuleManager（DB 状态 + 依赖校验 + 拓扑排序）
    → ModuleBootstrapper（register → boot）
      → ModuleServiceProvider（路由/迁移/配置/命令）
```

### 3.2 模块目录约定

```
src/Modules/<ModuleName>/
  ├── composer.json          # extra.saas 声明（name/provider/priority/dependencies）
  ├── <Module>ServiceProvider.php  # 继承 ModuleServiceProvider
  ├── Database/migrations/   # 自动加载
  ├── Routes/
  │   ├── api.php            # auth:sanctum + tenant.identify + VerifyOperatorTenant
  │   ├── admin.php          # auth:sanctum（admin 域名）
  │   ├── tenant.php         # auth:sanctum（console 域名）
  │   └── public.php         # 无认证
  ├── Http/Controllers/
  ├── Services/
  ├── Models/
  └── resources/
      ├── views/             # 自动注册到 Blade
      └── lang/              # 自动注册到翻译器
```

### 3.3 常见陷阱

| 陷阱 | 说明 | 解决 |
|------|------|------|
| 模块目录名大小写 | Linux 大小写敏感，`src/Modules/billing` ≠ `Billing` | pre-commit 自动检查 PascalCase |
| composer.json 缺 `extra.saas` | ModuleRegistry 跳过该模块 | 必须声明 name/provider |
| 路由中间件顺序 | `tenant.identify` 必须在 `VerifyOperatorTenant` 之前 | 否则 Operator 归属校验误判 403 |
| Config 文件名大小写 | `Config/Billing.php` vs `Config/billing.php` | `resolveModuleConfigPath()` 自动探测两种 |
| 模块间直接依赖 | 模块 A 直接 `new ModuleB\Service()` | 通过 Contract 接口 + DI 容器 |

### 3.4 路由中间件栈

| 路由类型 | 中间件 | 说明 |
|---------|--------|------|
| API | `auth:sanctum`, `throttle:api`, `tenant.identify`, `VerifyOperatorTenant` | 租户识别在 Operator 校验之前 |
| Admin | `auth:sanctum`, `throttle:api` | admin 域名由 IdentifyDomain 中间件保证 |
| Tenant | `auth:sanctum`, `throttle:api` | console 域名 |
| Public | `api` | 无认证，无租户识别 |

---

## 四、AgentRuntime 架构 — 拆分后的职责边界

### 4.1 四类职责

| 类 | 职责 | 禁止做的事 |
|----|------|-----------|
| `AgentRuntime` | 编排：循环控制、消息持久化、工作流链、记忆压缩触发 | 不直接调 AI、不直接执行工具 |
| `AgentChatClient` | 推理：调用 AI 模型、降级容错、模型配置解析 | 不持久化消息、不执行工具 |
| `AgentContextBuilder` | 上下文：组装 system_prompt + 历史 + 记忆注入 | 不调 AI、不执行工具 |
| `AgentToolExecutor` | 工具：风险分区、L2 确认签发、批量执行、事件派发 | 不编排循环、不调 AI |

### 4.2 L2 确认门流程

```
AgentToolExecutor.partitionByRisk(toolCalls)
  → L1 工具：直接执行
  → L2 工具：签发 confirm_token → 返回 pending_confirmation
    → 用户确认 → ActionConfirmService.consume() → 执行
    → 用户取消 → 令牌作废 → 审计 ai_action_cancel
```

### 4.3 事件派发点

| 事件 | 触发位置 | 监听器 |
|------|---------|--------|
| `ToolCalled` | AgentToolExecutor 执行前 | （可扩展） |
| `ToolCallCompleted` | AgentToolExecutor 执行成功后 | （可扩展） |
| `ToolCallFailed` | AgentToolExecutor 执行失败后 | LogEventListener → 审计日志 |
| `AgentCreated` | AgentService 创建 Agent 后 | LogEventListener → 审计日志 |
| `AgentEnabled/Disabled` | AgentService 启停 Agent 后 | LogEventListener → 审计日志 |
| `ConversationStarted` | AgentChatController/AssistantController | （可扩展） |
| `ConversationEnded` | AgentChatController/AssistantController | （可扩展） |

---

## 五、Controller 规范

### 5.1 必须继承 BaseController

```php
// 正确
use MultiTenantSaas\Http\Controllers\BaseController;

class TenantController extends BaseController
{
    public function index(): JsonResponse
    {
        return $this->paginatedResponse(Tenant::paginate());
    }
}

// 错误：直接继承 Illuminate\Routing\Controller
class TenantController extends Controller { ... }
```

### 5.2 响应格式一致性

所有 API 响应必须遵循统一结构：

```json
// 成功
{ "success": true, "message": "Success", "data": { ... } }

// 分页
{ "success": true, "message": "Success", "data": [...], "meta": { "current_page": 1, ... } }

// 错误
{ "success": false, "message": "错误描述" }
```

**禁止**: 直接 `return response()->json($model)` 或 `return response()->json(['data' => $data])`。

---

## 六、Octane/Swoole 安全

### 6.1 静态属性污染

**规则**: 不使用静态属性存储请求级状态。Octane 环境下 worker 进程复用，静态属性跨请求持久化。

```php
// 错误：静态属性
class SomeService {
    protected static $tenantId;  // Octane 下跨请求污染
}

// 正确：Request attributes
class TenantContext {
    protected static function getRequest(): ?Request {
        return request();  // 每次请求新实例
    }
}
```

### 6.2 config() 写入

**规则**: 不在运行时修改 `config()` 值。Octane 下 config 变更跨请求持久化。

```php
// 错误
config(['session.domain' => $domain]);  // Octane 污染

// 正确：通过中间件在请求早期设置，或使用 Request attributes
```

---

## 七、测试陷阱

### 7.1 SQLite vs MySQL 差异

| 差异 | SQLite | MySQL |
|------|--------|-------|
| `NOW()` 函数 | 不存在，需自定义 | 内置 |
| `JSON` 类型 | 存为 TEXT | 原生 JSON |
| 大小写 | 默认不敏感 | 敏感（取决于 collation） |
| 外键约束 | 默认关闭，需 `PRAGMA foreign_keys=ON` | 默认开启 |

**解决方案**: TestCase 已注册 SQLite 自定义 `NOW()` 函数，关闭外键约束。

### 7.2 种子数据幂等

```php
// 错误：重复插入 → UNIQUE 约束失败
DB::table('permissions')->insert(['name' => 'tenant.view', ...]);

// 正确：幂等插入
DB::table('permissions')->updateOrInsert(
    ['name' => 'tenant.view'],
    ['display_name' => '查看租户', ...]
);
```

### 7.3 测试数据重置

- MySQL: `TRUNCATE TABLE`（快，但有外键约束）
- SQLite: `DELETE FROM`（比 DROP/CREATE 快 10-50 倍）
- 每次 setUp 自动重置，无需手动清理

---

## 八、前端架构陷阱

### 8.1 多 UI 框架降级

路由解析按 4 级降级：本地当前框架 → vendor 当前框架 → 本地 bootstrap → vendor bootstrap。

**陷阱**: 如果本地覆盖了某个视图但没有对应的 UI 框架版本，会降级到 bootstrap，可能导致样式不一致。

### 8.2 localStorage Token

当前 Token 存储在 localStorage，XSS 攻击可窃取。这是一个已知的安全权衡（SMELL-003），需要前后端联调改为 httpOnly cookie。

### 8.3 路由守卫可测试性

路由守卫已抽取为 `createAuthGuard()` 工厂函数，接受 store 注入，便于单测：

```typescript
// router/guards.ts
export function createAuthGuard(getStore: () => AuthStoreLike): NavigationGuard {
  return async (to, _from, next) => { ... }
}
```

---

## 九、架构守卫（pre-commit）

`scripts/architecture_guard.py` 在每次提交前自动检查：

| 检查项 | 严重度 | 说明 |
|--------|--------|------|
| 大小写冲突 | 阻断 | macOS 隐身、Linux 生产爆炸 |
| 模块目录 PascalCase | 阻断 | `src/Modules/<Name>` 必须大驼峰 |
| PSR-4 一致性 | 阻断 | namespace 声明匹配文件路径 |
| RuntimeException 禁用 | 阻断 | 新增行不得 `throw new RuntimeException` |
| KB 索引新鲜度 | 警告 | 路由/工具变更时提醒重新生成 |
| **文档新鲜度** | **警告** | `docs/manifest.json` 中被修改代码涉及的文档需同步更新 |

文档版本追踪详见 `docs/manifest.json` 和 `scripts/check-docs-freshness.py`。

**紧急绕过**: `git commit --no-verify`（不推荐）。
