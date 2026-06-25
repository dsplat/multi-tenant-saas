<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->timestamp('expires_at')->nullable()->after('description')->comment('交易积分过期时间（仅充值/赠送类型）');
            $table->boolean('expired')->default(false)->after('expires_at')->comment('是否已过期');
            $table->index(['expires_at', 'expired'], 'idx_credit_txn_expiry');
        });
    }

    public function down(): void
    {
        Schema::table('credit_transactions', function (Blueprint $table) {
            $table->dropIndex('idx_credit_txn_expiry');
            $table->dropColumn(['expires_at', 'expired']);
        });
    }
};
