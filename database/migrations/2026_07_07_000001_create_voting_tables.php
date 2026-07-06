<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('voting_topics', function (Blueprint $table) {
            $table->unsignedBigInteger('topic_id')->primary()->comment('投票主题ID');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->string('title')->comment('标题');
            $table->string('slug', 100)->nullable()->comment('标识');
            $table->text('description')->nullable()->comment('描述');
            $table->string('status', 20)->default('draft')->comment('状态: draft/active/closed');
            $table->json('rules')->nullable()->comment('规则');
            $table->timestamp('start_at')->nullable()->comment('开始时间');
            $table->timestamp('end_at')->nullable()->comment('结束时间');
            $table->unsignedInteger('total_votes')->default(0)->comment('总投票数');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
        });

        Schema::create('voting_options', function (Blueprint $table) {
            $table->unsignedBigInteger('option_id')->primary()->comment('选项ID');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->unsignedBigInteger('topic_id')->comment('主题ID');
            $table->string('title')->comment('选项标题');
            $table->string('image_url')->nullable()->comment('选项图片');
            $table->text('description')->nullable()->comment('选项描述');
            $table->unsignedInteger('vote_count')->default(0)->comment('投票数');
            $table->unsignedSmallInteger('sort_order')->default(0)->comment('排序');
            $table->timestamps();

            $table->foreign('topic_id')->references('topic_id')->on('voting_topics')->onDelete('cascade');
            $table->index(['tenant_id', 'topic_id']);
        });

        Schema::create('voting_records', function (Blueprint $table) {
            $table->unsignedBigInteger('record_id')->primary()->comment('投票记录ID');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->unsignedBigInteger('topic_id')->comment('主题ID');
            $table->unsignedBigInteger('option_id')->comment('选项ID');
            $table->unsignedBigInteger('user_id')->nullable()->comment('用户ID');
            $table->string('user_ip', 45)->nullable()->comment('用户IP');
            $table->string('user_agent')->nullable()->comment('User Agent');
            $table->timestamp('voted_at')->nullable()->comment('投票时间');
            $table->timestamp('created_at')->nullable()->comment('创建时间');

            $table->foreign('topic_id')->references('topic_id')->on('voting_topics')->onDelete('cascade');
            $table->foreign('option_id')->references('option_id')->on('voting_options')->onDelete('cascade');
            $table->index(['tenant_id', 'topic_id']);
            $table->index(['topic_id', 'user_id']);
        });

        Schema::create('voting_blacklist', function (Blueprint $table) {
            $table->unsignedBigInteger('blacklist_id')->primary()->comment('黑名单ID');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->unsignedBigInteger('topic_id')->comment('主题ID');
            $table->string('identifier_type', 20)->comment('标识类型: user_id/ip/device');
            $table->string('identifier', 255)->comment('标识');
            $table->string('reason')->nullable()->comment('原因');
            $table->timestamp('created_at')->nullable()->comment('创建时间');

            $table->foreign('topic_id')->references('topic_id')->on('voting_topics')->onDelete('cascade');
            $table->index(['tenant_id', 'topic_id', 'identifier_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('voting_blacklist');
        Schema::dropIfExists('voting_records');
        Schema::dropIfExists('voting_options');
        Schema::dropIfExists('voting_topics');
    }
};
