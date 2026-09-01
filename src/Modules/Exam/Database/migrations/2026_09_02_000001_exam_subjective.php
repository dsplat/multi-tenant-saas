<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 考试模块二期：主观题判分 + 练习/错题本
 *
 * - exam_records.subjective_score：主观题批改得分（gradeSubjective 回写）
 * - exam_practice_records：练习记录（错题重练/题库练习，即时判分）
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('exam_records', 'subjective_score')) {
            Schema::table('exam_records', function (Blueprint $table) {
                $table->decimal('subjective_score', 8, 2)->default(0)
                    ->after('objective_score')->comment('主观题批改得分（覆盖式回写）');
            });
        }

        if (! Schema::hasTable('exam_practice_records')) {
            Schema::create('exam_practice_records', function (Blueprint $table) {
                $table->bigInteger('record_id')->unsigned()->primary()->comment('IdGenerator 全局ID');
                $table->bigInteger('tenant_id')->unsigned();
                $table->bigInteger('user_id')->unsigned();
                $table->string('source', 20)->default('wrong')->comment('wrong=错题重练|bank=题库练习');
                $table->bigInteger('bank_id')->unsigned()->nullable();
                $table->bigInteger('exam_id')->unsigned()->nullable();
                $table->json('question_ids')->comment('本次练习题目ID列表');
                $table->integer('correct_count')->default(0);
                $table->integer('total_count')->default(0);
                $table->timestamps();
                $table->index(['tenant_id', 'user_id']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_practice_records');

        if (Schema::hasColumn('exam_records', 'subjective_score')) {
            Schema::table('exam_records', function (Blueprint $table) {
                $table->dropColumn('subjective_score');
            });
        }
    }
};
