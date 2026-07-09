<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('modules', function (Blueprint $table) {
            $table->string('name', 50)->primary();
            $table->string('version', 20)->default('0.0.0');
            $table->enum('status', ['installed', 'enabled', 'disabled'])->default('installed');
            $table->json('config')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('modules');
    }
};
