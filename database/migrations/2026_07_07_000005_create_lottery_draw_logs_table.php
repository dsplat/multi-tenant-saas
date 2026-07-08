<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lottery_draw_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('log_id')->primary()->comment('抽奖记录ID（IdGenerator 全局ID）');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->unsignedBigInteger('activity_id')->comment('活动ID');
            $table->unsignedBigInteger('prize_id')->nullable()->comment('中奖奖品ID（null=未中奖）');
            $table->unsignedBigInteger('user_id')->nullable()->comment('用户ID');
            $table->string('user_ip', 45)->nullable()->comment('用户IP');
            $table->string('user_agent', 512)->nullable()->comment('User-Agent');
            $table->string('result', 20)->default('miss')->comment('结果: win/miss/blacklist');
            $table->timestamp('draw_at')->comment('抽奖时间');
            $table->timestamps();

            $table->index(['tenant_id', 'activity_id']);
            $table->index(['activity_id', 'result']);
            $table->index(['user_id', 'activity_id']);
            $table->index('draw_at');

            $table->foreign('tenant_id')->references('tenant_id')->on('tenants')->onDelete('cascade');
            $table->foreign('activity_id')->references('activity_id')->on('lottery_activities')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lottery_draw_logs');
    }
};
