<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_templates', function (Blueprint $table) {
            $table->unsignedBigInteger('sms_template_id')->primary()->comment('短信模板ID（IdGenerator 全局ID）');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->string('name', 128)->comment('模板名称');
            $table->text('content')->comment('模板内容');
            $table->json('variables')->nullable()->comment('变量列表及示例值');
            $table->string('channel', 20)->default('marketing')->comment('渠道: marketing/verification/notification');
            $table->string('provider_template_id', 128)->nullable()->comment('运营商模板ID');
            $table->string('status', 20)->default('pending_approval')->comment('状态: pending_approval/approved/rejected');
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->index(['tenant_id', 'channel']);

            $table->foreign('tenant_id')->references('tenant_id')->on('tenants')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_templates');
    }
};
