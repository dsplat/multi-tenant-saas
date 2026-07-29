<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ibot 模块
 * 表: ibots, operator_ibot_bindings
 */
class IbotModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('ibots', function (Blueprint $table) {
            $table->unsignedBigInteger('ibot_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('channel_type', 20);
            $table->string('transport', 20)->default('webhook');
            $table->string('name', 128);
            $table->text('credentials')->nullable();
            $table->string('webhook_secret', 128)->nullable();
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'channel_type']);
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('operator_ibot_bindings', function (Blueprint $table) {
            $table->unsignedBigInteger('binding_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('operator_id');
            $table->unsignedBigInteger('ibot_id');
            $table->string('external_id', 128);
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->boolean('is_default_channel')->default(false);
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['operator_id', 'ibot_id'], 'ibot_bindings_operator_ibot_unique');
            $table->unique(['ibot_id', 'external_id'], 'ibot_bindings_ibot_external_unique');
            $table->index(['tenant_id', 'operator_id']);
        });
    }

    public function getTableNames(): array
    {
        return ['ibots', 'operator_ibot_bindings'];
    }
}
