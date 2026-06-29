<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 租户加密密钥表（TASK-028）
 *
 * 每个租户独立的 AES-256 加密密钥，密钥本身经系统主密钥加密后存储于 encrypted_key。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_keys', function (Blueprint $table) {
            $table->unsignedBigInteger('tenant_key_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->text('encrypted_key')->comment('经系统主密钥加密后的租户 AES 密钥');
            $table->string('key_type', 20)->default('system')->comment('system / byok');
            $table->string('status', 20)->default('active')->comment('active / rotating / retired');
            $table->unsignedBigInteger('previous_key_id')->nullable()->comment('轮换前的上一把密钥 ID');
            $table->timestamp('rotated_at')->nullable()->comment('轮换时间');
            $table->timestamps();
            $table->softDeletes();

            $table->index('tenant_id', 'tk_tenant_index');
            $table->index(['tenant_id', 'status'], 'tk_tenant_status_index');
            $table->index('previous_key_id', 'tk_previous_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_keys');
    }
};
