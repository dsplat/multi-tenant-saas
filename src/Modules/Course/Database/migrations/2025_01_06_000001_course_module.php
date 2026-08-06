<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 统一商品交易体系 Phase 3：课程学习模块
 *
 * - courses：课程本体（价格载体：price/points_price/sale_mode，免费课程 price=0）
 * - course_chapters：章节（text | video | file）
 * - course_entitlements：购买权益（订单支付后授予）
 * - learning_records：学习进度
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('courses')) {
            Schema::create('courses', function (Blueprint $table) {
                $table->bigInteger('course_id')->unsigned()->primary();
                $table->bigInteger('tenant_id')->unsigned();
                $table->string('title', 255);
                $table->string('cover', 500)->nullable();
                $table->text('description')->nullable();
                $table->decimal('price', 12, 2)->default(0)->comment('现金价（元），0=免费');
                $table->integer('points_price')->default(0)->comment('积分价，0=不支持积分支付');
                $table->string('sale_mode', 20)->default('cash')->comment('cash|points|mixed');
                $table->integer('completion_reward_points')->default(0)->comment('学完奖励积分');
                $table->string('status', 20)->default('draft')->comment('draft|published|offline');
                $table->json('metadata')->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('course_chapters')) {
            Schema::create('course_chapters', function (Blueprint $table) {
                $table->bigInteger('chapter_id')->unsigned()->primary();
                $table->bigInteger('tenant_id')->unsigned();
                $table->bigInteger('course_id')->unsigned();
                $table->integer('sort_order')->default(0);
                $table->string('title', 255);
                $table->string('type', 20)->default('text')->comment('text|video|file');
                $table->text('content')->nullable()->comment('文本内容');
                $table->string('file_url', 500)->nullable()->comment('视频/文件 URL');
                $table->timestamps();
                $table->softDeletes();
                $table->index(['tenant_id', 'course_id']);
            });
        }

        if (! Schema::hasTable('course_entitlements')) {
            Schema::create('course_entitlements', function (Blueprint $table) {
                $table->bigInteger('entitlement_id')->unsigned()->primary();
                $table->bigInteger('tenant_id')->unsigned();
                $table->bigInteger('user_id')->unsigned();
                $table->bigInteger('course_id')->unsigned();
                $table->bigInteger('order_id')->unsigned()->nullable()->comment('免费课程直授时为 NULL');
                $table->timestamps();
                $table->unique(['tenant_id', 'user_id', 'course_id'], 'course_entitlements_unique');
            });
        }

        if (! Schema::hasTable('learning_records')) {
            Schema::create('learning_records', function (Blueprint $table) {
                $table->bigInteger('record_id')->unsigned()->primary();
                $table->bigInteger('tenant_id')->unsigned();
                $table->bigInteger('user_id')->unsigned();
                $table->bigInteger('course_id')->unsigned();
                $table->bigInteger('chapter_id')->unsigned()->nullable();
                $table->integer('progress')->default(0)->comment('课程总进度 %');
                $table->json('completed_chapters')->nullable()->comment('已完成章节 ID 列表');
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'user_id', 'course_id'], 'learning_records_unique');
                $table->index(['tenant_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('learning_records');
        Schema::dropIfExists('course_entitlements');
        Schema::dropIfExists('course_chapters');
        Schema::dropIfExists('courses');
    }
};
