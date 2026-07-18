<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('SET FOREIGN_KEY_CHECKS=0');

        // Table: workflow_executions
        DB::statement(<<<'SQL'
CREATE TABLE `workflow_executions` (
  `execution_id` bigint unsigned NOT NULL COMMENT '执行 ID（IdGenerator 全局ID）',
  `workflow_id` bigint unsigned NOT NULL COMMENT '工作流 ID',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending' COMMENT '状态: pending/running/completed/failed/cancelled',
  `context` json DEFAULT NULL COMMENT '执行上下文（JSON）',
  `error` text COLLATE utf8mb4_unicode_ci COMMENT '错误信息',
  `started_at` timestamp NULL DEFAULT NULL COMMENT '开始时间',
  `completed_at` timestamp NULL DEFAULT NULL COMMENT '完成时间',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`execution_id`),
  KEY `workflow_executions_workflow_id_status_index` (`workflow_id`,`status`),
  KEY `workflow_executions_tenant_id_status_index` (`tenant_id`,`status`),
  CONSTRAINT `workflow_executions_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  CONSTRAINT `workflow_executions_workflow_id_foreign` FOREIGN KEY (`workflow_id`) REFERENCES `workflows` (`workflow_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: workflow_nodes
        DB::statement(<<<'SQL'
CREATE TABLE `workflow_nodes` (
  `node_id` bigint unsigned NOT NULL COMMENT '节点 ID（IdGenerator 全局ID）',
  `workflow_id` bigint unsigned NOT NULL COMMENT '所属工作流 ID',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '节点名称',
  `type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '节点类型: start/end/condition/action/wait',
  `config` json DEFAULT NULL COMMENT '节点配置（JSON）',
  `next_node_id` bigint unsigned DEFAULT NULL COMMENT '下一节点 ID',
  `order` int NOT NULL DEFAULT '0' COMMENT '排序',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`node_id`),
  KEY `workflow_nodes_workflow_id_order_index` (`workflow_id`,`order`),
  KEY `workflow_nodes_tenant_id_index` (`tenant_id`),
  KEY `workflow_nodes_next_node_id_foreign` (`next_node_id`),
  CONSTRAINT `workflow_nodes_next_node_id_foreign` FOREIGN KEY (`next_node_id`) REFERENCES `workflow_nodes` (`node_id`) ON DELETE SET NULL,
  CONSTRAINT `workflow_nodes_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE,
  CONSTRAINT `workflow_nodes_workflow_id_foreign` FOREIGN KEY (`workflow_id`) REFERENCES `workflows` (`workflow_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        // Table: workflows
        DB::statement(<<<'SQL'
CREATE TABLE `workflows` (
  `workflow_id` bigint unsigned NOT NULL COMMENT '工作流 ID（IdGenerator 全局ID）',
  `tenant_id` bigint unsigned NOT NULL COMMENT '租户ID',
  `name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '工作流名称',
  `description` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '工作流描述',
  `type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sequential' COMMENT '类型: sequential/parallel/conditional',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft' COMMENT '状态: draft/active/archived',
  `version` int NOT NULL DEFAULT '1' COMMENT '版本号',
  `config` json DEFAULT NULL COMMENT '工作流配置（JSON）',
  `enabled` tinyint(1) NOT NULL DEFAULT '1' COMMENT '是否启用',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`workflow_id`),
  KEY `workflows_tenant_id_status_index` (`tenant_id`,`status`),
  KEY `workflows_tenant_id_type_index` (`tenant_id`,`type`),
  CONSTRAINT `workflows_tenant_id_foreign` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`tenant_id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL);

        DB::statement('SET FOREIGN_KEY_CHECKS=1');
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_executions');
        Schema::dropIfExists('workflow_nodes');
        Schema::dropIfExists('workflows');
    }
};
