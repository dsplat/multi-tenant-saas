<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_subscriptions', function (Blueprint $table) {
            $table->unsignedBigInteger('event_subscription_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('event_type', 100);
            $table->string('subscription_type', 20)->default('internal'); // internal / webhook
            $table->string('handler', 500); // 内部处理器类名 或 外部 Webhook URL
            $table->string('secret', 128)->nullable(); // 外部订阅的 HMAC-SHA256 签名密钥
            $table->boolean('is_active')->default(true);
            $table->string('description', 255)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'is_active']);
            $table->index('event_type');
            $table->unique(['tenant_id', 'event_type', 'handler'], 'uniq_tenant_event_handler');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_subscriptions');
    }
};
