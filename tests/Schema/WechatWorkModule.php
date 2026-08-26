<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 企业微信服务商代开发模块
 * 表: service_providers, wechat_work_authorizations
 */
class WechatWorkModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        // 与生产迁移保持一致：平台级服务商凭证（tenant_id=null 系统级）
        Schema::create('service_providers', function (Blueprint $table) {
            $table->unsignedBigInteger('service_provider_id')->primary();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('name', 100);
            $table->string('provider_corp_id', 64)->nullable();
            $table->string('suite_id', 64);
            $table->text('suite_secret')->nullable();
            $table->string('callback_token', 255)->nullable();
            $table->string('encoding_aes_key', 255)->nullable();
            $table->string('callback_url', 500)->nullable();
            $table->string('status', 20)->default('active');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('status', 'service_providers_status_index');
        });

        // 租户代开发授权（permanent_code 加密存储，一租户一条）
        Schema::create('wechat_work_authorizations', function (Blueprint $table) {
            $table->unsignedBigInteger('authorization_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('service_provider_id');
            $table->string('corp_id', 64);
            $table->string('agent_id', 64)->nullable();
            $table->text('permanent_code')->nullable();
            $table->string('status', 20)->default('pending');
            $table->timestamp('authorized_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique('tenant_id', 'wechat_work_authorizations_tenant_unique');
            $table->index('corp_id', 'wechat_work_authorizations_corp_id_index');
            $table->index('status', 'wechat_work_authorizations_status_index');
        });
    }

    public function getTableNames(): array
    {
        return ['service_providers', 'wechat_work_authorizations'];
    }
}
