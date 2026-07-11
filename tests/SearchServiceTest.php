<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
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
        $this->assertInstanceOf(Builder::class, $result);
    }

    public function test_search_escapes_like_wildcards(): void
    {
        $query = Tenant::query();
        $result = $this->service->search($query, 'test%', ['name']);
        // 转义后的值在 bindings 中
        $bindings = $result->getBindings();
        $likeBinding = $bindings[0] ?? '';
        $this->assertStringContainsString('test\\%', $likeBinding);
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

    public function test_search_with_tenant_model(): void
    {
        Tenant::create(['name' => 'Test Corp', 'slug' => 'test-corp', 'status' => 'active']);
        Tenant::create(['name' => 'Other Inc', 'slug' => 'other-inc', 'status' => 'active']);

        $results = $this->service->search(Tenant::query(), 'Test', ['name'])->get();
        $this->assertCount(1, $results);
        $this->assertEquals('Test Corp', $results->first()->name);
    }

    public function test_search_escapes_percent_wildcard(): void
    {
        Tenant::create(['name' => 'All Items', 'slug' => 'all', 'status' => 'active']);

        $results = $this->service->search(Tenant::query(), '%', ['name'])->get();
        $this->assertCount(0, $results);
    }

    public function test_search_escapes_underscore_wildcard(): void
    {
        Tenant::create(['name' => 'Item A', 'slug' => 'item-a', 'status' => 'active']);

        $results = $this->service->search(Tenant::query(), '_', ['name'])->get();
        $this->assertCount(0, $results);
    }

    public function test_search_models_returns_paginated(): void
    {
        Tenant::create(['name' => 'Test Corp', 'slug' => 'test-corp', 'status' => 'active']);

        $results = $this->service->searchModels(Tenant::class, 'Test', ['name']);
        $this->assertInstanceOf(LengthAwarePaginator::class, $results);
        $this->assertCount(1, $results);
    }
}
