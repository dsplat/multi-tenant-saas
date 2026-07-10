<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 消息渠道模块
 * 表: conversations, participants, messages, reactions, mentions, read_states,
 *     conversation_sessions, conversation_tags, archived_messages
 */
class ChannelModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->unsignedBigInteger('conversation_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('created_by')->nullable();
            $table->string('type', 20)->default('support');
            $table->string('status', 20)->default('active');
            $table->string('title', 255)->nullable();
            $table->string('channel', 20)->default('web');
            $table->unsignedBigInteger('agent_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->integer('message_count')->default(0);
            $table->text('summary')->nullable();
            $table->timestamp('summary_updated_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'type']);
            $table->index(['created_by']);
            $table->index(['last_message_at']);
        });

        Schema::create('participants', function (Blueprint $table) {
            $table->unsignedBigInteger('participant_id')->primary();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('tenant_id');
            $table->string('role', 20)->default('member');
            $table->boolean('is_muted')->default(false);
            $table->timestamp('joined_at')->nullable();
            $table->timestamp('left_at')->nullable();
            $table->timestamp('last_read_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id']);
            $table->index(['tenant_id']);
        });

        Schema::create('messages', function (Blueprint $table) {
            $table->unsignedBigInteger('message_id')->primary();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('sender_id')->nullable();
            $table->string('sender_type', 20)->default('user');
            $table->string('type', 20)->default('text');
            $table->text('content')->nullable();
            $table->json('attachments')->nullable();
            $table->unsignedBigInteger('reply_to_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'created_at']);
            $table->index(['tenant_id']);
            $table->index(['sender_id']);
        });

        Schema::create('reactions', function (Blueprint $table) {
            $table->unsignedBigInteger('reaction_id')->primary();
            $table->unsignedBigInteger('message_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('tenant_id');
            $table->string('emoji', 20);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['message_id', 'user_id', 'emoji']);
            $table->index(['tenant_id']);
            $table->index(['user_id']);
        });

        Schema::create('mentions', function (Blueprint $table) {
            $table->unsignedBigInteger('mention_id')->primary();
            $table->unsignedBigInteger('message_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('tenant_id');
            $table->boolean('is_notified')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['message_id', 'user_id']);
            $table->index(['user_id', 'is_notified']);
            $table->index(['tenant_id']);
        });

        Schema::create('read_states', function (Blueprint $table) {
            $table->unsignedBigInteger('read_state_id')->primary();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->integer('unread_count')->default(0);
            $table->timestamp('last_read_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'user_id']);
            $table->index(['user_id']);
            $table->index(['tenant_id']);
        });

        Schema::create('conversation_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('session_id')->primary();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('user_id');
            $table->unsignedBigInteger('tenant_id');
            $table->string('status', 20)->default('active');
            $table->timestamp('connected_at')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'status']);
            $table->index(['user_id']);
            $table->index(['tenant_id']);
        });

        Schema::create('conversation_tags', function (Blueprint $table) {
            $table->unsignedBigInteger('conversation_tag_id')->primary();
            $table->unsignedBigInteger('conversation_id');
            $table->unsignedBigInteger('tenant_id');
            $table->string('tag', 50);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['conversation_id', 'tag']);
            $table->index(['tenant_id', 'tag']);
            $table->index(['tenant_id']);
        });

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

    public function getTableNames(): array
    {
        return [
            'conversations', 'participants', 'messages', 'reactions', 'mentions',
            'read_states', 'conversation_sessions', 'conversation_tags', 'archived_messages',
        ];
    }
}
