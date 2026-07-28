<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * agent_tools 表新增 risk 风险等级字段
 *
 * L1=读/低风险，直接执行（默认，向后兼容）
 * L2=低风险写，需用户确认后执行（确认令牌机制）
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('agent_tools', 'risk')) {
            return;
        }

        Schema::table('agent_tools', function (Blueprint $table) {
            $table->string('risk', 10)->default('L1')->after('handler_class')->comment('风险等级：L1=读直接执行，L2=写需确认');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('agent_tools', 'risk')) {
            return;
        }

        Schema::table('agent_tools', function (Blueprint $table) {
            $table->dropColumn('risk');
        });
    }
};
