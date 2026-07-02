<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Agent 智能体模块
 * 表: agents, agent_tools, agent_conversations, agent_conversation_messages, agent_tool_logs
 */
class AgentModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('agents', function (Blueprint $table) {
            $table->bigInteger('agent_id')->unsigned()->primary();
            $table->bigInteger('tenant_id')->unsigned();
            $table->string('name', 100);
            $table->string('role', 50);
            $table->string('avatar', 500)->nullable();
            $table->text('system_prompt');
            $table->text('description')->nullable();
            $table->json('tools')->nullable();
            $table->json('kb_ids')->nullable();
            $table->json('feature_keys')->nullable();
            $table->json('model_config');
            $table->boolean('enabled')->default(true);
            $table->boolean('is_builtin')->default(false);
            $table->json('metadata')->nullable();
            $table->integer('version')->default(1);
            $table->timestamps();

            $table->index(['tenant_id']);
            $table->index(['tenant_id', 'role']);
            $table->index(['tenant_id', 'enabled']);
        });

        Schema::create('agent_tools', function (Blueprint $table) {
            $table->bigInteger('tool_id')->unsigned()->primary();
            $table->bigInteger('tenant_id')->unsigned()->default(0);
            $table->string('name', 100);
            $table->string('slug', 100)->unique();
            $table->text('description');
            $table->string('category', 50)->nullable();
            $table->json('parameters_schema');
            $table->string('handler_class', 255);
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index('slug');
            $table->index('tenant_id');
        });

        Schema::create('agent_conversations', function (Blueprint $table) {
            $table->bigInteger('conversation_id')->unsigned()->primary();
            $table->bigInteger('agent_id')->unsigned();
            $table->bigInteger('tenant_id')->unsigned();
            $table->bigInteger('customer_id')->unsigned()->nullable();
            $table->bigInteger('staff_id')->unsigned()->nullable();
            $table->string('channel', 20)->default('web');
            $table->string('subject', 255)->nullable();
            $table->string('status', 20)->default('active');
            $table->text('summary')->nullable();
            $table->json('token_usage')->nullable();
            $table->integer('message_count')->default(0);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('agent_id');
            $table->index('tenant_id');
            $table->index('customer_id');
            $table->index('status');
        });

        Schema::create('agent_conversation_messages', function (Blueprint $table) {
            $table->bigInteger('message_id')->unsigned()->primary();
            $table->bigInteger('conversation_id')->unsigned();
            $table->enum('role', ['user', 'assistant', 'tool', 'system']);
            $table->text('content')->nullable();
            $table->json('tool_calls')->nullable();
            $table->string('tool_call_id', 100)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('conversation_id');
            $table->index(['conversation_id', 'created_at']);
        });

        Schema::create('agent_tool_logs', function (Blueprint $table) {
            $table->bigInteger('log_id')->unsigned()->primary();
            $table->bigInteger('conversation_id')->unsigned();
            $table->bigInteger('agent_id')->unsigned();
            $table->string('tool_name', 100);
            $table->json('input')->nullable();
            $table->json('output')->nullable();
            $table->integer('duration_ms')->default(0);
            $table->string('status', 20)->default('success');
            $table->text('error')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index('conversation_id');
            $table->index('agent_id');
            $table->index(['tool_name', 'created_at']);
        });
    }

    public function getTableNames(): array
    {
        return ['agents', 'agent_tools', 'agent_conversations', 'agent_conversation_messages', 'agent_tool_logs'];
    }
}
