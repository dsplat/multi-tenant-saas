<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 下游项目（如 scrm-platform）可能已自建同名表，存在则跳过
        if (Schema::hasTable('external_kb_connections')) {
            return;
        }

        Schema::create('external_kb_connections', function (Blueprint $table) {
            $table->unsignedBigInteger('connection_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('provider_type', 30);
            $table->string('name', 100);
            $table->string('api_url', 500);
            $table->string('api_key_encrypted', 500)->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamp('last_synced_at')->nullable();
            $table->json('config')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['tenant_id', 'provider_type']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_kb_connections');
    }
};
