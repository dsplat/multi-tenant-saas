<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 章节解锁规则（对标小鹅通章节解锁条件）
 *
 * unlock_rule JSON 统一 schema（与打卡任务/训练营共用同一套规则语义）：
 * {"mode": "time|sequence|prerequisite", "config": {...}}
 * - time：到达 unlock_at 解锁
 * - sequence：完成上一节后解锁（默认）
 * - prerequisite：完成指定章节/任务后解锁
 *
 * type 列（varchar）已可直接存 'audio'，无需 schema 变更（模型常量补充）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_chapters', function (Blueprint $table) {
            $table->json('unlock_rule')->nullable()
                ->comment('解锁规则 {mode: time|sequence|prerequisite, config}')
                ->after('file_url');
        });
    }

    public function down(): void
    {
        Schema::table('course_chapters', function (Blueprint $table) {
            $table->dropColumn('unlock_rule');
        });
    }
};
