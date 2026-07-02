<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('archived_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('archived_message_id')->primary()->comment('存档消息ID');
            $table->unsignedBigInteger('tenant_id')->nullable()->comment('租户ID');
            $table->string('msg_id', 128)->comment('企业微信消息ID');
            $table->string('room_id', 128)->comment('群聊/会话ID');
            $table->string('msg_type', 32)->default('text')->comment('消息类型');
            $table->string('from_user', 128)->default('')->comment('发送者UserID');
            $table->json('content')->nullable()->comment('解密后的消息内容');
            $table->json('raw_data')->nullable()->comment('原始API返回数据');
            $table->unsignedBigInteger('seq')->default(0)->comment('消息序列号');
            $table->timestamp('create_time')->nullable()->comment('消息创建时间');
            $table->timestamps();

            $table->unique('msg_id');
            $table->index(['room_id', 'seq']);
            $table->index('tenant_id');
            $table->index('from_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('archived_messages');
    }
};
