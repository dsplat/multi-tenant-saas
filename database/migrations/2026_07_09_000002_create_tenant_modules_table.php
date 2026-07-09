<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_modules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('module_name', 50);
            $table->enum('status', ['enabled', 'disabled'])->default('enabled');
            $table->json('config')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamps();

            $table->unique(['tenant_id', 'module_name']);
            $table->index('tenant_id');
            $table->index('module_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_modules');
    }
};
