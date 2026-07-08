<?php

namespace App\Models\Workflow;

use MultiTenantSaas\Models\WorkflowExecution as BaseWorkflowExecution;

/**
 * 工作流执行记录模型
 *
 * 继承基类，自动获得租户隔离能力
 */
class WorkflowExecution extends BaseWorkflowExecution
{
    //
}
