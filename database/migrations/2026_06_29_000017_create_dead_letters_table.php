<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dead_letters', function (Blueprint $table) {
            $table->unsignedBigInteger('dead_letter_id')->primary();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->string('event_type', 100);
            $table->unsignedBigInteger('subscription_id')->nullable();
            $table->json('original_data')->nullable(); // 事件原始数据
            $table->text('failure_reason')->nullable(); // 失败原因
            $table->unsignedInteger('retry_count')->default(0); // 已重试次数
            $table->string('status', 20)->default('failed'); // failed / retried / resolved
            $table->timestamps();

            $table->index('tenant_id');
            $table->index('event_type');
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dead_letters');
    }
};
