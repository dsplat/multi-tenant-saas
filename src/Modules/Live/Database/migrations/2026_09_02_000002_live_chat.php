<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 直播模块二期：聊天消息记录（弹幕回调落库，供审计/回放侧栏）
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('live_chat_messages')) {
            Schema::create('live_chat_messages', function (Blueprint $table) {
                $table->bigInteger('message_id')->unsigned()->primary()->comment('IdGenerator 全局ID');
                $table->bigInteger('tenant_id')->unsigned();
                $table->bigInteger('room_id')->unsigned();
                $table->string('provider_msg_id', 100)->nullable()->comment('供给方消息ID（幂等去重）');
                $table->bigInteger('user_id')->unsigned()->nullable()->comment('本地学员ID（可回溯时填）');
                $table->string('nick', 100)->nullable();
                $table->text('content');
                $table->timestamp('sent_at')->nullable();
                $table->json('raw')->nullable()->comment('回调原始报文');
                $table->timestamps();
                $table->unique(['tenant_id', 'provider_msg_id'], 'live_chat_messages_unique');
                $table->index(['tenant_id', 'room_id', 'sent_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('live_chat_messages');
    }
};
