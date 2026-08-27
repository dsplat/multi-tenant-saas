<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * 服务商模板级应用回调凭证
     *
     * 企微「创建代开发应用模板」时生成的 Token/EncodingAESKey（与 URL 一同
     * 在「开始代开发应用」时自动带出到每个企业的应用回调配置），平台侧一次
     * 录入后所有租户共用，无需逐企业回填。
     *
     * - app_callback_token     明文存储（同 callback_token）
     * - app_encoding_aes_key   加密存储（mutator，密文超 varchar 用 text，同 encoding_aes_key）
     *
     * 租户级覆盖（wechat_work_authorizations.app_callback_*）保留：非空时优先，
     * 为空回退模板级。
     */
    public function up(): void
    {
        Schema::table('service_providers', function (Blueprint $table) {
            $table->string('app_callback_token', 255)->nullable()->after('callback_url');
            $table->text('app_encoding_aes_key')->nullable()->after('app_callback_token');
        });
    }

    public function down(): void
    {
        Schema::table('service_providers', function (Blueprint $table) {
            $table->dropColumn(['app_callback_token', 'app_encoding_aes_key']);
        });
    }
};
