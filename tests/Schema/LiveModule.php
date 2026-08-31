<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 直播模块（Live）
 * 表: live_rooms, live_view_records
 */
class LiveModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('live_rooms', function (Blueprint $table) {
            $table->bigInteger('room_id')->unsigned()->primary();
            $table->bigInteger('tenant_id')->unsigned();
            $table->string('title', 255);
            $table->string('cover', 500)->nullable();
            $table->bigInteger('course_id')->unsigned()->nullable();
            $table->string('provider', 30)->default('manual');
            $table->string('provider_room_id', 100)->nullable();
            $table->json('config')->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->timestamp('scheduled_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->string('replay_url', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'course_id']);
        });

        Schema::create('live_view_records', function (Blueprint $table) {
            $table->bigInteger('record_id')->unsigned()->primary();
            $table->bigInteger('tenant_id')->unsigned();
            $table->bigInteger('room_id')->unsigned();
            $table->bigInteger('user_id')->unsigned();
            $table->integer('duration_seconds')->default(0);
            $table->timestamp('first_view_at')->nullable();
            $table->timestamp('last_view_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'room_id', 'user_id'], 'live_view_records_unique');
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function getTableNames(): array
    {
        return ['live_rooms', 'live_view_records'];
    }
}
