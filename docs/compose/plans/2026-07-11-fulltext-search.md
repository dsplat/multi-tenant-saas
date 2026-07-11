# Full-Text Search Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use compose:subagent (recommended) or compose:execute to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a centralized SearchService that replaces scattered LIKE queries with a unified, tenant-aware search API supporting multiple search strategies (LIKE, FULLTEXT, extensible to Scout/Meilisearch).

**Architecture:** `SearchService` provides a `search(Model, keyword, fields)` method that abstracts over different search backends. A `Searchable` trait models can use to declare searchable fields. Default backend is LIKE with proper wildcard escaping; FULLTEXT backend for MySQL when available. No external dependencies.

**Tech Stack:** Laravel Eloquent, MySQL FULLTEXT (optional), existing model patterns.

## Global Constraints

- PHP ^8.3, Laravel ^13.0
- No new Composer dependencies (no Scout, no Meilisearch)
- Tenant isolation preserved (search scoped by tenant_id via BelongsToTenant)
- LIKE wildcard escaping mandatory (prevent `%` and `_` injection)
- All new code must pass Pint + existing test suite

---

### Task 1: Create SearchService with LIKE Backend

**Files:**
- Create: `src/Services/SearchService.php`
- Test: `tests/SearchServiceTest.php`

**Interfaces:**
- Produces: `SearchService::search($query, string $keyword, array $fields): Builder`
- Produces: `SearchService::searchModels(string $modelClass, string $keyword, array $fields, ?int $perPage): LengthAwarePaginator`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Models\Tenant;
use MultiTenantSaas\Services\SearchService;

