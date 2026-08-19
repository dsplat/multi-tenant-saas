<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * AI 长任务表（task/queue 跟踪机制）
 *
 * 主键 task_id 遵循框架铁律：IdGenerator 16 位随机数字全局 ID
 * （bigint unsigned，禁止 snowflake/UUID/自增，见 .qoder/rules/id-model.md）。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE `ai_tasks` (
  `task_id` bigint unsigned NOT NULL COMMENT '任务 ID（IdGenerator 全局ID）',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `conversation_id` bigint unsigned DEFAULT NULL COMMENT '发起会话 ID（断连兜底落库用）',
  `agent_id` bigint unsigned DEFAULT NULL COMMENT '发起 Agent ID',
  `type` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '任务类型（handler 标识，如 activity_plan_draft）',
  `status` enum('pending','processing','completed','failed') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT '任务状态',
  `payload` json DEFAULT NULL COMMENT '任务入参快照',
  `result` json DEFAULT NULL COMMENT '执行结果',
  `error` text COLLATE utf8mb4_unicode_ci COMMENT '失败原因',
  `metadata` json DEFAULT NULL COMMENT '元数据（如 abandoned=客户端已放弃轮询）',
  `attempts` tinyint unsigned NOT NULL DEFAULT '0' COMMENT '执行尝试次数',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL COMMENT '终态时间',
  PRIMARY KEY (`task_id`),
  KEY `ai_tasks_tenant_status_index` (`tenant_id`,`status`),
  KEY `ai_tasks_conversation_id_index` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS `ai_tasks`');
    }
};
