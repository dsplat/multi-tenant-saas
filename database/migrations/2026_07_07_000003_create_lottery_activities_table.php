<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lottery_activities', function (Blueprint $table) {
            $table->unsignedBigInteger('activity_id')->primary()->comment('抽奖活动ID（IdGenerator 全局ID）');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->string('title', 255)->comment('活动标题');
            $table->string('slug', 128)->comment('活动标识（URL友好）');
            $table->text('description')->nullable()->comment('活动描述');
            $table->string('status', 20)->default('draft')->comment('状态: draft/active/paused/ended');
            $table->json('rules')->nullable()->comment('规则配置: max_per_user, require_login, anti_bot 等');
            $table->timestamp('start_at')->nullable()->comment('开始时间');
            $table->timestamp('end_at')->nullable()->comment('结束时间');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'slug']);
            $table->index('start_at');
            $table->index('end_at');

            $table->foreign('tenant_id')->references('tenant_id')->on('tenants')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lottery_activities');
    }
};
