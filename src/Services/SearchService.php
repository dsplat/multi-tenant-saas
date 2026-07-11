<?php

namespace MultiTenantSaas\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * 全文搜索服务
 *
 * 统一所有搜索入口，支持多种搜索后端：
 * - LIKE: 默认，兼容所有数据库
 * - FULLTEXT: MySQL FULLTEXT 索引，性能更好
 *
 * 自动转义 LIKE 通配符 (% 和 _)，防止搜索注入。
 */
class SearchService
{
    /**
     * 在 Eloquent Builder 上执行搜索。
     *
     * @param  Builder  $query  查询构建器
     * @param  string  $keyword  搜索关键词
     * @param  string[]  $fields  搜索字段列表
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
     * @param  class-string<Model>  $modelClass  模型类名
     * @param  string  $keyword  搜索关键词
     * @param  string[]  $fields  搜索字段列表
     * @param  int|null  $perPage  每页数量
     */
    public function searchModels(string $modelClass, string $keyword, array $fields, ?int $perPage = null): LengthAwarePaginator
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
     * @param  Builder  $query  查询构建器
     * @param  string  $keyword  搜索关键词
     * @param  string[]  $fields  搜索字段列表
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
        $special = ['+', '-', '>', '<', '(', ')', '~', '*', '"', '@'];
        $value = str_replace($special, ' ', $value);
        $words = explode(' ', trim($value));
        $words = array_filter($words, fn ($w) => strlen($w) > 0);

        return implode(' ', array_map(fn ($w) => "+{$w}", $words));
    }
}
