<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 直播模块（一期：第三方 SaaS 集成，房间元数据 + 观看记录）
 *
 * - live_rooms：直播间（provider 适配器抽象；挂课程即复用权益与回放转化）
 * - live_view_records：观看记录（(room, user) 幂等，时长累计）
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('live_rooms')) {
            Schema::create('live_rooms', function (Blueprint $table) {
                $table->bigInteger('room_id')->unsigned()->primary()->comment('IdGenerator 全局ID');
                $table->bigInteger('tenant_id')->unsigned();
                $table->string('title', 255);
                $table->string('cover', 500)->nullable();
                $table->bigInteger('course_id')->unsigned()->nullable()->comment('挂载课程（复用权益/回放转化），NULL=公开直播');
                $table->string('provider', 30)->default('manual')->comment('manual|polyun|tencent');
                $table->string('provider_room_id', 100)->nullable()->comment('第三方平台房间ID');
                $table->json('config')->nullable()->comment('推流/播放地址等（按 provider 结构）');
                $table->string('status', 20)->default('scheduled')->comment('scheduled|living|ended');
                $table->timestamp('scheduled_at')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->string('replay_url', 500)->nullable()->comment('回放地址');
                $table->timestamps();
                $table->softDeletes();
                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'course_id']);
            });
        }

        if (! Schema::hasTable('live_view_records')) {
            Schema::create('live_view_records', function (Blueprint $table) {
                $table->bigInteger('record_id')->unsigned()->primary()->comment('IdGenerator 全局ID');
                $table->bigInteger('tenant_id')->unsigned();
                $table->bigInteger('room_id')->unsigned();
                $table->bigInteger('user_id')->unsigned();
                $table->integer('duration_seconds')->default(0)->comment('累计观看时长');
                $table->timestamp('first_view_at')->nullable();
                $table->timestamp('last_view_at')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'room_id', 'user_id'], 'live_view_records_unique');
                $table->index(['tenant_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('live_view_records');
        Schema::dropIfExists('live_rooms');
    }
};
