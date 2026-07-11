<?php

namespace MultiTenantSaas\Tests;

use Illuminate\Database\Eloquent\Model;
use MultiTenantSaas\Concerns\Searchable;
use MultiTenantSaas\Models\Tenant;

class SearchableTraitTest extends TestCase
{
    public function test_trait_provides_searchable_fields(): void
    {
        $model = new class extends Model
        {
            use Searchable;

            protected array $searchable = ['name', 'email'];
        };

        $this->assertEquals(['name', 'email'], $model->getSearchableFields());
    }

    public function test_trait_provides_search_scope(): void
    {
        Tenant::create(['name' => 'Test Corp', 'slug' => 'test-corp', 'status' => 'active']);

        $results = Tenant::search('Test')->get();
        $this->assertCount(1, $results);
    }

    public function test_get_searchable_fields_returns_empty_when_not_set(): void
    {
        $model = new class extends Model
        {
            use Searchable;
        };

        $this->assertEquals([], $model->getSearchableFields());
    }

    public function test_search_scope_with_empty_keyword(): void
    {
        Tenant::create(['name' => 'Test Corp', 'slug' => 'test-corp', 'status' => 'active']);

        $results = Tenant::search('')->get();
        $this->assertGreaterThanOrEqual(1, $results->count());
    }
}
