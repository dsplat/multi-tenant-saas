<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_session_id')->primary();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->bigInteger('user_id')->unsigned();
            $table->unsignedBigInteger('token_id')->nullable(); // personal_access_tokens.id
            $table->string('session_id', 100)->nullable(); // 会话标识
            $table->string('ip_address', 45)->nullable();
            $table->string('device_info', 500)->nullable(); // User-Agent
            $table->string('device_fingerprint', 64)->nullable(); // 指纹 hash
            $table->timestamp('login_at')->nullable();
            $table->timestamp('last_active_at')->nullable();
            $table->string('location', 255)->nullable();
            $table->boolean('is_anomalous')->default(false);
            $table->timestamps();

            $table->index(['tenant_id', 'user_id']);
            $table->index(['user_id', 'last_active_at']);
            $table->index('token_id');
            $table->index('device_fingerprint');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_sessions');
    }
};
