<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_unsubscribes', function (Blueprint $table) {
            $table->unsignedBigInteger('unsubscribe_id')->primary()->comment('退订ID（IdGenerator 全局ID）');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->string('phone', 20)->comment('手机号');
            $table->unsignedBigInteger('user_id')->nullable()->comment('关联用户ID');
            $table->string('reason', 512)->nullable()->comment('退订原因');
            $table->timestamp('unsubscribed_at')->comment('退订时间');
            $table->timestamps();

            $table->index(['tenant_id', 'phone']);
            $table->unique(['tenant_id', 'phone'], 'sms_unsubscribes_tenant_phone_unique');

            $table->foreign('tenant_id')->references('tenant_id')->on('tenants')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_unsubscribes');
    }
};
