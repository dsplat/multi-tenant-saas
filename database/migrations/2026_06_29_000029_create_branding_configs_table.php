<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 白标品牌配置表（TASK-028）
 *
 * 租户自定义 Logo、配色、自定义域名、登录页样式与邮件模板品牌化。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branding_configs', function (Blueprint $table) {
            $table->unsignedBigInteger('branding_config_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('logo_url', 500)->nullable();
            $table->string('favicon_url', 500)->nullable();
            $table->string('primary_color', 20)->nullable();
            $table->string('secondary_color', 20)->nullable();
            $table->text('custom_css')->nullable();
            $table->string('custom_domain', 200)->nullable()->comment('自定义域名');
            $table->string('login_page_style', 20)->default('default')->comment('登录页样式');
            $table->string('email_template', 50)->default('default')->comment('邮件模板品牌化');
            $table->timestamps();
            $table->softDeletes();

            $table->unique('tenant_id', 'bc_tenant_unique');
            $table->unique('custom_domain', 'bc_domain_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branding_configs');
    }
};
