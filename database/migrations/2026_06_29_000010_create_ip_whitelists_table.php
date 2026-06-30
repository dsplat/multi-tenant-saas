<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ip_whitelists', function (Blueprint $table) {
            $table->unsignedBigInteger('ip_whitelist_id')->primary();
            $table->unsignedBigInteger('tenant_id');
            $table->string('ip_value', 100); // 单个 IP / CIDR / IP 范围（起-止）
            $table->string('description', 255)->nullable();
            $table->string('scope', 20)->default('all'); // all / api / admin
            $table->boolean('is_enabled')->default(true);
            $table->timestamps();

            $table->index('tenant_id');
            $table->index(['tenant_id', 'is_enabled']);
            $table->index(['tenant_id', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_whitelists');
    }
};
