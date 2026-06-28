<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User 表追加认证安全字段
 *
 * - password_changed_at：最近一次密码修改时间（用于密码过期策略）
 * - login_attempts：连续登录失败次数（用于暴力破解锁定）
 * - locked_until：账号锁定截止时间
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('password_changed_at')->nullable()->after('password');
            $table->unsignedInteger('login_attempts')->default(0)->after('password_changed_at');
            $table->timestamp('locked_until')->nullable()->after('login_attempts');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['password_changed_at', 'login_attempts', 'locked_until']);
        });
    }
};
