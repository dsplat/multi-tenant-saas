<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 课程模块（Course）
 * 表: courses, course_chapters, course_entitlements, learning_records
 */
class CourseModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('courses', function (Blueprint $table) {
            $table->unsignedBigInteger('course_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('title', 255);
            $table->string('cover', 500)->nullable();
            $table->text('description')->nullable();
            $table->decimal('price', 12, 2)->default(0);
            $table->integer('points_price')->default(0);
            $table->string('sale_mode', 20)->default('cash');
            $table->integer('completion_reward_points')->default(0);
            $table->string('status', 20)->default('draft');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('course_chapters', function (Blueprint $table) {
            $table->unsignedBigInteger('chapter_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('course_id');
            $table->integer('sort_order')->default(0);
            $table->string('title', 255);
            $table->string('type', 20)->default('text');
            $table->text('content')->nullable();
            $table->string('file_url', 500)->nullable();
            $table->json('unlock_rule')->nullable()->comment('解锁规则 {mode: time|sequence|prerequisite, config}');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'course_id']);
        });

        Schema::create('course_entitlements', function (Blueprint $table) {
            $table->unsignedBigInteger('entitlement_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('order_id')->nullable();
            $table->string('source', 20)->default('order')->comment('order|free|import|compensation|subscription');
            $table->timestamp('valid_until')->nullable()->comment('权益有效期，NULL=永久');
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'course_id'], 'course_entitlements_unique');
        });

        Schema::create('learning_records', function (Blueprint $table) {
            $table->unsignedBigInteger('record_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('course_id');
            $table->unsignedBigInteger('chapter_id')->nullable();
            $table->integer('progress')->default(0);
            $table->json('completed_chapters')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'user_id', 'course_id'], 'learning_records_unique');
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function getTableNames(): array
    {
        return ['courses', 'course_chapters', 'course_entitlements', 'learning_records'];
    }
}
