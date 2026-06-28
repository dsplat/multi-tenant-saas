<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mail_templates', function (Blueprint $table) {
            $table->unsignedBigInteger('template_id')->primary()->comment('模板ID（全局ID，16位数字）');
            $table->bigInteger('tenant_id')->unsigned()->nullable()->comment('租户ID，NULL表示系统默认模板');
            $table->string('type', 50)->comment('类型: billing/notification/welcome/reset');
            $table->string('name_key', 50)->nullable()->comment('模板固定标识符，用于幂等匹配，不受 locale 影响');
            $table->string('name')->comment('模板名称');
            $table->string('subject')->comment('邮件主题');
            $table->longText('html_body')->comment('HTML 正文');
            $table->text('text_body')->nullable()->comment('纯文本正文');
            $table->json('variables')->nullable()->comment('变量定义（JSON）');
            $table->string('status', 20)->default('activated')->comment('状态: activated/disabled');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'type']);
            $table->index(['type', 'status']);
            $table->index(['name_key', 'tenant_id']);

            $table->foreign('tenant_id')
                ->references('tenant_id')
                ->on('tenants')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_templates');
    }
};
