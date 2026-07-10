<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_histories', function (Blueprint $table) {
            $table->unsignedBigInteger('subscription_history_id')->primary()->comment('历史ID（全局ID）');
            $table->bigInteger('tenant_id')->unsigned();
            $table->unsignedBigInteger('plan_id')->nullable();
            $table->foreign('plan_id')->references('subscription_plan_id')->on('subscription_plans')->nullOnDelete();
            $table->string('action', 30)->comment('subscribe, cancel, change, trial, renew, downgrade, upgrade');
            $table->string('from_plan', 50)->nullable()->comment('变更前计划');
            $table->string('to_plan', 50)->nullable()->comment('变更后计划');
            $table->string('billing_cycle', 20)->nullable()->comment('monthly, yearly');
            $table->decimal('amount', 10, 2)->default(0)->comment('操作金额');
            $table->decimal('proration_amount', 10, 2)->default(0)->comment('按比例退补金额');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->text('notes')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('tenant_id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'action']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_histories');
    }
};
