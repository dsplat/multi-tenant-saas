<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('oauth_accounts', function ($table) {
            // 微信 unionid（跨应用唯一标识，绑定开放平台后才有）
            $table->string('unionid', 64)->nullable()->after('provider_id')->index();
            // 微信 openid（应用级唯一标识，冗余存储便于查询）
            $table->string('openid', 128)->nullable()->after('unionid');
            // 应用 appid（区分同一 provider 下不同应用）
            $table->string('appid', 64)->nullable()->after('openid');
            // 小程序 session_key
            $table->string('session_key', 128)->nullable()->after('appid');
        });
    }

    public function down(): void
    {
        Schema::table('oauth_accounts', function ($table) {
            $table->dropIndex(['unionid']);
            $table->dropColumn(['unionid', 'openid', 'appid', 'session_key']);
        });
    }
};
