<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lotteries', function (Blueprint $table) {
            $table->unsignedBigInteger('lottery_id')->primary();
            $table->unsignedBigInteger('tenant_id')->comment('租户 ID');
            $table->string('title')->comment('抽奖标题');
            $table->string('description')->nullable()->comment('抽奖描述');
            $table->string('status', 20)->default('draft')->comment('状态: draft/active/ended');
            $table->timestamp('start_at')->comment('开始时间');
            $table->timestamp('end_at')->comment('结束时间');
            $table->unsignedInteger('daily_limit')->default(0)->comment('每日总参与次数上限，0=不限');
            $table->unsignedInteger('total_limit')->default(0)->comment('总参与次数上限，0=不限');
            $table->unsignedInteger('daily_limit_per_user')->default(0)->comment('每用户每日限制');
            $table->unsignedInteger('total_limit_per_user')->default(0)->comment('每用户总限制');
            $table->boolean('anti_cheat_ip')->default(true)->comment('是否启用 IP 防刷');
            $table->unsignedInteger('no_prize_probability')->default(0)->comment('未中奖概率权重(千分比)');
            $table->unsignedSmallInteger('prize_show_count')->default(8)->comment('奖品展示数量');
            $table->unsignedInteger('total_draws')->default(0)->comment('总抽奖次数');
            $table->unsignedInteger('total_wins')->default(0)->comment('总中奖次数');
            $table->json('metadata')->nullable()->comment('附加元数据');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('status');
        });

        Schema::create('lottery_prizes', function (Blueprint $table) {
            $table->unsignedBigInteger('prize_id')->primary();
            $table->unsignedBigInteger('lottery_id')->comment('所属抽奖');
            $table->string('name')->comment('奖品名称');
            $table->string('image')->nullable()->comment('奖品图片');
            $table->string('prize_type', 20)->default('physical')->comment('奖品类型: physical/virtual/coupon');
            $table->unsignedInteger('probability')->default(0)->comment('中奖概率权重(千分比)');
            $table->unsignedInteger('stock')->default(0)->comment('库存');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('排序');
            $table->json('metadata')->nullable()->comment('附加元数据');
            $table->timestamps();

            $table->foreign('lottery_id')->references('lottery_id')->on('lotteries')->onDelete('cascade');
            $table->index('lottery_id');
        });

        Schema::create('lottery_records', function (Blueprint $table) {
            $table->unsignedBigInteger('record_id')->primary();
            $table->unsignedBigInteger('lottery_id')->comment('所属抽奖');
            $table->unsignedBigInteger('prize_id')->nullable()->comment('中奖奖品');
            $table->unsignedBigInteger('user_id')->comment('参与用户');
            $table->unsignedBigInteger('tenant_id')->comment('租户 ID');
            $table->boolean('is_winner')->default(false)->comment('是否中奖');
            $table->string('prize_name')->default('谢谢参与')->comment('奖品名称');
            $table->string('ip_address', 45)->nullable()->comment('IP 地址');
            $table->string('user_agent')->nullable()->comment('User Agent');
            $table->timestamps();

            $table->foreign('lottery_id')->references('lottery_id')->on('lotteries')->onDelete('cascade');
            $table->index('lottery_id');
            $table->index('user_id');
            $table->index('tenant_id');
            $table->index('is_winner');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lottery_records');
        Schema::dropIfExists('lottery_prizes');
        Schema::dropIfExists('lotteries');
    }
};
