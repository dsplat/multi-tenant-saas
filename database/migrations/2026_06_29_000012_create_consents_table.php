<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 用户同意记录表（GDPR 合规）
 *
 * 记录用户对 Cookie、数据处理、营销、条款等的同意状态。
 * 具有法律效力，需记录 IP 和时间戳。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consents', function (Blueprint $table) {
            $table->unsignedBigInteger('consent_id')->primary();
            $table->unsignedBigInteger('tenant_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type', 50);
            $table->string('version', 50)->default('1.0');
            $table->boolean('is_granted')->default(false);
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('granted_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'type']);
            $table->index(['tenant_id', 'type']);
            $table->index(['is_granted', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consents');
    }
};
