# 编码规范

**最后更新**: 2026-08-01

---

## 异常体系（强制）

### 禁止裸 RuntimeException

框架已全量迁移至领域异常体系，`src/` 下禁止 `throw new \RuntimeException`（pre-commit 钩子自动拦截）。

### 异常层级

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

### 使用示例

```php
use MultiTenantSaas\Exceptions\NotFoundException;
use MultiTenantSaas\Exceptions\ConflictException;
use MultiTenantSaas\Exceptions\ServiceUnavailableException;

// 资源不存在 → 404
throw new NotFoundException(trans('payment.order_not_found'));

// 业务冲突 → 409
throw new ConflictException(trans('subscription.coupon_code_exists'));

// 依赖服务不可用 → 503
throw new ServiceUnavailableException(trans('payment.driver_not_configured', ['driver' => 'stripe']));

// 业务校验失败 → 422（DomainException 默认）
throw new DomainException(trans('subscription.plan_not_available'));
```

### 自定义领域异常

```php
use MultiTenantSaas\Exceptions\DomainException;

class PaymentDeclinedException extends DomainException
{
    protected int $statusCode = 402;
}
```

---

## Controller 规范

### 基类

所有 Controller 必须继承 `MultiTenantSaas\Http\Controllers\BaseController`，不要直接继承 `Illuminate\Routing\Controller`。

`BaseController` 提供统一 API 响应方法：

```php
use MultiTenantSaas\Http\Controllers\BaseController;

class TenantController extends BaseController
{
    public function show(Tenant $tenant): JsonResponse
    {
        return $this->successResponse(new TenantResource($tenant));
    }

    public function store(StoreTenantRequest $request): JsonResponse
    {
        $tenant = Tenant::create($request->validated());
        return $this->createdResponse(new TenantResource($tenant));
    }

    public function destroy(Tenant $tenant): JsonResponse
    {
        $tenant->delete();
        return $this->deletedResponse();
    }

    public function index(Request $request): JsonResponse
    {
        $tenants = Tenant::paginate($request->input('per_page', 15));
        return $this->paginatedResponse($tenants);
    }
}
```

### 可用响应方法

| 方法 | 状态码 | 说明 |
|------|--------|------|
| `successResponse($data, $message)` | 200 | 成功 |
| `createdResponse($data, $message)` | 201 | 创建成功 |
| `deletedResponse($message)` | 200 | 删除成功 |
| `errorResponse($message, $statusCode, $errors)` | 自定义 | 错误 |
| `notFoundResponse($message)` | 404 | 未找到 |
| `forbiddenResponse($message)` | 403 | 禁止访问 |
| `validationErrorResponse($errors, $message)` | 422 | 验证失败 |
| `paginatedResponse($paginator, $message)` | 200 | 分页数据 |

## API Resource 层

### 基本原则
- **所有 Controller 必须使用 API Resource 返回数据**：禁止直接返回模型或数组
- **Resource 位置**：`src/Modules/{Module}/Http/Resources/`
- **命名规范**：`{Model}Resource`（如 `UserResource`、`TenantResource`）

### 数据脱敏规则
| 字段类型 | 处理方式 | 示例 |
|----------|----------|------|
| 密码、token、私钥 | 永远不返回 | `password` → 不返回 |
| 手机号 | 脱敏 | `13812345678` → `138****5678` |
| 邮箱 | 仅必要时返回 | super_admin 可见，普通用户不可见 |
| 配置密钥 | 自动 mask | `wechat_secret` → `********` |

### 敏感字段识别
Resource 中自动识别包含以下关键词的字段：
- `secret`
- `password`
- `key`
- `token`
- `private`

### 使用示例
```php
// Controller 中使用
return response()->json([
    'success' => true,
    'data' => new UserResource($user),
]);

// 返回集合
return response()->json([
    'success' => true,
    'data' => UserResource::collection($users),
]);

// 关联数据按需加载
$user->load('tenants');
return new UserResource($user);
```

## 提交规范

### 格式
```
<type>: <description>
```

### 类型
| 类型 | 说明 | 示例 |
|------|------|------|
| `feat` | 新功能 | `feat: 添加用户注册 API` |
| `fix` | Bug 修复 | `fix: 修复租户数据隔离问题` |
| `refactor` | 重构（不改变功能） | `refactor: Controller 拆分为独立文件` |
| `docs` | 文档更新 | `docs: 更新 API 文档` |
| `test` | 测试相关 | `test: 添加用户注册测试` |
| `chore` | 构建/工具/依赖 | `chore: 更新 composer 依赖` |

### 注意事项
- 描述使用中文
- 第一行不超过 50 字符
- 不要以句号结尾
- 使用祈使语气（"添加" 而不是 "添加了"）

## 代码风格

### PHP 规范
- 遵循 **PSR-12** 规范
- 使用 **Laravel 最佳实践**

### 命名规范
| 元素 | 风格 | 示例 |
|------|------|------|
| 类名 | PascalCase | `UserResource`、`TenantController` |
| 方法名 | camelCase | `getUser`、`ensureTenantAccess` |
| 变量名 | camelCase | `$tenantId`、`$userResource` |
| 常量 | UPPER_SNAKE_CASE | `SENSITIVE_KEYS`、`MAX_LOGIN_ATTEMPTS` |
| 数据库表 | snake_case | `tenant_users`、`credit_accounts` |
| 数据库字段 | snake_case | `user_id`、`created_at` |

### 类型声明
- 所有方法参数必须有类型声明
- 所有方法必须有返回值类型声明
- 使用 PHP 8.3+ 特性（枚举、只读属性等）

### 注释规范
- 使用中文注释
- 关键逻辑必须有注释
- 类和方法必须有 PHPDoc 注释
- 复杂算法必须有详细注释

### 示例
```php
<?php

namespace MultiTenantSaas\Modules\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * 用户资源
 *
 * 返回用户信息，敏感字段自动脱敏
 */
class UserResource extends JsonResource
{
    /**
     * 转换为数组
     *
     * @param Request $request
     * @return array
     */
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->user_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->when($this->phone, fn() => $this->maskPhone($this->phone)),
            'role' => $this->role,
            'created_at' => $this->created_at,
        ];
    }

    /**
     * 手机号脱敏
     *
     * @param string $phone
     * @return string
     */
    private function maskPhone(string $phone): string
    {
        return substr($phone, 0, 3) . '****' . substr($phone, -4);
    }
}
```
