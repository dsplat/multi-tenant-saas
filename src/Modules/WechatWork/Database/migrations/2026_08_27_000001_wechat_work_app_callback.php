<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 代开发应用回调凭证（「开始代开发应用」在企微服务商后台配置的应用级回调，
        // 与模板回调（service_providers.callback_*）相互独立）
        Schema::table('wechat_work_authorizations', function (Blueprint $table) {
            $table->string('app_callback_token', 255)
                ->nullable()
                ->after('permanent_code')
                ->comment('应用回调 Token（开始代开发应用时企微侧生成/自定义）');
            // 加密存储，密文可能超 varchar(255)，用 text（同 service_providers.encoding_aes_key 先例）
            $table->text('app_encoding_aes_key')
                ->nullable()
                ->after('app_callback_token')
                ->comment('应用回调 EncodingAESKey（加密存储）');
            $table->string('app_callback_url', 500)
                ->nullable()
                ->after('app_encoding_aes_key')
                ->comment('应用回调 URL（平台统一回调域，带租户标识）');
        });
    }

    public function down(): void
    {
        Schema::table('wechat_work_authorizations', function (Blueprint $table) {
            $table->dropColumn(['app_callback_token', 'app_encoding_aes_key', 'app_callback_url']);
        });
    }
};
