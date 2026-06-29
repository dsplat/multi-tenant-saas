<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 租户表追加数据库隔离字段（TASK-027）
 *
 * - isolation_type: 隔离策略类型（shared/database/schema）
 * - database_name:  独立数据库名（database 策略使用）
 * - schema_name:    独立 Schema 名（schema 策略使用）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('isolation_type', 20)->default('shared')->after('status')->comment('隔离策略: shared/database/schema');
            $table->string('database_name', 100)->nullable()->after('isolation_type')->comment('独立数据库名（database 策略）');
            $table->string('schema_name', 100)->nullable()->after('database_name')->comment('独立 Schema 名（schema 策略）');

            $table->index('isolation_type', 'tenants_isolation_type_index');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropIndex('tenants_isolation_type_index');
            $table->dropColumn(['isolation_type', 'database_name', 'schema_name']);
        });
    }
};
