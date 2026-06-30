<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SSO 提供方表
 *
 * 租户级 SSO 配置：每个租户可配置自己的 IdP（SAML 2.0 / OIDC）。
 * 包含 IdP 元数据、证书、端点、属性映射等。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sso_providers', function (Blueprint $table) {
            $table->unsignedBigInteger('sso_provider_id')->primary();
            $table->bigInteger('tenant_id')->unsigned();
            $table->string('type', 20);                 // saml | oidc
            $table->string('name', 100);                // 租户内唯一标识
            $table->string('display_name', 200)->nullable();
            // 通用
            $table->string('entity_id', 500)->nullable();   // SAML EntityID / OIDC Issuer
            $table->string('metadata_url', 500)->nullable(); // IdP 元数据 URL
            $table->text('certificate')->nullable();        // IdP 公钥证书（验签）
            // SAML
            $table->string('sso_url', 500)->nullable();     // SAML SSO 重定向地址
            $table->string('slo_url', 500)->nullable();     // SAML 单点登出地址
            // OIDC
            $table->string('client_id', 200)->nullable();
            $table->text('client_secret')->nullable();
            $table->string('authorize_url', 500)->nullable();
            $table->string('token_url', 500)->nullable();
            $table->string('userinfo_url', 500)->nullable();
            $table->string('scope', 200)->default('openid profile email');
            // 属性映射：JSON，如 {"email":"email","name":"name","external_id":"sub"}
            $table->json('attribute_mapping')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->unique(['tenant_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sso_providers');
    }
};
