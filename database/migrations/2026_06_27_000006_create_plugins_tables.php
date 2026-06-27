<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plugins', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->string('name', 100);
            $table->string('version', 30)->nullable();
            $table->string('status', 20)->default('installed');
            $table->json('manifest')->nullable();
            $table->json('config')->nullable();
            $table->timestamp('installed_at')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->unique(['tenant_id', 'name']);
        });

        Schema::create('plugin_dependencies', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('plugin_id');
            $table->string('dependency_name', 200);
            $table->string('version_constraint', 100)->nullable();
            $table->timestamps();

            $table->foreign('plugin_id')->references('id')->on('plugins')->onDelete('cascade');
            $table->index('dependency_name');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('plugin_dependencies');
        Schema::dropIfExists('plugins');
    }
};