class SearchServiceTest extends TestCase
{
    protected SearchService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(SearchService::class);
    }

    public function test_service_can_be_resolved(): void
    {
        $this->assertInstanceOf(SearchService::class, $this->service);
    }

    public function test_search_returns_builder(): void
    {
        $query = Tenant::query();
        $result = $this->service->search($query, 'test', ['name']);
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $result);
    }

    public function test_search_escapes_like_wildcards(): void
    {
        // % and _ should be escaped, not treated as wildcards
        $query = Tenant::query();
        $result = $this->service->search($query, 'test%', ['name']);
        // Should search for literal 'test%', not wildcard
        $sql = $result->toSql();
        $this->assertStringContainsString('test\\\\%', $sql);
    }

    public function test_search_with_empty_keyword_returns_original_query(): void
    {
        $query = Tenant::query();
        $result = $this->service->search($query, '', ['name']);
        $this->assertSame($query, $result);
    }

    public function test_search_with_multiple_fields(): void
    {
        $query = Tenant::query();
        $result = $this->service->search($query, 'test', ['name', 'slug', 'contact_email']);
        $sql = $result->toSql();
        $this->assertStringContainsString('name', $sql);
        $this->assertStringContainsString('slug', $sql);
        $this->assertStringContainsString('contact_email', $sql);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:filter -- SearchServiceTest`
Expected: FAIL with "Class not found"

- [ ] **Step 3: Implement SearchService**

```php
<?php

namespace MultiTenantSaas\Services;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 全文搜索服务
 *
 * 统一所有搜索入口，支持多种搜索后端：
 * - LIKE: 默认，兼容所有数据库
 * - FULLTEXT: MySQL FULLTEXT 索引，性能更好
 * - 可扩展: 用户可替换为 Scout/Meilisearch 等
 *
 * 自动转义 LIKE 通配符 (% 和 _)，防止搜索注入。
 */
class SearchService
{
    /**
     * 在 Eloquent Builder 上执行搜索。
     *
     * @param  Builder  $query   查询构建器
     * @param  string   $keyword 搜索关键词
     * @param  string[] $fields  搜索字段列表
     * @return Builder 添加了搜索条件的查询
     */
    public function search(Builder $query, string $keyword, array $fields): Builder
    {
        $keyword = trim($keyword);

        if ($keyword === '' || empty($fields)) {
            return $query;
        }

        $escaped = $this->escapeLike($keyword);

        return $query->where(function ($q) use ($escaped, $fields) {
            foreach ($fields as $field) {
                $q->orWhere($field, 'like', "%{$escaped}%");
            }
        });
    }

    /**
     * 在指定模型上执行搜索并返回分页结果。
     *
     * @param  class-string<Model>  $modelClass 模型类名
     * @param  string               $keyword    搜索关键词
     * @param  string[]             $fields     搜索字段列表
     * @param  int|null             $perPage    每页数量
     * @return \Illuminate\Contracts\Pagination\LengthAwarePaginator
     */
    public function searchModels(string $modelClass, string $keyword, array $fields, ?int $perPage = null): \Illuminate\Contracts\Pagination\LengthAwarePaginator
    {
        $query = $modelClass::query();
        $query = $this->search($query, $keyword, $fields);

        return $query->paginate($perPage ?? 15);
    }

    /**
     * 执行全文搜索（MySQL FULLTEXT）。
     *
     * 仅在 MySQL 环境下可用，其他数据库回退到 LIKE。
     *
     * @param  Builder  $query      查询构建器
     * @param  string   $keyword    搜索关键词
     * @param  string[] $fields     搜索字段列表
     * @return Builder
     */
    public function fulltext(Builder $query, string $keyword, array $fields): Builder
    {
        $keyword = trim($keyword);

        if ($keyword === '' || empty($fields)) {
            return $query;
        }

        $connection = $query->getModel()->getConnection()->getDriverName();

        if ($connection === 'mysql') {
            return $this->mysqlFulltext($query, $keyword, $fields);
        }

        // 非 MySQL 回退到 LIKE
        return $this->search($query, $keyword, $fields);
    }

    /**
     * MySQL FULLTEXT 搜索。
     */
    protected function mysqlFulltext(Builder $query, string $keyword, array $fields): Builder
    {
        $columns = implode(',', $fields);
        $escaped = $this->escapeFulltext($keyword);

        return $query->whereRaw(
            "MATCH({$columns}) AGAINST(? IN BOOLEAN MODE)",
            [$escaped]
        );
    }

    /**
     * 转义 LIKE 通配符。
     *
     * 防止用户输入 % 或 _ 操纵搜索结果。
     */
    protected function escapeLike(string $value): string
    {
        return Str::replace(['%', '_'], ['\\%', '\\_'], $value);
    }

    /**
     * 转义 FULLTEXT 特殊字符。
     */
    protected function escapeFulltext(string $value): string
    {
        // 移除 MySQL FULLTEXT BOOLEAN MODE 特殊字符
        $special = ['+', '-', '>', '<', '(', ')', '~', '*', '"', '@', '(', ')'];
        $value = str_replace($special, ' ', $value);
        // 每个词加 + 前缀 (必须匹配)
        $words = explode(' ', trim($value));
        $words = array_filter($words, fn ($w) => strlen($w) > 0);

        return implode(' ', array_map(fn ($w) => "+{$w}", $words));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:filter -- SearchServiceTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Services/SearchService.php tests/SearchServiceTest.php
git commit -m "feat: add SearchService with LIKE and FULLTEXT backends"
```

---

### Task 2: Create Searchable Trait

**Files:**
- Create: `src/Concerns/Searchable.php`
- Test: `tests/SearchableTraitTest.php`

**Interfaces:**
- Produces: `Searchable::getSearchableFields(): array`
- Produces: `Searchable::scopeSearch(Builder, string): Builder`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace MultiTenantSaas\Tests;

use MultiTenantSaas\Concerns\Searchable;
use Illuminate\Database\Eloquent\Model;

class SearchableTraitTest extends TestCase
{
    public function test_trait_provides_searchable_fields(): void
    {
        $model = new class extends Model {
            use Searchable;

            protected array $searchable = ['name', 'email'];
        };

        $this->assertEquals(['name', 'email'], $model->getSearchableFields());
    }

    public function test_trait_provides_search_scope(): void
    {
        $model = new class extends Model {
            use Searchable;

            protected array $searchable = ['name'];

            protected $table = 'users';
        };

        $query = $model->newQuery();
        $result = $query->search('test');
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Builder::class, $result);
    }

    public function test_get_searchable_fields_returns_empty_when_not_set(): void
    {
        $model = new class extends Model {
            use Searchable;
        };

        $this->assertEquals([], $model->getSearchableFields());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `composer test:filter -- SearchableTraitTest`
Expected: FAIL

- [ ] **Step 3: Implement Searchable trait**

```php
<?php

namespace MultiTenantSaas\Concerns;

use Illuminate\Database\Eloquent\Builder;
use MultiTenantSaas\Services\SearchService;

/**
 * 可搜索 Trait
 *
 * 为模型声明可搜索字段，并提供 scopeSearch 查询作用域。
 *
 * 用法：
 *   class User extends Model
 *   {
 *       use Searchable;
 *
 *       protected array $searchable = ['name', 'email', 'phone'];
 *   }
 *
 *   // 查询
 *   User::search('keyword')->paginate();
 */
trait Searchable
{
    /**
     * 获取可搜索字段列表。
     *
     * @return string[]
     */
    public function getSearchableFields(): array
    {
        return $this->searchable ?? [];
    }

    /**
     * 搜索查询作用域。
     */
    public function scopeSearch(Builder $query, string $keyword): Builder
    {
        $fields = $this->getSearchableFields();

        if (empty($fields)) {
            return $query;
        }

        return app(SearchService::class)->search($query, $keyword, $fields);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `composer test:filter -- SearchableTraitTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add src/Concerns/Searchable.php tests/SearchableTraitTest.php
git commit -m "feat: add Searchable trait with scopeSearch and configurable fields"
```

---

### Task 3: Register SearchService + Apply Searchable Trait to Models

**Files:**
- Modify: `src/TenancyServiceProvider.php` (register SearchService)
- Modify: `src/Models/Tenant.php` (replace scopeSearch with trait)
- Modify: `src/Models/User.php` (add Searchable trait)

**Interfaces:**
- Consumes: `Searchable` trait, `SearchService`

- [ ] **Step 1: Register SearchService in TenancyServiceProvider**

Add to `register()`:

```php
$this->app->singleton(\MultiTenantSaas\Services\SearchService::class);
```

- [ ] **Step 2: Add Searchable trait to Tenant model**

In `src/Models/Tenant.php`:

1. Add `use MultiTenantSaas\Concerns\Searchable;` to the class
2. Add property: `protected array $searchable = ['name', 'slug', 'contact_email'];`
3. Remove the existing `scopeSearch()` method (lines 199-206) — the trait provides it

- [ ] **Step 3: Add Searchable trait to User model**

In `src/Models/User.php`:

1. Add `use MultiTenantSaas\Concerns\Searchable;` to the class
2. Add property: `protected array $searchable = ['name', 'email', 'phone'];`

- [ ] **Step 4: Run tests**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 5: Commit**

```bash
git add src/TenancyServiceProvider.php src/Models/Tenant.php src/Models/User.php
git commit -m "feat: apply Searchable trait to Tenant and User models"
```

---

### Task 4: Refactor Existing LIKE Queries to Use SearchService

**Files:**
- Modify: `src/Services/UserService.php` (replace LIKE with SearchService)
- Modify: `src/Services/TenantMemberService.php` (replace LIKE with SearchService)
- Modify: `src/Services/TenantService.php` (use scopeSearch from trait)

**Interfaces:**
- Consumes: `SearchService::search()`, `Searchable::scopeSearch()`

- [ ] **Step 1: Refactor UserService::list()**

In `src/Services/UserService.php`, replace the LIKE block (around line 30-36) with:

```php
if (! empty($filters['search'])) {
    $query = app(\MultiTenantSaas\Services\SearchService::class)
        ->search($query, $filters['search'], ['name', 'email', 'phone']);
}
```

- [ ] **Step 2: Refactor TenantMemberService::getMembers()**

In `src/Services/TenantMemberService.php`, replace the LIKE block (around line 34-40) with:

```php
if (! empty($options['search'])) {
    $search = $options['search'];
    $query->whereHas('user', function ($q) use ($search) {
        $q->search($search); // Uses User's Searchable trait
    });
}
```

- [ ] **Step 3: Verify TenantService uses scopeSearch**

In `src/Services/TenantService.php`, the existing `$query->search($filters['search'])` at line 26 should work automatically with the trait. No change needed.

- [ ] **Step 4: Run tests**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 5: Commit**

```bash
git add src/Services/UserService.php src/Services/TenantMemberService.php
git commit -m "refactor: replace LIKE queries with SearchService in UserService and TenantMemberService"
```

---

### Task 5: Add SearchService Tests for Integration

**Files:**
- Modify: `tests/SearchServiceTest.php` (add integration tests)

- [ ] **Step 1: Add integration tests**

Add to `tests/SearchServiceTest.php`:

```php
public function test_search_with_tenant_model_scope(): void
{
    // Create test tenants
    Tenant::create(['name' => 'Test Corp', 'slug' => 'test-corp', 'status' => 'active']);
    Tenant::create(['name' => 'Other Inc', 'slug' => 'other-inc', 'status' => 'active']);

    $results = Tenant::search('Test')->get();
    $this->assertCount(1, $results);
    $this->assertEquals('Test Corp', $results->first()->name);
}

public function test_search_escapes_percent_wildcard(): void
{
    Tenant::create(['name' => 'All Items', 'slug' => 'all', 'status' => 'active']);
    Tenant::create(['name' => 'Specific', 'slug' => 'specific', 'status' => 'active']);

    // Searching for '%' should NOT match everything
    $results = Tenant::search('%')->get();
    $this->assertCount(0, $results);
}

public function test_search_escapes_underscore_wildcard(): void
{
    Tenant::create(['name' => 'Item A', 'slug' => 'item-a', 'status' => 'active']);
    Tenant::create(['name' => 'Item B', 'slug' => 'item-b', 'status' => 'active']);

    // Searching for '_' should match literal underscore, not any char
    $results = Tenant::search('_')->get();
    $this->assertCount(0, $results);
}
```

- [ ] **Step 2: Run tests**

Run: `composer test:filter -- SearchServiceTest`
Expected: All pass

- [ ] **Step 3: Commit**

```bash
git add tests/SearchServiceTest.php
git commit -m "test: add SearchService integration tests for wildcard escaping"
```

---

### Task 6: Add Search Config and Documentation

**Files:**
- Modify: `config/tenancy.php` (add search config)
- Modify: `docs/zh/user-manual.md` (add search section)

- [ ] **Step 1: Add search config**

Add to `config/tenancy.php`:

```php
    // 搜索配置
    'search' => [
        // 默认搜索后端: like (兼容) / fulltext (MySQL FULLTEXT)
        'backend' => env('TENANCY_SEARCH_BACKEND', 'like'),
        // 默认每页数量
        'per_page' => 15,
    ],
```

- [ ] **Step 2: Add search section to user manual**

Add to `docs/zh/user-manual.md` after the Mailer section:

```markdown
### Full-Text Search

Centralized search via `SearchService`. Supports LIKE (default) and MySQL FULLTEXT backends.

```php
use MultiTenantSaas\Services\SearchService;

$search = app(SearchService::class);

// Search on Eloquent builder
$results = $search->search(User::query(), 'keyword', ['name', 'email'])->get();

// Search with pagination
$results = $search->searchModels(User::class, 'keyword', ['name', 'email'], 20);

// FULLTEXT search (MySQL only, falls back to LIKE on other DBs)
$results = $search->fulltext(User::query(), 'keyword', ['name', 'email'])->get();
```

**Searchable trait:**

```php
use MultiTenantSaas\Concerns\Searchable;

class User extends Model
{
    use Searchable;

    protected array $searchable = ['name', 'email', 'phone'];
}

// Usage
$users = User::search('keyword')->paginate();
```

**Config:** `config/tenancy.php` → `search.backend` (like/fulltext), `search.per_page`.
```

- [ ] **Step 3: Run final test suite**

Run: `composer test`
Expected: All tests pass

- [ ] **Step 4: Commit**

```bash
git add config/tenancy.php docs/zh/user-manual.md
git commit -m "feat: add search config and documentation"
```
