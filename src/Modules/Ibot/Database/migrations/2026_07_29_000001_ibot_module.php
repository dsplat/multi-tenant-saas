<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ibot 模块 — IM 机器人网关两表
 *
 * ibots：租户级机器人实例（凭证加密存储，transport 区分 webhook/longconn/ilink）
 * operator_ibot_bindings：operator ↔ 机器人绑定（external_id 为 IM 平台会话标识）
 *
 * 设计稿：docs/ibot.md 第三节。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ibots')) {
            Schema::create('ibots', function (Blueprint $table) {
                $table->unsignedBigInteger('ibot_id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->string('channel_type', 20)->comment('telegram/wechat_work/wechat_kf/wechat/dingtalk/feishu');
                $table->string('transport', 20)->default('webhook')->comment('webhook/longconn/ilink');
                $table->string('name', 128)->comment('机器人展示名');
                $table->text('credentials')->nullable()->comment('平台凭证（加密 JSON）');
                $table->string('webhook_secret', 128)->nullable()->comment('本系统为该 bot 生成的回调验签密钥');
                $table->unsignedBigInteger('agent_id')->nullable()->comment('背后的数字员工，null=system_secretary');
                $table->string('status', 20)->default('active')->comment('active/disabled');
                $table->timestamps();

                $table->index(['tenant_id', 'channel_type']);
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('operator_ibot_bindings')) {
            Schema::create('operator_ibot_bindings', function (Blueprint $table) {
                $table->unsignedBigInteger('binding_id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('operator_id');
                $table->unsignedBigInteger('ibot_id');
                $table->string('external_id', 128)->comment('operator 在 IM 平台的会话标识（如 TG chat_id）');
                $table->unsignedBigInteger('conversation_id')->nullable()->comment('承载对话的 agent_conversation');
                $table->boolean('is_default_channel')->default(false)->comment('系统通知默认出口');
                $table->string('status', 20)->default('active')->comment('pending/active/revoked');
                $table->timestamps();

                $table->unique(['operator_id', 'ibot_id'], 'ibot_bindings_operator_ibot_unique');
                $table->unique(['ibot_id', 'external_id'], 'ibot_bindings_ibot_external_unique');
                $table->index(['tenant_id', 'operator_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('operator_ibot_bindings');
        Schema::dropIfExists('ibots');
    }
};
