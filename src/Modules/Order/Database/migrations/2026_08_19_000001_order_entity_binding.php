<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 统一订单中心：实体绑定隔离层 + 字段命名统一
 *
 * 1. orders 增加 entity_type/entity_id（主实体）+ secondary_entity_type/secondary_entity_id（次要实体）
 *    —— 订单与业务实体（活动/课程/商品等）的字符串多态绑定，不绑类名、不加业务专属 ID 字段
 * 2. order_items.item_type → entity_type、ref_id → entity_id（RENAME COLUMN 保数据）
 *    —— 全系统实体绑定字段统一为 entity_type/entity_id（与 materials/scripts/EntityMemory 一致）
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('entity_type', 50)->nullable()->after('pay_method')->comment('主实体类型（EntityTypes 白名单）');
            $table->string('entity_id', 64)->nullable()->after('entity_type')->comment('主实体 ID');
            $table->string('secondary_entity_type', 50)->nullable()->after('entity_id')->comment('次要实体类型（如活动推广课程）');
            $table->string('secondary_entity_id', 64)->nullable()->after('secondary_entity_type')->comment('次要实体 ID');
            $table->index(['tenant_id', 'entity_type', 'entity_id'], 'orders_entity_idx');
            $table->index(['tenant_id', 'secondary_entity_type', 'secondary_entity_id'], 'orders_secondary_entity_idx');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->renameColumn('item_type', 'entity_type');
            $table->renameColumn('ref_id', 'entity_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->renameColumn('entity_type', 'item_type');
            $table->renameColumn('entity_id', 'ref_id');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_entity_idx');
            $table->dropIndex('orders_secondary_entity_idx');
            $table->dropColumn(['entity_type', 'entity_id', 'secondary_entity_type', 'secondary_entity_id']);
        });
    }
};
