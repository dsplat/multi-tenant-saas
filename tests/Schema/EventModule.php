<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 事件与监控模块
 * 表: broadcast_events, event_subscriptions, dead_letters, metrics_snapshots, sla_events, audit_logs
 */
class EventModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('broadcast_events', function (Blueprint $table) {
            $table->unsignedBigInteger('broadcast_event_id')->primary();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('event_type', 100);
            $table->string('channel', 200);
            $table->json('payload');
            $table->boolean('is_sent')->default(false);
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'event_type', 'is_sent'], 'idx_tenant_event_sent');
            $table->index('channel');
            $table->index('is_sent');
        });

        Schema::create('event_subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('event_subscription_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('event_type', 100);
            $table->string('subscription_type', 20)->default('internal');
            $table->string('handler', 500);
            $table->string('secret', 128)->nullable();
            $table->boolean('is_active')->default(true);
            $table->string('description', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'is_active']);
            $table->index('event_type');
            $table->unique(['tenant_id', 'event_type', 'handler'], 'uniq_tenant_event_handler');
        });

        Schema::create('dead_letters', function (Blueprint $table) {
            $table->unsignedBigInteger('dead_letter_id')->primary();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('event_type', 100);
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->json('original_data')->nullable();
            $table->text('failure_reason')->nullable();
            $table->unsignedInteger('retry_count')->default(0);
            $table->string('status', 20)->default('failed');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('event_type');
            $table->index(['tenant_id', 'status']);
        });

        Schema::table('dead_letters', function (Blueprint $table) {
            $table->foreign('subscription_id')
                ->references('event_subscription_id')
                ->on('event_subscriptions')
                ->nullOnDelete();
        });

        Schema::create('metrics_snapshots', function (Blueprint $table) {
            $table->unsignedBigInteger('metrics_snapshot_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->string('metric_name', 100);
            $table->double('metric_value')->default(0);
            $table->string('dimension_type', 30)->nullable();
            $table->string('dimension_value', 200)->nullable();
            $table->string('granularity', 10)->default('minute');
            $table->boolean('aggregated')->default(false);
            $table->timestamp('sampled_at');
            $table->timestamps();

            $table->index(['metric_name', 'granularity', 'sampled_at']);
            $table->index(['tenant_id', 'metric_name', 'sampled_at']);
            $table->index(['dimension_type', 'dimension_value']);
            $table->index('sampled_at');
        });

        Schema::create('sla_events', function (Blueprint $table) {
            $table->unsignedBigInteger('sla_event_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->string('event_type', 20);
            $table->string('severity', 20)->default('warning');
            $table->string('affected_scope', 100)->default('global');
            $table->unsignedInteger('affected_count')->default(0);
            $table->timestamp('started_at');
            $table->timestamp('ended_at')->nullable();
            $table->unsignedInteger('duration_sec')->default(0);
            $table->string('status', 20)->default('active');
            $table->text('root_cause')->nullable();
            $table->text('resolution_notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'started_at']);
            $table->index(['event_type', 'started_at']);
            $table->index('status');
            $table->index('started_at');
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->bigInteger('log_id')->unsigned()->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned()->nullable();
            $table->string('action', 50);
            $table->string('resource_type', 50);
            $table->bigInteger('resource_id')->unsigned()->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('user_id');
            $table->index(['resource_type', 'resource_id']);
        });
    }

    public function getTableNames(): array
    {
        return [
            'broadcast_events', 'event_subscriptions', 'dead_letters',
            'metrics_snapshots', 'sla_events', 'audit_logs',
        ];
    }
}
