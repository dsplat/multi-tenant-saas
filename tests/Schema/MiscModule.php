<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 杂项模块
 * 表: consents, cost_allocations, sandbox_environments, custom_reports,
 *     data_retention_policies, tenant_keys, tenant_hierarchies
 */
class MiscModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->unsignedBigInteger('consent_id')->primary();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type', 50);
            $table->string('version', 50)->default('1.0');
            $table->boolean('is_granted')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
            $table->index(['tenant_id', 'type']);
            $table->index(['is_granted', 'revoked_at']);
        });

        Schema::create('cost_allocations', function (Blueprint $table) {
            $table->unsignedBigInteger('cost_allocation_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->string('cost_type', 30);
            $table->string('cost_subtype', 50)->nullable();
            $table->decimal('amount', 14, 4)->default(0);
            $table->string('currency', 10)->default('CNY');
            $table->string('period', 7);
            $table->string('allocation_basis', 100)->nullable();
            $table->decimal('allocation_value', 14, 4)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'period', 'cost_type']);
            $table->index(['period', 'cost_type']);
            $table->index('tenant_id');
        });

        Schema::create('sandbox_environments', function (Blueprint $table) {
            $table->unsignedBigInteger('sandbox_environment_id')->primary();
            $table->unsignedBigInteger('developer_id');
            $table->unsignedBigInteger('sandbox_tenant_id');
            $table->string('api_key', 128)->unique();
            $table->string('status', 20)->default('active');
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index('developer_id');
            $table->index('sandbox_tenant_id');
            $table->index(['developer_id', 'status']);
            $table->index('expires_at');
        });

        Schema::create('custom_reports', function (Blueprint $table) {
            $table->unsignedBigInteger('custom_report_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name', 200);
            $table->string('description', 500)->nullable();
            $table->json('metrics_config')->nullable();
            $table->json('dimensions')->nullable();
            $table->string('time_range', 30)->default('last_7_days');
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->string('frequency', 20)->default('daily');
            $table->json('recipients')->nullable();
            $table->string('format', 20)->default('csv');
            $table->string('template', 100)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_sent_at')->nullable();
            $table->timestamp('next_send_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'frequency']);
            $table->index('next_send_at');
        });

        Schema::create('data_retention_policies', function (Blueprint $table) {
            $table->unsignedBigInteger('data_retention_policy_id')->primary();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('data_type', 50);
            $table->unsignedInteger('retention_days')->default(365);
            $table->boolean('auto_cleanup')->default(false);
            $table->string('cleanup_strategy', 20)->default('delete');
            $table->boolean('is_exempt')->default(false);
            $table->string('description', 255)->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'data_type'], 'uniq_retention_tenant_type');
            $table->index(['auto_cleanup', 'is_exempt']);
            $table->index('data_type');
        });

        Schema::create('tenant_keys', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_key_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->text('encrypted_key');
            $table->string('key_type', 20)->default('system');
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('previous_key_id')->nullable();
            $table->timestamp('rotated_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id', 'tk_tenant_index');
            $table->index(['tenant_id', 'status'], 'tk_tenant_status_index');
            $table->index('previous_key_id', 'tk_previous_index');
        });

        Schema::create('tenant_hierarchies', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_hierarchy_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('child_tenant_id');
            $table->string('relation_type', 30)->default('subsidiary');
            $table->json('permission_scope')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id', 'th_tenant_index');
            $table->index('child_tenant_id', 'th_child_index');
            $table->unique(['tenant_id', 'child_tenant_id'], 'th_parent_child_unique');
        });
    }

    public function getTableNames(): array
    {
        return [
            'consents', 'cost_allocations', 'sandbox_environments', 'custom_reports',
            'data_retention_policies', 'tenant_keys', 'tenant_hierarchies',
        ];
    }
}
