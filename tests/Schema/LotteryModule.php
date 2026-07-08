<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 抽奖模块
 * 表: lottery_activities, lottery_activity_prizes, lottery_draw_logs, lottery_blacklists
 */
class LotteryModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('lottery_activities', function (Blueprint $table) {
            $table->unsignedBigInteger('activity_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('title', 255);
            $table->string('slug', 128);
            $table->text('description')->nullable();
            $table->string('status', 20)->default('draft');
            $table->json('rules')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('end_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'slug']);
        });

        Schema::create('lottery_activity_prizes', function (Blueprint $table) {
            $table->unsignedBigInteger('prize_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('activity_id');
            $table->string('name', 255);
            $table->string('image_url', 512)->nullable();
            $table->string('type', 20)->default('virtual');
            $table->decimal('value', 12, 2)->default(0);
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('remaining_count')->default(0);
            $table->unsignedInteger('version')->default(0);
            $table->decimal('probability', 8, 6)->default(0);
            $table->unsignedInteger('weight')->default(1);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'activity_id']);
            $table->index(['activity_id', 'remaining_count']);
        });

        Schema::create('lottery_draw_logs', function (Blueprint $table) {
            $table->unsignedBigInteger('log_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('activity_id');
            $table->unsignedBigInteger('prize_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_ip', 45)->nullable();
            $table->string('user_agent', 512)->nullable();
            $table->string('result', 20)->default('miss');
            $table->timestamp('draw_at');
            $table->timestamps();

            $table->index(['tenant_id', 'activity_id']);
            $table->index(['activity_id', 'result']);
            $table->index(['user_id', 'activity_id']);
        });

        Schema::create('lottery_blacklists', function (Blueprint $table) {
            $table->unsignedBigInteger('blacklist_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('activity_id');
            $table->string('identifier_type', 20);
            $table->string('identifier', 255);
            $table->string('reason', 512)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'activity_id']);
            $table->index(['activity_id', 'identifier_type', 'identifier']);
            $table->unique(['activity_id', 'identifier_type', 'identifier'], 'lottery_blacklist_unique');
        });
    }

    public function getTableNames(): array
    {
        return ['lottery_activities', 'lottery_activity_prizes', 'lottery_draw_logs', 'lottery_blacklists'];
    }
}
