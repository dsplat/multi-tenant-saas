<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Webhook 模块
 * 表: webhooks, webhook_deliveries
 */
class WebhookModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('webhooks', function (Blueprint $table) {
            $table->unsignedBigInteger('webhook_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('url', 500);
            $table->json('events');
            $table->string('secret', 128);
            $table->boolean('is_active')->default(true);
            $table->string('description', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('webhook_deliveries', function (Blueprint $table) {
            $table->unsignedBigInteger('webhook_delivery_id')->primary();
            $table->unsignedBigInteger('webhook_id');
            $table->unsignedBigInteger('tenant_id');
            $table->string('event_type', 100);
            $table->json('payload');
            $table->unsignedSmallInteger('response_status_code')->nullable();
            $table->text('response_body')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('status', 20)->default('pending');
            $table->text('error_message')->nullable();
            $table->timestamps();

            $table->index('webhook_id');
            $table->index('tenant_id');
            $table->index(['webhook_id', 'status']);
            $table->index(['tenant_id', 'status']);
            $table->index('event_type');
        });
    }

    public function getTableNames(): array
    {
        return ['webhooks', 'webhook_deliveries'];
    }
}
