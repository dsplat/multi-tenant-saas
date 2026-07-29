<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * AI 统一审计日志表
 *
 * 记录租户内所有 AI 操作事件：对话、工具执行、转派、配置变更等。
 * 与 agent_tool_logs（工具细节）和 ai_requests（API 计量）互补，
 * 本表面向合规审计与运营分析：谁、在什么时候、通过哪个 Agent、做了什么、结果如何。
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement(<<<'SQL'
CREATE TABLE `ai_audit_logs` (
  `audit_id` bigint unsigned NOT NULL COMMENT '审计 ID（全局 ID）',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户 ID',
  `operator_id` bigint unsigned DEFAULT NULL COMMENT '操作人（Operator ID）',
  `agent_id` bigint unsigned DEFAULT NULL COMMENT '关联 Agent ID',
  `conversation_id` bigint unsigned DEFAULT NULL COMMENT '关联会话 ID',
  `action` varchar(50) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '事件类型（conversation.start/tool.execute/agent.delegate/agent.enable/agent.disable/prompt.update）',
  `target_type` varchar(50) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '操作对象类型（agent/tool/prompt/conversation）',
  `target_id` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '操作对象 ID',
  `summary` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '事件摘要（人类可读）',
  `detail` json DEFAULT NULL COMMENT '事件详情（结构化数据）',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'success' COMMENT '结果：success/failed/denied',
  `ip_address` varchar(45) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '来源 IP',
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`audit_id`),
  KEY `idx_audit_tenant_time` (`tenant_id`,`created_at`),
  KEY `idx_audit_tenant_action` (`tenant_id`,`action`),
  KEY `idx_audit_operator` (`operator_id`,`created_at`),
  KEY `idx_audit_agent` (`agent_id`,`created_at`),
  KEY `idx_audit_conversation` (`conversation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_audit_logs');
    }
};
