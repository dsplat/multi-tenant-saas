<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('transaction_id')->primary()->comment('交易ID（全局ID，16位数字）');
            $table->unsignedBigInteger('account_id')->comment('账户ID（关联credit_accounts）');
            $table->unsignedBigInteger('tenant_id')->comment('租户ID');
            $table->unsignedBigInteger('user_id')->comment('用户ID');
            $table->enum('type', ['recharge', 'consume', 'refund', 'transfer', 'gift', 'expire'])->comment('交易类型');
            $table->bigInteger('amount')->comment('金额（正数=收入，负数=支出）');
            $table->unsignedBigInteger('balance_after')->comment('交易后余额');
            $table->string('related_type', 100)->nullable()->comment('关联模型类型');
            $table->string('related_id', 100)->nullable()->comment('关联模型ID');
            $table->string('description', 255)->nullable()->comment('交易描述');
            $table->json('metadata')->nullable()->comment('元数据');
            $table->timestamp('created_at')->nullable();

            $table->index(['account_id', 'created_at'], 'idx_credit_transactions_account');
            $table->index(['tenant_id', 'type', 'created_at'], 'idx_credit_transactions_tenant');
            $table->index(['user_id', 'created_at'], 'idx_credit_transactions_user');
            $table->index('created_at', 'idx_credit_transactions_created');
            $table->index(['related_type', 'related_id'], 'idx_credit_transactions_related');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_transactions');
    }
};
