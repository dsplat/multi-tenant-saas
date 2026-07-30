<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * task_chain_runs.conversation_id → nullable
 *
 * Campaign 触发的链无归属会话（conversation_id=NULL），
 * unfinishedRuns 查询按 conversation_id 过滤天然排除 campaign 运行实例。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_chain_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('conversation_id')->nullable()->comment('归属会话（链在会话内推进；campaign 触发为 null）')->change();
        });
    }

    public function down(): void
    {
        Schema::table('task_chain_runs', function (Blueprint $table) {
            $table->unsignedBigInteger('conversation_id')->nullable(false)->comment('归属会话（链在会话内推进）')->change();
        });
    }
};
