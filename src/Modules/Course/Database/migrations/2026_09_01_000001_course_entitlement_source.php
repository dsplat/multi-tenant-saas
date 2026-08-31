<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 课程权益来源标记 + 有效期
 *
 * 对标小鹅通 join_type（import/free/付费）：
 * - source：权益来源（订单购买/免费领取/外部导入/补偿/订阅）
 * - valid_until：有效期（NULL=永久），支撑训练营营期、专栏订阅等时限权益
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_entitlements', function (Blueprint $table) {
            $table->string('source', 20)->default('order')
                ->comment('order|free|import|compensation|subscription')
                ->after('order_id');
            $table->timestamp('valid_until')->nullable()
                ->comment('权益有效期，NULL=永久')
                ->after('source');
        });
    }

    public function down(): void
    {
        Schema::table('course_entitlements', function (Blueprint $table) {
            $table->dropColumn(['source', 'valid_until']);
        });
    }
};
