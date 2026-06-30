<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sandbox_environments', function (Blueprint $table) {
            $table->unsignedBigInteger('sandbox_environment_id')->primary();
            $table->unsignedBigInteger('developer_id')->index(); // 开发者（用户）ID
            $table->unsignedBigInteger('sandbox_tenant_id')->index(); // 沙箱隔离租户 ID
            $table->string('api_key', 128)->unique(); // 沙箱测试 API Key
            $table->string('status', 20)->default('active'); // active / expired / cleaned
            $table->timestamp('expires_at')->nullable(); // 过期时间（24 小时 TTL）
            $table->timestamps();

            $table->index(['developer_id', 'status']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sandbox_environments');
    }
};
