<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Table: agent_conversation_messages
        DB::statement(<<<'SQL'
CREATE TABLE `agent_conversation_messages` (
  `message_id` bigint unsigned NOT NULL COMMENT '消息 ID（IdGenerator 全局ID）',
  `conversation_id` bigint unsigned NOT NULL COMMENT '会话 ID',
  `role` enum('user','assistant','tool','system') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '消息角色',
  `content` text COLLATE utf8mb4_unicode_ci COMMENT '消息内容',
  `tool_calls` json DEFAULT NULL COMMENT '工具调用（OpenAI 结构）',
  `tool_call_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '工具调用 ID（tool 角色消息）',
  `metadata` json DEFAULT NULL COMMENT '元数据',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`message_id`),
  KEY `agent_conversation_messages_conversation_id_index` (`conversation_id`),
  KEY `agent_conversation_messages_conversation_id_created_at_index` (`conversation_id`,`created_at`),
  CONSTRAINT `agent_conversation_messages_conversation_id_foreign` FOREIGN KEY (`conversation_id`) REFERENCES `agent_conversations` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: agent_conversations
        DB::statement(<<<'SQL'
CREATE TABLE `agent_conversations` (
  `conversation_id` bigint unsigned NOT NULL COMMENT '会话 ID（IdGenerator 全局ID）',
  `agent_id` bigint unsigned NOT NULL COMMENT 'Agent ID',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `customer_id` bigint unsigned DEFAULT NULL COMMENT '客户ID（业务层）',
  `staff_id` bigint unsigned DEFAULT NULL COMMENT '坐席ID（业务层）',
  `channel` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'web' COMMENT '会话渠道',
  `subject` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '会话主题',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT '会话状态',
  `summary` text COLLATE utf8mb4_unicode_ci COMMENT '会话摘要',
  `token_usage` json DEFAULT NULL COMMENT 'Token 用量统计',
  `message_count` int NOT NULL DEFAULT '0' COMMENT '消息计数',
  `metadata` json DEFAULT NULL COMMENT '元数据',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`conversation_id`),
  KEY `agent_conversations_agent_id_index` (`agent_id`),
  KEY `agent_conversations_tenant_id_index` (`tenant_id`),
  KEY `agent_conversations_customer_id_index` (`customer_id`),
  KEY `agent_conversations_status_index` (`status`),
  CONSTRAINT `agent_conversations_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`agent_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: agent_tool_logs
        DB::statement(<<<'SQL'
CREATE TABLE `agent_tool_logs` (
  `log_id` bigint unsigned NOT NULL COMMENT '日志 ID（IdGenerator 全局ID）',
  `conversation_id` bigint unsigned NOT NULL COMMENT '会话 ID',
  `agent_id` bigint unsigned NOT NULL COMMENT 'Agent ID',
  `tool_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '工具名称',
  `input` json DEFAULT NULL COMMENT '工具输入参数',
  `output` json DEFAULT NULL COMMENT '工具输出',
  `duration_ms` int NOT NULL DEFAULT '0' COMMENT '执行耗时（毫秒）',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'success' COMMENT '调用状态',
  `error` text COLLATE utf8mb4_unicode_ci COMMENT '错误信息',
  `created_at` timestamp NULL DEFAULT NULL COMMENT '创建时间',
  PRIMARY KEY (`log_id`),
  KEY `agent_tool_logs_conversation_id_index` (`conversation_id`),
  KEY `agent_tool_logs_agent_id_index` (`agent_id`),
  KEY `agent_tool_logs_tool_name_created_at_index` (`tool_name`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: agent_tools
        DB::statement(<<<'SQL'
CREATE TABLE `agent_tools` (
  `tool_id` bigint unsigned NOT NULL COMMENT '工具 ID（IdGenerator 全局ID）',
  `tenant_id` bigint unsigned NOT NULL DEFAULT '0' COMMENT '租户ID（0=全局工具）',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '工具名称',
  `slug` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '工具唯一标识',
  `description` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '工具描述',
  `category` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '工具分类',
  `parameters_schema` json NOT NULL COMMENT '参数 JSON Schema',
  `handler_class` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '处理类全限定名',
  `enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否启用',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`tool_id`),
  UNIQUE KEY `agent_tools_slug_unique` (`slug`),
  KEY `agent_tools_tenant_id_index` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: agent_workflows
        DB::statement(<<<'SQL'
CREATE TABLE `agent_workflows` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `agent_id` bigint unsigned NOT NULL COMMENT 'Agent ID',
  `workflow_id` bigint unsigned NOT NULL COMMENT 'Workflow ID',
  `is_primary` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否主工作流',
  `sort_order` int NOT NULL DEFAULT '0' COMMENT '排序',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `agent_workflows_agent_id_workflow_id_unique` (`agent_id`,`workflow_id`),
  KEY `agent_workflows_tenant_id_agent_id_index` (`tenant_id`,`agent_id`),
  KEY `agent_workflows_workflow_id_foreign` (`workflow_id`),
  CONSTRAINT `agent_workflows_agent_id_foreign` FOREIGN KEY (`agent_id`) REFERENCES `agents` (`agent_id`) ON DELETE CASCADE,
  CONSTRAINT `agent_workflows_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  CONSTRAINT `agent_workflows_workflow_id_foreign` FOREIGN KEY (`workflow_id`) REFERENCES `workflows` (`workflow_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: agents
        DB::statement(<<<'SQL'
CREATE TABLE `agents` (
  `agent_id` bigint unsigned NOT NULL COMMENT 'Agent ID（IdGenerator 全局ID）',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT 'Agent 名称',
  `role` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '角色标识',
  `avatar` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '头像 URL',
  `system_prompt` text COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '系统提示词',
  `description` text COLLATE utf8mb4_unicode_ci COMMENT '描述',
  `tools` json DEFAULT NULL COMMENT '工具 slug 列表',
  `kb_ids` json DEFAULT NULL COMMENT '知识库 ID 列表',
  `feature_keys` json DEFAULT NULL COMMENT '映射的 AI 功能点列表（业务层使用）',
  `model_config` json NOT NULL DEFAULT (_utf8mb4'{}') COMMENT '模型配置 JSON',
  `enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否启用',
  `is_builtin` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否内置',
  `metadata` json DEFAULT NULL COMMENT '元数据',
  `version` int NOT NULL DEFAULT '1' COMMENT '版本号',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`agent_id`),
  KEY `agents_tenant_id_index` (`tenant_id`),
  KEY `agents_tenant_id_role_index` (`tenant_id`,`role`),
  KEY `agents_tenant_id_enabled_index` (`tenant_id`,`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: ai_model_aliases
        DB::statement(<<<'SQL'
CREATE TABLE `ai_model_aliases` (
  `alias_id` bigint unsigned NOT NULL COMMENT '别名ID（全局ID，16位数字）',
  `alias` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '模型别名（友好名称）',
  `actual_model` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '实际模型名（对应 AiModelEnum 值或自定义模型）',
  `provider` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '提供商标识（可选，用于约束/路由）',
  `type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '类型: text/image/video',
  `is_active` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否激活',
  `is_deprecated` tinyint(1) NOT NULL DEFAULT '0' COMMENT '废弃标记',
  `description` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '说明',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`alias_id`),
  UNIQUE KEY `uk_alias` (`alias`),
  KEY `idx_provider_type` (`provider`,`type`),
  KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: ai_prompts
        DB::statement(<<<'SQL'
CREATE TABLE `ai_prompts` (
  `prompt_id` bigint unsigned NOT NULL COMMENT '提示词ID（全局ID，16位数字）',
  `tenant_id` bigint unsigned DEFAULT NULL COMMENT '租户ID，null 表示系统级模板',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '模板名称（同租户内唯一，租户可同名覆盖系统级）',
  `category` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'general' COMMENT '分类',
  `system_prompt` text COLLATE utf8mb4_unicode_ci COMMENT '系统提示词',
  `user_prompt` text COLLATE utf8mb4_unicode_ci COMMENT '用户提示词模板（含 {{变量}} 占位符）',
  `variables` json DEFAULT NULL COMMENT '变量定义 JSON：[{name,description,required}]',
  `version` int unsigned NOT NULL DEFAULT '1' COMMENT '版本号',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT '状态: active/inactive',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`prompt_id`),
  KEY `idx_tenant_name` (`tenant_id`,`name`),
  KEY `idx_category` (`category`),
  KEY `idx_ai_prompts_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: ai_providers
        DB::statement(<<<'SQL'
CREATE TABLE `ai_providers` (
  `provider_id` bigint unsigned NOT NULL COMMENT '提供商ID（全局ID，16位数字）',
  `tenant_id` bigint unsigned DEFAULT NULL COMMENT '租户ID，null 表示系统级配置',
  `code` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '提供商标识（openai/zhipu/anthropic 等），对应 config(ai.providers) 键名',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '提供商显示名称',
  `base_url` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'API 基地址',
  `api_key` text COLLATE utf8mb4_unicode_ci COMMENT '默认 API Key（加密存储）',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT '状态: active/inactive',
  `priority` smallint NOT NULL DEFAULT '0' COMMENT '优先级，数字越小越优先',
  `metadata` json DEFAULT NULL COMMENT '扩展配置（超时、额外参数等）',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`provider_id`),
  UNIQUE KEY `uk_tenant_code` (`tenant_id`,`code`),
  KEY `idx_status` (`status`),
  KEY `idx_priority` (`priority`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: ai_requests
        DB::statement(<<<'SQL'
CREATE TABLE `ai_requests` (
  `request_id` bigint unsigned NOT NULL COMMENT '请求ID（全局ID，16位数字）',
  `tenant_id` bigint unsigned DEFAULT NULL COMMENT '租户ID，实现租户隔离',
  `user_id` bigint unsigned DEFAULT NULL COMMENT '用户ID',
  `model` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '模型名（对应 AiModelEnum 值或自定义模型）',
  `provider` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '提供商标识',
  `prompt_summary` text COLLATE utf8mb4_unicode_ci COMMENT '请求内容摘要',
  `input_tokens` int unsigned NOT NULL DEFAULT '0' COMMENT '输入 Token 用量',
  `output_tokens` int unsigned NOT NULL DEFAULT '0' COMMENT '输出 Token 用量',
  `response_time_ms` int unsigned DEFAULT NULL COMMENT '响应时间（毫秒）',
  `cost` decimal(12,6) NOT NULL DEFAULT '0.000000' COMMENT '费用',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT '状态: pending/success/failed',
  `error_message` text COLLATE utf8mb4_unicode_ci COMMENT '错误信息（失败时）',
  `metadata` json DEFAULT NULL COMMENT '扩展元数据（finish_reason、options 摘要等）',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`request_id`),
  KEY `idx_tenant_created` (`tenant_id`,`created_at`),
  KEY `idx_tenant_model` (`tenant_id`,`model`),
  KEY `idx_tenant_provider` (`tenant_id`,`provider`),
  KEY `idx_user` (`user_id`),
  KEY `idx_tenant_status` (`tenant_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: ai_tenant_configs
        DB::statement(<<<'SQL'
CREATE TABLE `ai_tenant_configs` (
  `ai_tenant_config_id` bigint unsigned NOT NULL COMMENT '配置ID（全局ID，16位数字）',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `text_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否启用文本 AI',
  `image_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否启用图片 AI',
  `video_enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否启用视频 AI',
  `custom_api_keys` json DEFAULT NULL COMMENT '自定义 API Key：{provider: key}，覆盖系统默认',
  `allowed_models` json DEFAULT NULL COMMENT '允许租户使用的模型列表，null 表示继承系统默认',
  `monthly_budget_limit` decimal(12,2) NOT NULL DEFAULT '0.00' COMMENT '月度预算上限（0 表示不限）',
  `overage_action` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'block' COMMENT '超额处理: block/warn/allow',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`ai_tenant_config_id`),
  UNIQUE KEY `uniq_tenant` (`tenant_id`),
  KEY `ai_tenant_configs_text_enabled_index` (`text_enabled`),
  KEY `ai_tenant_configs_image_enabled_index` (`image_enabled`),
  KEY `ai_tenant_configs_video_enabled_index` (`video_enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: ai_usage_quotas
        DB::statement(<<<'SQL'
CREATE TABLE `ai_usage_quotas` (
  `ai_usage_quota_id` bigint unsigned NOT NULL COMMENT '配额ID（全局ID，16位数字）',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `subscription_plan_id` bigint unsigned DEFAULT NULL COMMENT '套餐ID',
  `text_token_limit` bigint unsigned NOT NULL DEFAULT '0' COMMENT '文本 Token 月度上限（0 表示不限）',
  `image_generation_limit` bigint unsigned NOT NULL DEFAULT '0' COMMENT '图片生成月度上限（0 表示不限）',
  `video_duration_limit` bigint unsigned NOT NULL DEFAULT '0' COMMENT '视频时长月度上限（秒，0 表示不限）',
  `period` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'monthly' COMMENT '计费周期标识，如 monthly:2026-06',
  `used_tokens` bigint unsigned NOT NULL DEFAULT '0' COMMENT '已用 Token 数',
  `used_images` bigint unsigned NOT NULL DEFAULT '0' COMMENT '已生成图片数',
  `used_video_seconds` bigint unsigned NOT NULL DEFAULT '0' COMMENT '已生成视频时长（秒）',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`ai_usage_quota_id`),
  UNIQUE KEY `uniq_tenant_period` (`tenant_id`,`period`),
  KEY `ai_usage_quotas_tenant_id_period_index` (`tenant_id`,`period`),
  KEY `ai_usage_quotas_subscription_plan_id_index` (`subscription_plan_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: entity_memories
        DB::statement(<<<'SQL'
CREATE TABLE `entity_memories` (
  `memory_id` bigint unsigned NOT NULL COMMENT '记忆ID',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `entity_type` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '实体类型',
  `entity_id` bigint unsigned NOT NULL COMMENT '实体ID',
  `key` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '记忆键',
  `value` json DEFAULT NULL COMMENT '记忆值(JSON)',
  `weight` float NOT NULL DEFAULT '1' COMMENT '权重',
  `last_accessed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`memory_id`),
  KEY `entity_memories_tenant_id_entity_type_entity_id_index` (`tenant_id`,`entity_type`,`entity_id`),
  KEY `entity_memories_entity_type_entity_id_key_index` (`entity_type`,`entity_id`,`key`),
  CONSTRAINT `entity_memories_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: laravel_ai_conversations
        DB::statement(<<<'SQL'
CREATE TABLE `laravel_ai_conversations` (
  `conversation_id` bigint unsigned NOT NULL COMMENT '会话 ID（IdGenerator 16位数字）',
  `user_id` bigint unsigned DEFAULT NULL COMMENT '用户 ID',
  `tenant_id` bigint unsigned DEFAULT NULL COMMENT '租户 ID（多租户隔离）',
  `title` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '会话标题',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active' COMMENT '会话状态',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`conversation_id`),
  KEY `laravel_ai_conversations_user_id_updated_at_index` (`user_id`,`updated_at`),
  KEY `laravel_ai_conversations_tenant_id_index` (`tenant_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: tenant_memories
        DB::statement(<<<'SQL'
CREATE TABLE `tenant_memories` (
  `memory_id` bigint unsigned NOT NULL COMMENT '记忆ID',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '类型: preference/rule/decision',
  `key` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '记忆键',
  `value` json DEFAULT NULL COMMENT '记忆值(JSON)',
  `weight` float NOT NULL DEFAULT '1' COMMENT '权重',
  `last_accessed_at` timestamp NULL DEFAULT NULL COMMENT '最后访问时间',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`memory_id`),
  KEY `tenant_memories_tenant_id_type_index` (`tenant_id`,`type`),
  KEY `tenant_memories_tenant_id_key_index` (`tenant_id`,`key`),
  CONSTRAINT `tenant_memories_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        Schema::dropIfExists('agent_conversation_messages');
        Schema::dropIfExists('agent_conversations');
        Schema::dropIfExists('agent_tool_logs');
        Schema::dropIfExists('agent_tools');
        Schema::dropIfExists('agent_workflows');
        Schema::dropIfExists('agents');
        Schema::dropIfExists('ai_model_aliases');
        Schema::dropIfExists('ai_prompts');
        Schema::dropIfExists('ai_providers');
        Schema::dropIfExists('ai_requests');
        Schema::dropIfExists('ai_tenant_configs');
        Schema::dropIfExists('ai_usage_quotas');
        Schema::dropIfExists('entity_memories');
        Schema::dropIfExists('laravel_ai_conversations');
        Schema::dropIfExists('tenant_memories');
    }
};
