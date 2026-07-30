<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ticket 模块 — 工单两表
 *
 * tickets：租户级工单（创建人/处理人关联 users，软删除）
 * ticket_comments：工单评论
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('tickets')) {
            Schema::create('tickets', function (Blueprint $table) {
                $table->unsignedBigInteger('ticket_id')->primary();
                $table->unsignedBigInteger('tenant_id');
                $table->string('subject', 255);
                $table->text('description')->nullable();
                $table->string('status', 20)->default('open')->comment('open/in_progress/resolved/closed');
                $table->string('priority', 20)->default('medium')->comment('low/medium/high/urgent');
                $table->string('category', 50)->nullable();
                $table->unsignedBigInteger('created_by')->nullable()->comment('创建人 user_id');
                $table->unsignedBigInteger('assigned_to')->nullable()->comment('处理人 user_id');
                $table->timestamps();
                $table->softDeletes();

                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'assigned_to']);
            });
        }

        if (! Schema::hasTable('ticket_comments')) {
            Schema::create('ticket_comments', function (Blueprint $table) {
                $table->unsignedBigInteger('comment_id')->primary();
                $table->unsignedBigInteger('ticket_id');
                $table->unsignedBigInteger('user_id')->nullable()->comment('评论人 user_id');
                $table->text('content');
                $table->timestamps();

                $table->index('ticket_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ticket_comments');
        Schema::dropIfExists('tickets');
    }
};
