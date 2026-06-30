<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 租户层级关系表（TASK-029）
 *
 * 用于企业集团场景下的父-子租户关系管理，支持资源共享池与层级计费。
 * tenant_id 字段存父租户 ID，作为 BelongsToTenant 全局作用域的隔离依据；
 * child_tenant_id 为关联的子租户 ID。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_hierarchies', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_hierarchy_id')->primary();
            $table->unsignedBigInteger('tenant_id')->comment('父租户 ID（隔离作用域依据）');
            $table->unsignedBigInteger('child_tenant_id')->comment('子租户 ID');
            $table->string('relation_type', 30)->default('subsidiary')->comment('关系类型: subsidiary/branch/division');
            $table->json('permission_scope')->nullable()->comment('权限范围：资源共享、跨租户访问授权、计费聚合等');
            $table->boolean('is_active')->default(true)->comment('关系是否有效');
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id', 'th_tenant_index');
            $table->index('child_tenant_id', 'th_child_index');
            $table->unique(['tenant_id', 'child_tenant_id'], 'th_parent_child_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_hierarchies');
    }
};
