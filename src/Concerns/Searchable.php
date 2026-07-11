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
