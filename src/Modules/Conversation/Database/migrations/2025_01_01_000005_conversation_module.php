<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Table: archived_messages
        DB::statement(<<<'SQL'
CREATE TABLE `archived_messages` (
  `archived_message_id` bigint unsigned NOT NULL COMMENT '存档消息ID',
  `tenant_id` bigint unsigned DEFAULT NULL COMMENT '租户ID',
  `msg_id` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '企业微信消息ID',
  `room_id` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '群聊/会话ID',
  `msg_type` varchar(32) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text' COMMENT '消息类型',
  `from_user` varchar(128) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '' COMMENT '发送者UserID',
  `content` json DEFAULT NULL COMMENT '解密后的消息内容',
  `raw_data` json DEFAULT NULL COMMENT '原始API返回数据',
  `seq` bigint unsigned NOT NULL DEFAULT '0' COMMENT '消息序列号',
  `create_time` timestamp NULL DEFAULT NULL COMMENT '消息创建时间',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`archived_message_id`),
  UNIQUE KEY `archived_messages_msg_id_unique` (`msg_id`),
  KEY `archived_messages_room_id_seq_index` (`room_id`,`seq`),
  KEY `archived_messages_tenant_id_index` (`tenant_id`),
  KEY `archived_messages_from_user_index` (`from_user`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: attachments
        DB::statement(<<<'SQL'
CREATE TABLE `attachments` (
  `attachment_id` bigint unsigned NOT NULL COMMENT '附件 ID（IdGenerator 全局ID）',
  `conversation_id` bigint unsigned NOT NULL COMMENT '会话 ID',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `uploaded_by` bigint unsigned NOT NULL COMMENT '上传者用户ID',
  `file_upload_id` bigint unsigned DEFAULT NULL COMMENT '关联的文件上传 ID',
  `filename` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '原始文件名',
  `mime_type` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'MIME 类型',
  `size` bigint unsigned NOT NULL DEFAULT '0' COMMENT '文件大小（字节）',
  `disk` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'local' COMMENT '存储磁盘: local/s3/oss',
  `path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '存储路径',
  `metadata` json DEFAULT NULL COMMENT '元数据',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`attachment_id`),
  KEY `attachments_conversation_id_index` (`conversation_id`),
  KEY `attachments_tenant_id_index` (`tenant_id`),
  KEY `attachments_uploaded_by_index` (`uploaded_by`),
  KEY `attachments_file_upload_id_index` (`file_upload_id`),
  CONSTRAINT `attachments_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`conversation_id`) ON DELETE CASCADE,
  CONSTRAINT `attachments_file_upload_id_foreign` FOREIGN KEY (`file_upload_id`) REFERENCES `file_uploads` (`file_upload_id`),
  CONSTRAINT `attachments_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`),
  CONSTRAINT `attachments_uploaded_by_foreign` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: conversation_sessions
        DB::statement(<<<'SQL'
CREATE TABLE `conversation_sessions` (
  `session_id` bigint unsigned NOT NULL COMMENT '会话会话 ID（IdGenerator 全局ID）',
  `conversation_id` bigint unsigned NOT NULL COMMENT '会话 ID',
  `user_id` bigint unsigned NOT NULL COMMENT '用户 ID',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT '会话状态: active/idle/disconnected',
  `connected_at` timestamp NULL DEFAULT NULL COMMENT '连接时间',
  `last_active_at` timestamp NULL DEFAULT NULL COMMENT '最后活跃时间',
  `metadata` json DEFAULT NULL COMMENT '元数据',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`session_id`),
  KEY `conversation_sessions_conversation_id_status_index` (`conversation_id`,`status`),
  KEY `conversation_sessions_user_id_index` (`user_id`),
  KEY `conversation_sessions_tenant_id_index` (`tenant_id`),
  CONSTRAINT `conversation_sessions_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`conversation_id`) ON DELETE CASCADE,
  CONSTRAINT `conversation_sessions_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`),
  CONSTRAINT `conversation_sessions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: conversation_tags
        DB::statement(<<<'SQL'
CREATE TABLE `conversation_tags` (
  `conversation_tag_id` bigint unsigned NOT NULL COMMENT '标签 ID（IdGenerator 全局ID）',
  `conversation_id` bigint unsigned NOT NULL COMMENT '会话 ID',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `tag` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '标签名称',
  `metadata` json DEFAULT NULL COMMENT '元数据',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`conversation_tag_id`),
  UNIQUE KEY `conversation_tags_conversation_id_tag_unique` (`conversation_id`,`tag`),
  KEY `conversation_tags_tenant_id_tag_index` (`tenant_id`,`tag`),
  KEY `conversation_tags_tenant_id_index` (`tenant_id`),
  CONSTRAINT `conversation_tags_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`conversation_id`) ON DELETE CASCADE,
  CONSTRAINT `conversation_tags_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: conversations
        DB::statement(<<<'SQL'
CREATE TABLE `conversations` (
  `conversation_id` bigint unsigned NOT NULL COMMENT '会话 ID（IdGenerator 全局ID）',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `created_by` bigint unsigned DEFAULT NULL COMMENT '创建者用户ID',
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'support' COMMENT '会话类型: support/group/direct',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT '会话状态: active/closed/archived',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '会话标题',
  `channel` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'web' COMMENT '会话渠道',
  `agent_id` bigint unsigned DEFAULT NULL COMMENT '分配的 Agent ID',
  `last_message_at` timestamp NULL DEFAULT NULL COMMENT '最后消息时间',
  `message_count` int NOT NULL DEFAULT '0' COMMENT '消息计数',
  `summary` text COLLATE utf8mb4_unicode_ci COMMENT '会话摘要',
  `summary_updated_at` timestamp NULL DEFAULT NULL COMMENT '摘要更新时间',
  `metadata` json DEFAULT NULL COMMENT '元数据',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`conversation_id`),
  KEY `conversations_tenant_id_status_index` (`tenant_id`,`status`),
  KEY `conversations_tenant_id_type_index` (`tenant_id`,`type`),
  KEY `conversations_created_by_index` (`created_by`),
  KEY `conversations_agent_id_index` (`agent_id`),
  KEY `conversations_last_message_at_index` (`last_message_at`),
  CONSTRAINT `conversations_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`agent_id`) ON DELETE SET NULL,
  CONSTRAINT `conversations_created_by_foreign` FOREIGN KEY (`created_by`) REFERENCES `users` (`user_id`) ON DELETE SET NULL,
  CONSTRAINT `conversations_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: mentions
        DB::statement(<<<'SQL'
CREATE TABLE `mentions` (
  `mention_id` bigint unsigned NOT NULL COMMENT '提及 ID（IdGenerator 全局ID）',
  `message_id` bigint unsigned NOT NULL COMMENT '消息 ID',
  `user_id` bigint unsigned NOT NULL COMMENT '被提及用户 ID',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `is_notified` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否已通知',
  `metadata` json DEFAULT NULL COMMENT '元数据',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`mention_id`),
  UNIQUE KEY `mentions_message_id_user_id_unique` (`message_id`,`user_id`),
  KEY `mentions_user_id_is_notified_index` (`user_id`,`is_notified`),
  KEY `mentions_tenant_id_index` (`tenant_id`),
  CONSTRAINT `mentions_message_id_foreign` FOREIGN KEY (`message_id`) REFERENCES `messages` (`message_id`) ON DELETE CASCADE,
  CONSTRAINT `mentions_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`),
  CONSTRAINT `mentions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: messages
        DB::statement(<<<'SQL'
CREATE TABLE `messages` (
  `message_id` bigint unsigned NOT NULL COMMENT '消息 ID（IdGenerator 全局ID）',
  `conversation_id` bigint unsigned NOT NULL COMMENT '会话 ID',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `sender_id` bigint unsigned DEFAULT NULL COMMENT '发送者用户ID',
  `sender_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'user' COMMENT '发送者类型: user/agent/system',
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'text' COMMENT '消息类型: text/image/file/system',
  `content` text COLLATE utf8mb4_unicode_ci COMMENT '消息内容',
  `attachments` json DEFAULT NULL COMMENT '附件列表 JSON',
  `metadata` json DEFAULT NULL COMMENT '元数据',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`message_id`),
  KEY `messages_conversation_id_created_at_index` (`conversation_id`,`created_at`),
  KEY `messages_tenant_id_index` (`tenant_id`),
  KEY `messages_sender_id_index` (`sender_id`),
  CONSTRAINT `messages_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`conversation_id`) ON DELETE CASCADE,
  CONSTRAINT `messages_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: participants
        DB::statement(<<<'SQL'
CREATE TABLE `participants` (
  `participant_id` bigint unsigned NOT NULL COMMENT '参与者 ID（IdGenerator 全局ID）',
  `conversation_id` bigint unsigned NOT NULL COMMENT '会话 ID',
  `user_id` bigint unsigned NOT NULL COMMENT '用户 ID',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `role` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'member' COMMENT '参与者角色: member/agent/admin/guest',
  `is_muted` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否静音',
  `left_at` timestamp NULL DEFAULT NULL COMMENT '离开时间',
  `last_read_at` timestamp NULL DEFAULT NULL COMMENT '最后已读时间',
  `metadata` json DEFAULT NULL COMMENT '元数据',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`participant_id`),
  UNIQUE KEY `participants_conversation_id_user_id_unique` (`conversation_id`,`user_id`),
  KEY `participants_user_id_index` (`user_id`),
  KEY `participants_tenant_id_index` (`tenant_id`),
  CONSTRAINT `participants_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`conversation_id`) ON DELETE CASCADE,
  CONSTRAINT `participants_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`),
  CONSTRAINT `participants_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: reactions
        DB::statement(<<<'SQL'
CREATE TABLE `reactions` (
  `reaction_id` bigint unsigned NOT NULL COMMENT '回应 ID（IdGenerator 全局ID）',
  `message_id` bigint unsigned NOT NULL COMMENT '消息 ID',
  `user_id` bigint unsigned NOT NULL COMMENT '用户 ID',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `emoji` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '表情符号',
  `metadata` json DEFAULT NULL COMMENT '元数据',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`reaction_id`),
  UNIQUE KEY `reactions_message_id_user_id_emoji_unique` (`message_id`,`user_id`,`emoji`),
  KEY `reactions_tenant_id_index` (`tenant_id`),
  KEY `reactions_user_id_index` (`user_id`),
  CONSTRAINT `reactions_message_id_foreign` FOREIGN KEY (`message_id`) REFERENCES `messages` (`message_id`) ON DELETE CASCADE,
  CONSTRAINT `reactions_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`),
  CONSTRAINT `reactions_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: read_states
        DB::statement(<<<'SQL'
CREATE TABLE `read_states` (
  `read_state_id` bigint unsigned NOT NULL COMMENT '已读状态 ID（IdGenerator 全局ID）',
  `conversation_id` bigint unsigned NOT NULL COMMENT '会话 ID',
  `user_id` bigint unsigned NOT NULL COMMENT '用户 ID',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `last_read_message_id` bigint unsigned DEFAULT NULL COMMENT '最后已读消息 ID',
  `unread_count` int NOT NULL DEFAULT '0' COMMENT '未读消息数',
  `last_read_at` timestamp NULL DEFAULT NULL COMMENT '最后已读时间',
  `metadata` json DEFAULT NULL COMMENT '元数据',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`read_state_id`),
  UNIQUE KEY `read_states_conversation_id_user_id_unique` (`conversation_id`,`user_id`),
  KEY `read_states_user_id_index` (`user_id`),
  KEY `read_states_tenant_id_index` (`tenant_id`),
  CONSTRAINT `read_states_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `conversations` (`conversation_id`) ON DELETE CASCADE,
  CONSTRAINT `read_states_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`),
  CONSTRAINT `read_states_user_id_foreign` FOREIGN KEY (`user_id`) REFERENCES `users` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        Schema::dropIfExists('archived_messages');
        Schema::dropIfExists('attachments');
        Schema::dropIfExists('conversation_sessions');
        Schema::dropIfExists('conversation_tags');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('mentions');
        Schema::dropIfExists('messages');
        Schema::dropIfExists('participants');
        Schema::dropIfExists('reactions');
        Schema::dropIfExists('read_states');
    }
};
