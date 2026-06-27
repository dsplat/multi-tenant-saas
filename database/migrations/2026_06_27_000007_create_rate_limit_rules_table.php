<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('rate_limit_rules', function (Blueprint $table) {
            $table->id();
            $table->bigInteger('tenant_id')->unsigned()->nullable();
            $table->string('scope', 20)->default('user');
            $table->string('pattern', 200)->nullable();
            $table->unsignedInteger('max_attempts')->default(60);
            $table->unsignedInteger('decay_sec')->default(60);
            $table->string('strategy', 30)->default('fixed');
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'enabled']);
            $table->index(['scope', 'enabled']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rate_limit_rules');
    }
};
