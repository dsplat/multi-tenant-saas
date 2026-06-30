<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trusted_devices', function (Blueprint $table) {
            $table->unsignedBigInteger('trusted_device_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable(); // 审计引用，不参与租户隔离
            $table->bigInteger('user_id')->unsigned();
            $table->string('device_fingerprint', 64); // SHA256(IP + User-Agent)
            $table->string('device_name', 200)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 500)->nullable();
            $table->timestamp('expires_at')->nullable(); // 信任到期时间
            $table->timestamp('last_used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'device_fingerprint']);
            $table->index(['user_id', 'expires_at']);
            $table->unique(['user_id', 'device_fingerprint'], 'uniq_user_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trusted_devices');
    }
};
