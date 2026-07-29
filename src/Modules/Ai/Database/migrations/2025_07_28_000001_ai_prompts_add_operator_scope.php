<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * ai_prompts 增加 operator 级作用域 + role 绑定
 *
 * 解析链：operator（operator_id + role）> tenant（tenant_id + role）> system（tenant_id=NULL + role）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_prompts', function (Blueprint $table) {
            $table->unsignedBigInteger('operator_id')->nullable()->after('tenant_id')
                ->comment('Operator ID，非 null 表示 operator 级定制');
            $table->string('role', 50)->nullable()->after('operator_id')
                ->comment('绑定的 Agent 角色键（system_secretary/customer_service 等），null 表示通用');
        });

        // 复合索引：operator 级查询
        Schema::table('ai_prompts', function (Blueprint $table) {
            $table->index(['operator_id', 'role', 'status'], 'idx_operator_role_status');
            $table->index(['tenant_id', 'role', 'status'], 'idx_tenant_role_status');
        });
    }

    public function down(): void
    {
        Schema::table('ai_prompts', function (Blueprint $table) {
            $table->dropIndex('idx_operator_role_status');
            $table->dropIndex('idx_tenant_role_status');
            $table->dropColumn(['operator_id', 'role']);
        });
    }
};
