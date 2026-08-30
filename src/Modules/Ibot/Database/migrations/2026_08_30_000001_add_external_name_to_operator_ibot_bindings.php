<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * operator_ibot_bindings 增加 IM 成员展示名（企微姓名等），
 * 列表页展示真实姓名/昵称而非平台 userid。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('operator_ibot_bindings', 'external_name')) {
            return;
        }

        Schema::table('operator_ibot_bindings', function (Blueprint $table) {
            $table->string('external_name', 128)->nullable()
                ->after('external_id')
                ->comment('IM 平台成员展示名（企微姓名/昵称，读取失败回退 userid）');
        });
    }

    public function down(): void
    {
        Schema::table('operator_ibot_bindings', function (Blueprint $table) {
            $table->dropColumn('external_name');
        });
    }
};
