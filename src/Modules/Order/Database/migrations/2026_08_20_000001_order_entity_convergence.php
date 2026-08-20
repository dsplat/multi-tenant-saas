<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use MultiTenantSaas\Contracts\IdGeneratorContract;

/**
 * 订单实体绑定收敛（方案 B · 职责分离）
 *
 * 1. 新建 order_entity_relations：承载订单次要实体归因（relation_type 白名单），
 *    恢复"次要实体独立成表"的历史定稿。
 * 2. orders 移除 secondary_entity_type/secondary_entity_id（违背定稿落在订单表的字段），
 *    非空行兜底迁入 order_entity_relations（relation_type='related'，当前空置）。
 * 3. order_items 移除行级 entity_type/entity_id（与 orders 主实体冗余重叠；
 *    规格信息由 sku_id 承载，票种等细粒度由 metadata 承载）。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_entity_relations', function (Blueprint $table) {
            $table->bigInteger('relation_id')->unsigned()->primary();
            $table->bigInteger('tenant_id')->unsigned();
            $table->bigInteger('order_id')->unsigned();
            $table->string('entity_type', 50)->comment('次要实体类型（EntityTypes 白名单）');
            $table->string('entity_id', 64)->comment('次要实体 ID');
            $table->string('relation_type', 30)->comment('关系类型（OrderRelationTypes 白名单）');
            $table->decimal('share_amount', 10, 2)->nullable()->comment('分摊金额');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index(['tenant_id', 'order_id'], 'oer_order_idx');
            $table->index(['tenant_id', 'entity_type', 'entity_id'], 'oer_entity_idx');
        });

        // 兜底搬迁：orders.secondary_entity_* 非空行迁入关系表（当前空置，防历史脏数据）
        $generator = app(IdGeneratorContract::class);
        $now = now();
        $rows = DB::table('orders')
            ->whereNotNull('secondary_entity_id')
            ->whereNull('deleted_at')
            ->get(['tenant_id', 'order_id', 'secondary_entity_type', 'secondary_entity_id']);

        foreach ($rows as $row) {
            DB::table('order_entity_relations')->insert([
                'relation_id'   => $generator->generate(),
                'tenant_id'     => $row->tenant_id,
                'order_id'      => $row->order_id,
                'entity_type'   => $row->secondary_entity_type,
                'entity_id'     => $row->secondary_entity_id,
                'relation_type' => 'related',
                'created_at'    => $now,
                'updated_at'    => $now,
            ]);
        }

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_secondary_entity_idx');
            $table->dropColumn('secondary_entity_type');
            $table->dropColumn('secondary_entity_id');
        });

        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn('entity_type');
            $table->dropColumn('entity_id');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->string('entity_type', 50)->nullable()->comment('行级实体类型');
            $table->string('entity_id', 64)->nullable()->comment('行级实体 ID');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->string('secondary_entity_type', 50)->nullable()->after('entity_id')->comment('次要实体类型');
            $table->string('secondary_entity_id', 64)->nullable()->after('secondary_entity_type')->comment('次要实体 ID');
            $table->index(['tenant_id', 'secondary_entity_type', 'secondary_entity_id'], 'orders_secondary_entity_idx');
        });

        // 回填：relation_type='related' 的关系尽力回写 orders.secondary
        foreach (DB::table('order_entity_relations')->where('relation_type', 'related')->get() as $rel) {
            DB::table('orders')
                ->where('order_id', $rel->order_id)
                ->update([
                    'secondary_entity_type' => $rel->entity_type,
                    'secondary_entity_id'   => $rel->entity_id,
                ]);
        }

        Schema::dropIfExists('order_entity_relations');
    }
};
