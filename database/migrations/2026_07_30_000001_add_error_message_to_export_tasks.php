<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * export_tasks 补充 error_message 列，存储导出任务失败原因。
 *
 * 原 error 列为布尔标志（仅标记失败与否），失败详情无处落地导致无法排查。
 * 基线迁移已同步加列，此处用 hasColumn 守卫兼容新装环境。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('export_tasks', 'error_message')) {
            Schema::table('export_tasks', function (Blueprint $table) {
                $table->text('error_message')->nullable()->after('error');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('export_tasks', 'error_message')) {
            Schema::table('export_tasks', function (Blueprint $table) {
                $table->dropColumn('error_message');
            });
        }
    }
};
