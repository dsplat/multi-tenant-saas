<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('forms', function (Blueprint $table) {
            $table->string('slug', 100)->nullable()->after('title')->comment('表单标识');
            $table->json('settings')->nullable()->after('metadata')->comment('表单设置');
            $table->unsignedInteger('submit_count')->default(0)->after('submit_limit')->comment('提交次数');
        });

        Schema::table('form_submissions', function (Blueprint $table) {
            $table->string('status', 20)->default('pending')->after('data')->comment('状态: pending/approved/rejected');
            $table->timestamp('submitted_at')->nullable()->after('user_agent')->comment('提交时间');
        });

        Schema::create('form_submission_data', function (Blueprint $table) {
            $table->unsignedBigInteger('submission_data_id')->primary()->comment('提交数据ID');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->unsignedBigInteger('submission_id')->comment('提交ID');
            $table->unsignedBigInteger('field_id')->comment('字段ID');
            $table->text('value')->nullable()->comment('字段值');
            $table->timestamp('created_at')->nullable()->comment('创建时间');

            $table->foreign('submission_id')->references('submission_id')->on('form_submissions')->onDelete('cascade');
            $table->foreign('field_id')->references('field_id')->on('form_fields')->onDelete('cascade');
            $table->index(['tenant_id', 'submission_id']);
            $table->index(['submission_id', 'field_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('form_submission_data');

        Schema::table('form_submissions', function (Blueprint $table) {
            $table->dropColumn(['status', 'submitted_at']);
        });

        Schema::table('forms', function (Blueprint $table) {
            $table->dropColumn(['slug', 'settings', 'submit_count']);
        });
    }
};
