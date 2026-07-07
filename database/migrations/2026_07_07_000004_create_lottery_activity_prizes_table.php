<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lottery_activity_prizes', function (Blueprint $table) {
            $table->unsignedBigInteger('prize_id')->primary()->comment('奖品ID（IdGenerator 全局ID）');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->unsignedBigInteger('activity_id')->comment('所属活动ID');
            $table->string('name', 255)->comment('奖品名称');
            $table->string('image_url', 512)->nullable()->comment('奖品图片URL');
            $table->string('type', 20)->default('virtual')->comment('类型: physical/virtual/coupon/credit');
            $table->decimal('value', 12, 2)->default(0)->comment('奖品价值');
            $table->unsignedInteger('total_count')->default(0)->comment('奖品总量');
            $table->unsignedInteger('remaining_count')->default(0)->comment('剩余数量（乐观锁）');
            $table->unsignedInteger('version')->default(0)->comment('乐观锁版本号');
            $table->decimal('probability', 8, 6)->default(0)->comment('中奖概率 0~1');
            $table->unsignedInteger('weight')->default(1)->comment('权重（用于加权随机）');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('排序');
            $table->timestamps();

            $table->index(['tenant_id', 'activity_id']);
            $table->index(['activity_id', 'remaining_count']);

            $table->foreign('tenant_id')->references('tenant_id')->on('tenants')->onDelete('cascade');
            $table->foreign('activity_id')->references('activity_id')->on('lottery_activities')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lottery_activity_prizes');
    }
};
