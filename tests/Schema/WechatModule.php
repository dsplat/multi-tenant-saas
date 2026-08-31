<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 微信第三方平台（服务商模式）模块
 * 表: wechat_component_providers, wechat_authorizations, wechat_message_templates, wechat_message_logs
 */
class WechatModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        // 与生产迁移保持一致：平台级组件凭证（tenant_id=null 系统级）
        Schema::create('wechat_component_providers', function (Blueprint $table) {
            $table->unsignedBigInteger('component_provider_id')->primary();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('name', 100);
            $table->string('component_appid', 32);
            // component_secret / encoding_aes_key 加密存储，密文超 varchar 用 text
            $table->text('component_secret')->nullable();
            $table->string('component_token', 255)->nullable();
            $table->text('encoding_aes_key')->nullable();
            $table->string('callback_url', 500)->nullable();
            $table->string('status', 20)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('component_appid', 'wechat_component_providers_appid_unique');
            $table->index('status', 'wechat_component_providers_status_index');
        });

        // 租户第三方平台授权（authorizer_refresh_token 加密存储，一租户一条）
        Schema::create('wechat_authorizations', function (Blueprint $table) {
            $table->unsignedBigInteger('authorization_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('component_provider_id');
            $table->string('authorizer_appid', 32);
            $table->string('authorizer_type', 20)->default('official_account');
            $table->text('authorizer_refresh_token')->nullable();
            $table->string('nickname', 128)->nullable();
            $table->string('head_img', 512)->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique('tenant_id', 'wechat_authorizations_tenant_unique');
            $table->unique('authorizer_appid', 'wechat_authorizations_appid_unique');
            $table->index('component_provider_id', 'wechat_authorizations_provider_index');
            $table->index('status', 'wechat_authorizations_status_index');
        });

        // 租户模板登记（业务 key → 微信模板 ID）
        Schema::create('wechat_message_templates', function (Blueprint $table) {
            $table->unsignedBigInteger('message_template_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('template_key', 64);
            $table->string('template_id', 64);
            $table->string('title', 128)->nullable();
            $table->text('content_example')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->unique(['tenant_id', 'template_key'], 'wechat_message_templates_tenant_key_unique');
            $table->index('status', 'wechat_message_templates_status_index');
        });

        // 发送记录（模板消息 / 客服消息统一）
        Schema::create('wechat_message_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('message_log_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('message_type', 20);
            $table->string('template_key', 64)->nullable();
            $table->string('openid', 64);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->text('content');
            $table->string('msg_id', 64)->nullable();
            $table->string('status', 20)->default('pending');
            $table->string('error_code', 32)->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status'], 'wechat_message_logs_tenant_status_index');
            $table->index(['tenant_id', 'created_at'], 'wechat_message_logs_tenant_created_index');
        });
    }

    public function getTableNames(): array
    {
        return ['wechat_component_providers', 'wechat_authorizations', 'wechat_message_templates', 'wechat_message_logs'];
    }
}
