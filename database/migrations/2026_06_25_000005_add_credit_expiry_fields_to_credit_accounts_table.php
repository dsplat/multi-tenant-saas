<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_accounts', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('total_consumed')->comment('账户积分过期时间');
            $table->integer('expired_total')->default(0)->after('expires_at')->comment('累计过期积分');
            $table->timestamp('last_warning_at')->nullable()->after('expired_total')->comment('上次低余额预警时间');
            $table->boolean('auto_recharge_enabled')->default(false)->after('last_warning_at')->comment('是否启用自动充值');
            $table->integer('auto_recharge_threshold')->default(100)->after('auto_recharge_enabled')->comment('自动充值触发阈值');
            $table->integer('auto_recharge_amount')->default(1000)->after('auto_recharge_threshold')->comment('自动充值金额');
        });
    }

    public function down(): void
    {
        Schema::table('credit_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'expires_at',
                'expired_total',
                'last_warning_at',
                'auto_recharge_enabled',
                'auto_recharge_threshold',
                'auto_recharge_amount',
            ]);
        });
    }
};
