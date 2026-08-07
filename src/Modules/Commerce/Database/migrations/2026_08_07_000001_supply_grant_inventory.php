<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * P4 供货结算：supply_grants 库存化
 *
 * 一次划拨 = 一个批次：allocated_qty 为划拨总量，remaining_qty 可下发余量，
 * locked_qty 为用户下单锁定中的量（防超卖）。存量授权三字段默认 0，
 * 表示非库存型授权（内容包等），行为向后兼容。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('supply_grants', function (Blueprint $table) {
            $table->unsignedInteger('allocated_qty')->default(0)->comment('划拨总量（0=非库存型授权）')->after('settlement');
            $table->unsignedInteger('remaining_qty')->default(0)->comment('可下发余量')->after('allocated_qty');
            $table->unsignedInteger('locked_qty')->default(0)->comment('下单锁定中的量')->after('remaining_qty');
        });
    }

    public function down(): void
    {
        Schema::table('supply_grants', function (Blueprint $table) {
            $table->dropColumn(['allocated_qty', 'remaining_qty', 'locked_qty']);
        });
    }
};
