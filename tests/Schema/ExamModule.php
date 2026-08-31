<?php

namespace MultiTenantSaas\Tests\Schema;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 考试/测评模块（Exam）
 * 表: exam_question_banks, exam_questions, exams, exam_records
 */
class ExamModule implements SchemaModuleInterface
{
    public function createTables(): void
    {
        Schema::create('exam_question_banks', function (Blueprint $table) {
            $table->bigInteger('bank_id')->unsigned()->primary();
            $table->bigInteger('tenant_id')->unsigned();
            $table->string('name', 255);
            $table->string('description', 500)->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id']);
        });

        Schema::create('exam_questions', function (Blueprint $table) {
            $table->bigInteger('question_id')->unsigned()->primary();
            $table->bigInteger('tenant_id')->unsigned();
            $table->bigInteger('bank_id')->unsigned();
            $table->string('type', 20)->default('single');
            $table->text('content');
            $table->json('options')->nullable();
            $table->json('answer');
            $table->text('analysis')->nullable();
            $table->decimal('score', 6, 2)->default(1);
            $table->string('difficulty', 20)->default('normal');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'bank_id', 'type']);
        });

        Schema::create('exams', function (Blueprint $table) {
            $table->bigInteger('exam_id')->unsigned()->primary();
            $table->bigInteger('tenant_id')->unsigned();
            $table->string('title', 255);
            $table->json('compose_rule');
            $table->decimal('total_score', 8, 2)->default(0);
            $table->decimal('pass_score', 8, 2)->default(0);
            $table->integer('time_limit_minutes')->default(0);
            $table->integer('retry_limit')->default(1);
            $table->string('status', 20)->default('draft');
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'status']);
        });

        Schema::create('exam_records', function (Blueprint $table) {
            $table->bigInteger('record_id')->unsigned()->primary();
            $table->bigInteger('tenant_id')->unsigned();
            $table->bigInteger('exam_id')->unsigned();
            $table->bigInteger('user_id')->unsigned();
            $table->integer('attempt')->default(1);
            $table->json('questions_snapshot');
            $table->json('answers')->nullable();
            $table->decimal('objective_score', 8, 2)->default(0);
            $table->decimal('total_score', 8, 2)->default(0);
            $table->boolean('passed')->default(false);
            $table->string('status', 20)->default('in_progress');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['tenant_id', 'exam_id', 'user_id', 'attempt'], 'exam_records_attempt_unique');
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function getTableNames(): array
    {
        return ['exam_question_banks', 'exam_questions', 'exams', 'exam_records'];
    }
}
