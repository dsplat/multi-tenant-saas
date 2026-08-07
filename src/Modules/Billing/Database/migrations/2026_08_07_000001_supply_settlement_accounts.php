<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P4 供货结算账务扩展
 *
 * 1. credit_accounts.account_type 枚举扩展：supply_prepay（预存货款，预收账款负债）
 *    与 domain_deposit（域名保证金，其他应付款），与 AI credit 账户分账。
 * 2. credit_transactions.type 枚举扩展：release（保证金退还），
 *    user_id 改为可空（供货结算为租户级账务，无操作终端用户）。
 * 3. credit_accounts 补齐模型依赖的 gift_balance / recharge_balance 列
 *    （模型 recharge/consume 已引用，原表缺失）。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('credit_accounts', 'gift_balance')) {
            Schema::table('credit_accounts', function (Blueprint $table) {
                $table->unsignedBigInteger('gift_balance')->default(0)->after('balance');
                $table->unsignedBigInteger('recharge_balance')->default(0)->after('gift_balance');
            });
        }

        // MySQL enum 扩展需原生 ALTER；sqlite（测试）无 enum 约束，跳过
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `credit_accounts` MODIFY COLUMN `account_type` enum('enterprise','personal','supply_prepay','domain_deposit') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'personal' COMMENT '账户类型'");
            DB::statement("ALTER TABLE `credit_transactions` MODIFY COLUMN `type` enum('recharge','consume','refund','transfer','gift','expire','release') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '交易类型'");
            DB::statement("ALTER TABLE `credit_transactions` MODIFY COLUMN `user_id` bigint unsigned DEFAULT NULL COMMENT '用户ID（NULL=租户级账务）'");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE `credit_transactions` MODIFY COLUMN `user_id` bigint unsigned NOT NULL COMMENT '用户ID'");
            DB::statement("ALTER TABLE `credit_transactions` MODIFY COLUMN `type` enum('recharge','consume','refund','transfer','gift','expire') COLLATE utf8mb4_unicode_ci NOT NULL COMMENT '交易类型'");
            DB::statement("ALTER TABLE `credit_accounts` MODIFY COLUMN `account_type` enum('enterprise','personal') COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'personal' COMMENT '账户类型'");
        }

        Schema::table('credit_accounts', function (Blueprint $table) {
            $table->dropColumn(['gift_balance', 'recharge_balance']);
        });
    }
};
