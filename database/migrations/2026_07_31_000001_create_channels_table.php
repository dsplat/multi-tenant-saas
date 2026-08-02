<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 框架级 channels 表：结构化存储租户频道凭证。
 *
 * ChannelManager 原有路径从 tenant_settings (group=payment/channel) 读取，
 * 本表提供结构化替代方案，下游项目可直接使用或扩展。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('channels')) {
            return;
        }

        Schema::create('channels', function (Blueprint $table) {
            $table->bigInteger('channel_id')->unsigned()->primary();
            $table->bigInteger('tenant_id')->unsigned();
            $table->string('type', 50)->comment('频道类型: wechat_work, telegram, wechat_official, sms');
            $table->string('name', 200)->nullable()->comment('频道显示名称');
            $table->string('app_id', 200)->nullable()->comment('应用ID / CorpID / Bot Username');
            $table->text('app_secret')->nullable()->comment('应用密钥（加密存储）');
            $table->string('agent_id', 100)->nullable()->comment('企微 AgentID 等');
            $table->string('callback_token', 200)->nullable();
            $table->string('encoding_aes_key', 200)->nullable();
            $table->string('status', 20)->default('active')->comment('active / inactive / error');
            $table->json('metadata')->nullable()->comment('扩展配置（webhook_url, proxy 等）');
            $table->timestamp('last_connected_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'type']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('channels');
    }
};
