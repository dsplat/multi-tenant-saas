<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 考试/测评模块（对标小鹅通考试 + 鲸打卡测评）
 *
 * - exam_question_banks：题库
 * - exam_questions：题目（一期客观题三型：single|multi|judge）
 * - exams：试卷（compose_rule 组卷规则：固定题序 或 按题库+题型随机抽题）
 * - exam_records：答卷记录（题目快照防题库变更污染历史答卷，重试计数幂等）
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('exam_question_banks')) {
            Schema::create('exam_question_banks', function (Blueprint $table) {
                $table->bigInteger('bank_id')->unsigned()->primary()->comment('IdGenerator 全局ID');
                $table->bigInteger('tenant_id')->unsigned();
                $table->string('name', 255);
                $table->string('description', 500)->nullable();
                $table->timestamps();
                $table->softDeletes();
                $table->index(['tenant_id']);
            });
        }

        if (! Schema::hasTable('exam_questions')) {
            Schema::create('exam_questions', function (Blueprint $table) {
                $table->bigInteger('question_id')->unsigned()->primary()->comment('IdGenerator 全局ID');
                $table->bigInteger('tenant_id')->unsigned();
                $table->bigInteger('bank_id')->unsigned();
                $table->string('type', 20)->default('single')->comment('single|multi|judge');
                $table->text('content')->comment('题干');
                $table->json('options')->nullable()->comment('选项列表（判断题为 [正确,错误]）');
                $table->json('answer')->comment('标准答案（单选下标/多选下标集/布尔）');
                $table->text('analysis')->nullable()->comment('答案解析');
                $table->decimal('score', 6, 2)->default(1)->comment('题目分值');
                $table->string('difficulty', 20)->default('normal')->comment('easy|normal|hard');
                $table->timestamps();
                $table->softDeletes();
                $table->index(['tenant_id', 'bank_id', 'type']);
            });
        }

        if (! Schema::hasTable('exams')) {
            Schema::create('exams', function (Blueprint $table) {
                $table->bigInteger('exam_id')->unsigned()->primary()->comment('IdGenerator 全局ID');
                $table->bigInteger('tenant_id')->unsigned();
                $table->string('title', 255);
                $table->json('compose_rule')->comment('{mode: fixed|random, question_ids? | rules: [{bank_id, type, count}]}');
                $table->decimal('total_score', 8, 2)->default(0);
                $table->decimal('pass_score', 8, 2)->default(0)->comment('及格线');
                $table->integer('time_limit_minutes')->default(0)->comment('0=不限时');
                $table->integer('retry_limit')->default(1)->comment('允许考试次数');
                $table->string('status', 20)->default('draft')->comment('draft|published|closed');
                $table->timestamps();
                $table->softDeletes();
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('exam_records')) {
            Schema::create('exam_records', function (Blueprint $table) {
                $table->bigInteger('record_id')->unsigned()->primary()->comment('IdGenerator 全局ID');
                $table->bigInteger('tenant_id')->unsigned();
                $table->bigInteger('exam_id')->unsigned();
                $table->bigInteger('user_id')->unsigned();
                $table->integer('attempt')->default(1)->comment('第几次考试');
                $table->json('questions_snapshot')->comment('开考时题目快照（含标准答案，判分用）');
                $table->json('answers')->nullable()->comment('{question_id: 作答}');
                $table->decimal('objective_score', 8, 2)->default(0);
                $table->decimal('total_score', 8, 2)->default(0);
                $table->boolean('passed')->default(false);
                $table->string('status', 20)->default('in_progress')->comment('in_progress|submitted');
                $table->timestamp('started_at')->nullable();
                $table->timestamp('submitted_at')->nullable();
                $table->timestamps();
                $table->unique(['tenant_id', 'exam_id', 'user_id', 'attempt'], 'exam_records_attempt_unique');
                $table->index(['tenant_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_records');
        Schema::dropIfExists('exams');
        Schema::dropIfExists('exam_questions');
        Schema::dropIfExists('exam_question_banks');
    }
};
