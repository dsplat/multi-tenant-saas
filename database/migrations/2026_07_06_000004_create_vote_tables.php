<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('votes', function (Blueprint $table) {
            $table->unsignedBigInteger('vote_id')->primary();
            $table->unsignedBigInteger('tenant_id')->comment('租户 ID');
            $table->string('title')->comment('投票标题');
            $table->string('description')->nullable()->comment('投票描述');
            $table->string('vote_type', 20)->default('single')->comment('投票类型: single/multiple');
            $table->string('status', 20)->default('draft')->comment('状态: draft/active/ended');
            $table->timestamp('start_at')->comment('开始时间');
            $table->timestamp('end_at')->comment('结束时间');
            $table->unsignedInteger('daily_limit')->default(0)->comment('每日总投票上限，0=不限');
            $table->unsignedInteger('total_limit')->default(0)->comment('总投票上限，0=不限');
            $table->unsignedInteger('daily_limit_per_user')->default(1)->comment('每用户每日限制');
            $table->unsignedInteger('total_limit_per_user')->default(0)->comment('每用户总限制');
            $table->boolean('anti_cheat_ip')->default(true)->comment('是否启用 IP 防刷');
            $table->boolean('show_result')->default(true)->comment('是否显示结果');
            $table->boolean('show_rank')->default(true)->comment('是否显示排行');
            $table->unsignedInteger('total_votes')->default(0)->comment('总投票数');
            $table->json('metadata')->nullable()->comment('附加元数据');
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('status');
        });

        Schema::create('vote_options', function (Blueprint $table) {
            $table->unsignedBigInteger('vote_option_id')->primary();
            $table->unsignedBigInteger('vote_id')->comment('所属投票');
            $table->string('title')->comment('选项标题');
            $table->string('image')->nullable()->comment('选项图片');
            $table->string('description')->nullable()->comment('选项描述');
            $table->unsignedInteger('vote_count')->default(0)->comment('得票数');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('排序');
            $table->json('metadata')->nullable()->comment('附加元数据');
            $table->timestamps();

            $table->foreign('vote_id')->references('vote_id')->on('votes')->onDelete('cascade');
            $table->index('vote_id');
        });

        Schema::create('vote_records', function (Blueprint $table) {
            $table->unsignedBigInteger('vote_record_id')->primary();
            $table->unsignedBigInteger('vote_id')->comment('所属投票');
            $table->unsignedBigInteger('vote_option_id')->comment('投票选项');
            $table->unsignedBigInteger('user_id')->comment('投票用户');
            $table->unsignedBigInteger('tenant_id')->comment('租户 ID');
            $table->string('ip_address', 45)->nullable()->comment('IP 地址');
            $table->string('user_agent')->nullable()->comment('User Agent');
            $table->timestamps();

            $table->foreign('vote_id')->references('vote_id')->on('votes')->onDelete('cascade');
            $table->foreign('vote_option_id')->references('vote_option_id')->on('vote_options')->onDelete('cascade');
            $table->index('vote_id');
            $table->index('vote_option_id');
            $table->index('user_id');
            $table->index('tenant_id');
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('vote_records');
        Schema::dropIfExists('vote_options');
        Schema::dropIfExists('votes');
    }
};