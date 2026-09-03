<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 课程开售时间（产品字段）
 *
 * courses.created_at/updated_at 是系统审计时刻（导入/编辑触发），不允许被来源覆盖；
 * 来源站（小鹅通）的开售时间 sale_at 映射到本列，无开售时间时以来源建课时间近似。
 * 导入链路只写本列；其余来源时间留存于 external_mappings.raw_snapshot（操作记录）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->timestamp('onsale_at')->nullable()
                ->comment('开售时间（导入来源 sale_at 映射，无则来源建课时间近似）')
                ->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('courses', function (Blueprint $table) {
            $table->dropColumn('onsale_at');
        });
    }
};
